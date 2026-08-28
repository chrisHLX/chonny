<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellRelationship;
use App\Models\Specialization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Single-match playstyle analysis for one real player, assembled from four sources that were
 * previously only usable one at a time:
 *   - the archived match metadata (roster, spec, duration)
 *   - COMBATANT_INFO in the raw log -> the exact talent + PvP-talent build
 *     (via ArenaLogService::extractCombatantInfo()/resolveCombatantTalents(), which already
 *     handle the COMBATANT_INFO entryId <-> our external_talent_id mismatch and CHOICE-node
 *     disambiguation — see those methods' docblocks)
 *   - the raw combat log filtered to that player -> what they actually pressed / procced / kept up
 *   - the app DB (spells, spell_relationships) -> what each talent *does*
 *
 * The analytical core is linkTalents(): every selected talent is tied to its in-match evidence
 * and given a verdict — used / unused / dead-modifier / no-proc-seen / passive — which is what
 * answers "did this player actually take advantage of this talent, or is it a wasted pick?".
 *
 * Read-only. Reads the archive + the DB, writes nothing. Backs `wow:analyze-player`.
 */
class PlayerMatchAnalysisService
{
    /** Relationship types that mean "this talent changes how another spell behaves". */
    private const MODIFIER_RELATIONSHIPS = ['modifies', 'modifies_cooldown', 'modifies_charges', 'replaces'];

    public function __construct(private ArenaLogService $arena) {}

    /**
     * @return array<string, mixed> the analysis, or ['error' => string] on a resolvable failure
     */
    public function analyze(string $matchId, string $playerRef): array
    {
        $metaPath = $this->arena->metadataPath($matchId);
        $rawPath = $this->arena->rawLogPath($matchId);

        if (! File::exists($metaPath) || ! File::exists($rawPath)) {
            return ['error' => "Match {$matchId} is not on file (need both raw/{$matchId}.log.gz and metadata/{$matchId}.json)."];
        }

        $meta = json_decode(File::get($metaPath), true) ?: [];
        $raw = gzdecode(File::get($rawPath));

        $player = $this->resolvePlayer($meta, $playerRef);

        if (! $player) {
            $names = collect($meta['units'] ?? [])
                ->filter(fn ($u) => str_starts_with((string) ($u['id'] ?? ''), 'Player-'))
                ->pluck('name')->implode(', ');

            return ['error' => "No real player matching \"{$playerRef}\" in {$matchId}. Players in this match: {$names}"];
        }

        $patchId = Patch::where('is_current', true)->value('id');
        $spec = Specialization::with('gameClass')->where('external_spec_id', $player['specExternalId'])->first();

        $ci = $this->arena->extractCombatantInfo($matchId, $player['guid']);
        $resolved = ($ci && $spec)
            ? $this->arena->resolveCombatantTalents($ci, $spec->id)
            : ['talents' => [], 'pvpTalents' => []];

        $rawNodeCount = $ci ? collect($ci['talents'])->pluck('nodeId')->unique()->count() : 0;

        $log = $this->parseLog($raw, $player['guid']);
        $talentAnalysis = $this->linkTalents($resolved['talents'], $resolved['pvpTalents'], $log, $patchId);
        $buffWeb = $this->buffWeb($log, $resolved['talents'], $patchId);

        return [
            'match' => [
                'id' => $matchId,
                'durationSec' => $meta['durationInSeconds'] ?? $log['endT'],
                'result' => $meta['result'] ?? null,
                'player' => $player['name'],
                'spec' => $spec ? "{$spec->gameClass?->name} / {$spec->name}" : "spec {$player['specExternalId']}",
                'roster' => $this->rosterFor($meta, $player),
            ],
            'build' => [
                'talents' => $resolved['talents'],
                'pvpTalents' => $resolved['pvpTalents'],
                'nodesInLog' => $rawNodeCount,
                'nodesResolved' => count($resolved['talents']),
            ],
            'usage' => [
                'casts' => $log['casts'],
                'castFailed' => $log['castFailed'],
                'interrupts' => $log['interrupts'],
                'selfBuffs' => $log['selfBuffs'],
            ],
            'talentAnalysis' => $talentAnalysis,
            'buffWeb' => $buffWeb,
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Player + roster resolution
     * ------------------------------------------------------------------ */

    private function resolvePlayer(array $meta, string $ref): ?array
    {
        $ref = trim($ref);
        $units = collect($meta['units'] ?? [])->filter(fn ($u) => str_starts_with((string) ($u['id'] ?? ''), 'Player-'));

        $hit = $units->first(function ($u) use ($ref) {
            if (strcasecmp((string) $u['id'], $ref) === 0) {
                return true;
            }
            $name = (string) ($u['name'] ?? '');

            return strcasecmp($name, $ref) === 0
                || strcasecmp(explode('-', $name)[0], $ref) === 0;
        });

        return $hit ? [
            'guid' => $hit['id'],
            'name' => $hit['name'],
            'reaction' => $hit['reaction'] ?? null,
            'specExternalId' => (int) ($hit['spec'] ?? 0),
        ] : null;
    }

    private function rosterFor(array $meta, array $player): array
    {
        $players = collect($meta['units'] ?? [])
            ->filter(fn ($u) => str_starts_with((string) ($u['id'] ?? ''), 'Player-'))
            ->unique('id');

        $specs = Specialization::with('gameClass')->get()->keyBy('external_spec_id');

        $label = fn ($u) => ($s = $specs->get((int) ($u['spec'] ?? 0)))
            ? "{$s->gameClass?->name} / {$s->name}"
            : "spec {$u['spec']}";

        return [
            'allies' => $players->where('reaction', $player['reaction'])->reject(fn ($u) => $u['id'] === $player['guid'])
                ->map($label)->values()->all(),
            'enemies' => $players->where('reaction', '!=', $player['reaction'])->map($label)->values()->all(),
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Raw-log parsing (one player)
     * ------------------------------------------------------------------ */

    /**
     * @return array{casts: array, castFailed: array, interrupts: array, selfBuffs: array, endT: float}
     */
    private function parseLog(string $raw, string $guid): array
    {
        $casts = [];
        $castFailed = [];
        $interrupts = [];
        $selfBuffs = [];
        $seenNames = [];   // lowercased name of every spell this player was ever the SOURCE of
        $open = [];        // spellId => stack of start-times for currently-applied self-auras
        $t0 = null;
        $tLast = 0.0;

        foreach (explode("\n", $raw) as $line) {
            $sp = strpos($line, '  ');
            if ($sp === false) {
                continue;
            }

            $rest = ltrim(substr($line, $sp));
            if (! str_contains($rest, $guid) || ! str_contains($rest, ',')) {
                continue;
            }

            $f = str_getcsv($rest);
            $event = $f[0] ?? '';
            if (! str_starts_with($event, 'SPELL_')) {
                continue;
            }

            $t = $this->ts(substr($line, 0, $sp));
            $t0 ??= $t;
            $rel = round($t - $t0, 2);
            $tLast = $rel;

            $isSource = ($f[1] ?? '') === $guid;
            $isDest = ($f[5] ?? '') === $guid;
            $spellId = (int) ($f[9] ?? 0);
            $spellName = $f[10] ?? '';

            if ($isSource && $spellName !== '' && $spellName !== 'nil') {
                $seenNames[mb_strtolower($spellName)] = true;
            }

            switch ($event) {
                case 'SPELL_CAST_SUCCESS':
                    if (! $isSource || $spellId === 0) {
                        break;
                    }
                    $casts[$spellId] ??= ['spellId' => $spellId, 'name' => $spellName, 'count' => 0, 'firstT' => $rel, 'lastT' => $rel];
                    $casts[$spellId]['count']++;
                    $casts[$spellId]['lastT'] = $rel;
                    break;

                case 'SPELL_CAST_FAILED':
                    if (! $isSource || $spellId === 0) {
                        break;
                    }
                    $reason = trim((string) end($f), '"');
                    $castFailed[$spellId] ??= ['spellId' => $spellId, 'name' => $spellName, 'count' => 0, 'reasons' => []];
                    $castFailed[$spellId]['count']++;
                    $castFailed[$spellId]['reasons'][$reason] = ($castFailed[$spellId]['reasons'][$reason] ?? 0) + 1;
                    break;

                case 'SPELL_INTERRUPT':
                    if (! $isSource) {
                        break;
                    }
                    $n = count($f);
                    $exId = (int) ($f[$n - 3] ?? 0);
                    $exName = trim((string) ($f[$n - 2] ?? ''), '"');
                    $interrupts[$exId] ??= ['interruptedSpellId' => $exId, 'interruptedName' => $exName, 'count' => 0];
                    $interrupts[$exId]['count']++;
                    break;

                case 'SPELL_AURA_APPLIED':
                case 'SPELL_AURA_APPLIED_DOSE':
                case 'SPELL_AURA_REFRESH':
                case 'SPELL_AURA_REMOVED':
                case 'SPELL_AURA_REMOVED_DOSE':
                    if (! ($isSource && $isDest) || $spellId === 0) {
                        break;
                    }
                    $selfBuffs[$spellId] ??= ['spellId' => $spellId, 'name' => $spellName, 'applies' => 0, 'uptime' => 0.0, 'maxStack' => 1];
                    $b = &$selfBuffs[$spellId];

                    if ($event === 'SPELL_AURA_APPLIED') {
                        $b['applies']++;
                        $open[$spellId][] = $rel;
                    } elseif ($event === 'SPELL_AURA_REMOVED') {
                        if (! empty($open[$spellId])) {
                            $start = array_pop($open[$spellId]);
                            $b['uptime'] += max(0.0, $rel - $start);
                        }
                    } elseif (str_ends_with($event, '_DOSE')) {
                        $stack = $this->doseAmount($f);
                        if ($stack > $b['maxStack']) {
                            $b['maxStack'] = $stack;
                        }
                    }
                    unset($b);
                    break;
            }
        }

        // Close auras still open at the last observed event.
        foreach ($open as $spellId => $starts) {
            foreach ($starts as $start) {
                $selfBuffs[$spellId]['uptime'] += max(0.0, $tLast - $start);
            }
        }

        return [
            'casts' => collect($casts)->sortByDesc('count')->values()->all(),
            'castFailed' => collect($castFailed)->sortByDesc('count')->values()->all(),
            'interrupts' => collect($interrupts)->sortByDesc('count')->values()->all(),
            'selfBuffs' => collect($selfBuffs)->map(fn ($b) => [...$b, 'uptime' => round($b['uptime'], 1)])
                ->sortByDesc('uptime')->values()->all(),
            'seenNames' => $seenNames,
            'endT' => $tLast,
        ];
    }

    private function ts(string $stamp): float
    {
        if (preg_match('/(\d+):(\d+):(\d+)\.(\d+)/', $stamp, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3] + ((int) $m[4]) / 1000;
        }

        return 0.0;
    }

    /** Stack count on a *_DOSE aura line — the numeric field right after BUFF/DEBUFF. */
    private function doseAmount(array $f): int
    {
        foreach ($f as $i => $v) {
            if (($v === 'BUFF' || $v === 'DEBUFF') && isset($f[$i + 1]) && is_numeric($f[$i + 1])) {
                return (int) $f[$i + 1];
            }
        }

        return (int) (end($f) ?: 1);
    }

    /* ------------------------------------------------------------------ *
     *  Talent  <->  usage linkage  (the analytical core)
     * ------------------------------------------------------------------ */

    /**
     * @param  array<int, array{name:string, spellId:int, rank:int, treeType:?string}>  $talents
     * @param  array<int, array{name:string, spellId:int}>  $pvpTalents
     * @param  array{casts:array, selfBuffs:array}  $log
     * @return array<int, array<string, mixed>>
     */
    private function linkTalents(array $talents, array $pvpTalents, array $log, ?int $patchId): array
    {
        // WoW has many internal spell_ids per display name (Windstrike, Doom Winds' pulse, …),
        // so the id a talent/relationship carries rarely equals the id the combat log emits for
        // "the same" ability. Every match below is therefore name-first.
        $castByName = collect($log['casts'])->keyBy(fn ($c) => mb_strtolower($c['name']));
        $buffByName = collect($log['selfBuffs'])->keyBy(fn ($b) => mb_strtolower($b['name']));
        $seen = $log['seenNames'];   // every spell name the player was the source of (cast/damage/energize/aura)

        $picks = collect($talents)->map(fn ($t) => [...$t, 'source' => $t['treeType'] ?? 'talent'])
            ->merge(collect($pvpTalents)->map(fn ($p) => [
                'name' => $p['name'], 'spellId' => $p['spellId'], 'rank' => 1, 'source' => 'pvp',
            ]));

        $spellRows = Spell::where('patch_id', $patchId)
            ->whereIn('spell_id', $picks->pluck('spellId')->unique()->filter()->values())
            ->get()->keyBy('spell_id');

        $rels = SpellRelationship::whereIn('source_spell_id', $spellRows->pluck('id')->all())
            ->whereIn('relationship_type', self::MODIFIER_RELATIONSHIPS)
            ->with('targetSpell:id,spell_id,name')
            ->get()->groupBy('source_spell_id');

        return $picks->map(function ($t) use ($castByName, $buffByName, $seen, $spellRows, $rels, $patchId) {
            $spell = $spellRows->get($t['spellId']);
            $name = mb_strtolower($t['name']);
            $isPassive = $spell?->is_passive ?? false;
            $castCount = $castByName->get($name)['count'] ?? 0;
            $ownBuff = $buffByName->get($name);

            // 1. Identity — the talent IS an active ability.
            if (! $isPassive && ($castCount > 0 || $spell?->cooldown_seconds !== null || $spell?->charges !== null)) {
                return $this->pick($t, 'active-ability', ['castCount' => $castCount],
                    $castCount > 0 ? "used ({$castCount}x)" : 'UNUSED — active ability, never pressed');
            }

            // 2. Its own buff/proc carries the payload (Maelstrom Weapon, Hot Hand, Flurry …).
            if ($ownBuff && ($ownBuff['uptime'] >= 3.0 || $ownBuff['applies'] >= 2)) {
                return $this->pick($t, 'buff', [
                    'applies' => $ownBuff['applies'], 'maxStack' => $ownBuff['maxStack'], 'uptime' => $ownBuff['uptime'],
                ], "active — {$ownBuff['applies']}x apply, max {$ownBuff['maxStack']} stk");
            }

            // 3. Modifier — the talent changes another spell's behaviour. Name-matched against
            //    every spell the player actually produced (not just SPELL_CAST_SUCCESS — auto-
            //    attack procs like Windfury Attack never cast but fire constantly).
            $mods = ($spell ? ($rels->get($spell->id) ?? collect()) : collect())
                ->map(fn ($r) => ['target' => $r->targetSpell?->name, 'type' => $r->relationship_type])
                ->filter(fn ($m) => $m['target'] && mb_strtolower($m['target']) !== $name)
                ->unique('target')
                ->map(fn ($m) => [...$m, 'seen' => isset($seen[mb_strtolower($m['target'])])])
                ->values();

            // A talent that touches many spells is a broad, always-on damage/healing/utility
            // amp — enumerating 30 internal spell names is noise, and it behaves like a passive.
            if ($mods->count() > 6) {
                $seenCount = $mods->where('seen', true)->count();

                return $this->pick($t, 'passive', ['modifies' => $mods->count(), 'seen' => $seenCount],
                    "passive — broad modifier ({$seenCount}/{$mods->count()} affected spells seen)");
            }

            if ($mods->isNotEmpty()) {
                $live = $mods->contains('seen', true);
                $liveNames = $mods->where('seen', true)->pluck('target')->implode(', ');

                return $this->pick($t, 'modifier', ['modifies' => $mods->all()],
                    $live ? "active — modifies {$liveNames}" : 'DEAD MODIFIER — modified spell(s) never seen');
            }

            // 4. Proc — the talent's own text points at a buff/spell that should show up.
            $refs = $spell ? $this->descriptionSpellRefs($spell, $patchId) : collect();
            $refHits = $refs->map(fn ($r) => [
                'name' => $r['name'],
                'fired' => ($buffByName->get(mb_strtolower($r['name']))['applies'] ?? 0)
                    ?: ($castByName->get(mb_strtolower($r['name']))['count'] ?? 0)
                    ?: (isset($seen[mb_strtolower($r['name'])]) ? 1 : 0),
            ]);

            if ($refHits->isNotEmpty()) {
                $fired = $refHits->contains(fn ($r) => $r['fired'] > 0);

                return $this->pick($t, 'proc', ['refs' => $refHits->all()],
                    $fired ? 'procs — referenced effect seen' : 'NO PROC SEEN — referenced effect never fired');
            }

            // 5. Everything else. A non-passive spell with a cast type but zero evidence is a
            //    castable option that simply wasn't used this match.
            if (! $isPassive && $spell?->cast_type !== null) {
                return $this->pick($t, 'active-ability', ['castCount' => 0], 'UNUSED — castable, never used');
            }

            return $this->pick($t, $isPassive ? 'passive' : 'unknown', [],
                $isPassive ? 'passive (always on)' : 'no measurable in-match signal');
        })->all();
    }

    private function pick(array $t, string $linkType, array $evidence, string $verdict): array
    {
        return [
            'talent' => $t['name'],
            'source' => $t['source'] ?? 'talent',
            'rank' => $t['rank'] ?? 1,
            'spellId' => $t['spellId'],
            'linkType' => $linkType,
            'verdict' => $verdict,
            'evidence' => $evidence,
        ];
    }

    /**
     * Spell references embedded in a talent's own description / variables text —
     * `$@spellname123`, `$@spelldesc123`, and bare `$123456` tokens — resolved to real
     * same-patch spells. This is how a proc talent ("upgrade your next Lightning Bolt to
     * Tempest") gets tied to the buff it should produce, since spell_relationships only
     * carries *structural* modifier links, never trigger/grant ones.
     *
     * @return Collection<int, array{spellId:int, name:string}>
     */
    private function descriptionSpellRefs(Spell $spell, ?int $patchId): Collection
    {
        $text = (string) $spell->description.' '.(string) $spell->variables;
        preg_match_all('/\$@spell(?:name|desc|tooltip|icon)?(\d+)/i', $text, $a);
        preg_match_all('/\$(\d{5,7})\b/', $text, $b);

        $ids = collect(array_merge($a[1] ?? [], $b[1] ?? []))
            ->map(fn ($x) => (int) $x)->unique()
            ->reject(fn ($id) => $id === $spell->spell_id);

        if ($ids->isEmpty()) {
            return collect();
        }

        return Spell::where('patch_id', $patchId)->whereIn('spell_id', $ids)
            ->get(['spell_id', 'name'])->unique('name')
            ->map(fn ($s) => ['spellId' => $s->spell_id, 'name' => $s->name])
            ->values();
    }

    /* ------------------------------------------------------------------ *
     *  Buff / proc web
     * ------------------------------------------------------------------ */

    /**
     * For the player's most-present self-buffs: uptime, apply count, max stack, and which of
     * their *selected* talents feed it (talent description names the buff, or structurally
     * modifies it).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buffWeb(array $log, array $talents, ?int $patchId): array
    {
        $end = max($log['endT'], 1);
        $top = collect($log['selfBuffs'])
            ->filter(fn ($b) => $b['uptime'] >= 1.0 || $b['applies'] >= 3)
            ->take(10);

        if ($top->isEmpty()) {
            return [];
        }

        $talentSpells = Spell::where('patch_id', $patchId)
            ->whereIn('spell_id', collect($talents)->pluck('spellId')->unique())
            ->get(['id', 'spell_id', 'name', 'description']);

        return $top->map(function ($b) use ($end, $talentSpells) {
            $needle = mb_strtolower($b['name']);

            $feeders = $talentSpells
                ->filter(fn ($s) => str_contains(mb_strtolower((string) $s->description), $needle))
                ->pluck('name')->values()->all();

            return [
                'buff' => $b['name'],
                'uptimePct' => (int) round($b['uptime'] / $end * 100),
                'applies' => $b['applies'],
                'maxStack' => $b['maxStack'],
                'feedingTalents' => $feeders,
            ];
        })->values()->all();
    }
}
