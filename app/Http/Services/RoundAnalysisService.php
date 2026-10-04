<?php

namespace App\Http\Services;

/**
 * One arena round, measured the way match-review-operations.md defines, for any player.
 *
 * Run at upload, while the raw log is still in hand: an uploaded round's text is discarded straight
 * after it is derived, so anything not measured HERE can never be recovered without the player
 * uploading the game again. What it returns is stored in `arena_rounds.payload['analysis']` and
 * combined across games by MatchAnalysisService.
 *
 * THIS IS THE PRODUCT DEFINITION of the measures `tools/match-review/killread.php` was built to
 * explore (2026-09-28): the same go, drain, kill read, peak burst, timing, overcommitment, overlap
 * and kick measures, with the definitions that research settled. The research script can keep
 * changing; this is what a player's page is built from, so a change here is a change to every
 * player's analysis and needs a test.
 *
 * "US" IS THE LOGGING PLAYER'S SIDE: `reaction` as the log's writer saw it, never an arena team id
 * (CLAUDE.md rule 12). So it works for whoever uploads, with no names anywhere in the code.
 */
class RoundAnalysisService
{
    /**
     * Bumped when a measure's definition or the stored shape changes, so stored analyses can be told
     * apart. 2 (2026-09-29): each go keeps its links, the defensives it forced and its burst as
     * structured rows (who, what, when, on whom), so a go can be drawn with icons like a guide.
     * 3 (2026-09-29): who applied each overlapping defensive, who spent each defensive outside the
     * enemy's goes, and whose CC was on their healer during each burst — so a loss's mistakes can
     * be owned by the player whose button it was ("Where the losses came from").
     * 4 (2026-09-30): the Garrote bleed is no longer read as a silence (see
     * ArenaMomentService::AURA_IS_NOT_THE_CONTROL). Every lockout figure in a game against a Rogue
     * who pressed Garrote was too high before this: an analysis stored at 3 or lower from such a
     * game overstates it and can call a healer locked out at a death when they were not.
     * 5 (2026-10-02): `breakdown` (each player's damage, healing and absorbs by ability, and time
     * not pressing anything) and `checks` (offensive cooldowns left ready, defensives put on an
     * immune teammate). Older analyses simply lack both; nothing else changed.
     * 6 (2026-10-03): `dispels` (every friendly dispel of a debuff: who took what off whom),
     * `debuffs` (each player's debuffs from the other side, merged per name, so the chance to
     * dispel can be measured against what any dispel was seen removing), and `cover` on every go
     * (the defending side's damage defensives back as it started, cooldowns resolved from each
     * player's own talents: CooldownLedgerService). Older analyses lack all three.
     * 7 (2026-10-03): each of our deaths carries `answers`, the answer sheet for the go that killed
     * (withAnswers()), and each defensive row `beforeLockout`.
     * 8 (2026-10-04): spells used both ways are classified per press (classifyContextual()), the
     * timeline takes defensive-tagged cooldowns down to 15s (ArenaMomentService), and the
     * classification gained Alter Time, Intervene and Leap of Faith as defensives and five main
     * offensive cooldowns, and lost eleven heals on a rotation. Every defensive count, overlap and
     * go can differ from version 7.
     */
    public const VERSION = 8;

    /** A defensive pressed this long or less before its owner was locked out went in before the chance was lost. */
    private const BEFORE_LOCKOUT = 4.0;

    /** Crowd control that takes a player out: a slow or root does not stop a healer healing. */
    private const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];

    /** A link within this long of the chain's end makes a good go. */
    private const TIGHT_LINK = 5.0;

    /** A link this far still joins, but only through an offensive cooldown: a bad go. */
    private const LOOSE_LINK = 10.0;

    /** An offensive cast counts as "ending" this long after it is pressed, for linking. */
    private const OFFENSIVE_HOLD = 5.0;

    /** A go's window runs this long past its last offensive cooldown. */
    private const GO_TAIL = 15.0;

    /** A go "converts" if a kill lands in its window or this long after it. */
    private const KILL_LATER = 30.0;

    /** The burst window: the 6s of a go with the most damage from its DPS. */
    private const PEAK = 6.0;

    private const DAMAGE_EVENTS = ['SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'RANGE_DAMAGE', 'SWING_DAMAGE_LANDED'];

    /** A gap between two casts longer than this counts as not pressing anything (specread.php's GAP). */
    private const IDLE_GAP = 2.5;

    /**
     * Full immunities: a damage reduction or absorb put on top of one of these removes nothing.
     * Blessing of Protection (physical) and Cloak of Shadows (magic) are partial, so they are not here.
     */
    private const IMMUNITIES = ['Ice Block', 'Divine Shield', 'Aspect of the Turtle', 'Netherwalk'];

    public function __construct(private ArenaMomentService $moments, private ArenaLogService $arena, private CooldownLedgerService $ledger) {}

    /**
     * @param  array<int, string>  $lines  one round's raw log lines
     * @param  array  $metadata  that round's derived metadata
     */
    public function analyse(array $lines, array $metadata): ?array
    {
        $roster = $this->moments->roster($metadata);

        if (count($roster) < 2) {
            return null;
        }

        // The logging player: affiliation 1 in the metadata. Their `reaction` is our side.
        $logger = collect($metadata['units'] ?? [])->first(fn ($u) => (int) ($u['affiliation'] ?? 0) === 1 && isset($roster[$u['id']]));
        $us = $logger ? $roster[$logger['id']]['side'] : 1;
        $sideOf = fn (?string $guid) => $guid !== null && isset($roster[$guid]) ? ($roster[$guid]['side'] === $us ? 'us' : 'them') : null;

        $tl = $this->moments->readTimeline($lines, $roster);
        $ccMap = $this->moments->crowdControl();

        foreach ($tl['commitments'] as &$c) {
            $c['cat'] = in_array($c['kind'], ['offensive', 'mixed', 'defensive'], true) ? $c['kind']
                : (isset($ccMap[$c['spellId']]) ? 'control' : 'utility');
        }
        unset($c);

        [$dmg, $owner, $buffs, $interrupts, $end, $heals, $absorbs, $casts, $swingOnly, $dispelEvents, $debuffSpans, $named, $failed] = $this->secondPass($lines, $roster);
        $this->classifyContextual($tl, $dmg, $sideOf);
        $credit = function (string $src) use ($roster, $owner) {
            for ($i = 0; $i < 3 && ! isset($roster[$src]) && isset($owner[$src]); $i++) {
                $src = $owner[$src];
            }

            return $src;
        };

        $healers = [];
        foreach ($roster as $g => $r) {
            if ($r['healer']) {
                $healers[$sideOf($g)] = $g;
            }
        }

        // Lockout per player, merged so a stun under a silence counts once.
        $locked = [];
        foreach ($roster as $g => $r) {
            $locked[$g] = $this->union(array_map(fn ($c) => [$c['from'], $c['to']],
                array_filter($tl['control'], fn ($c) => $c['on'] === $g && in_array($c['dr'], self::LOCKOUT, true))));
        }

        $deaths = array_values(array_map(fn ($d) => $d + ['side' => $sideOf($d['who'])], $tl['deaths']));
        $firstDeath = $deaths[0]['t'] ?? INF;
        $goes = $this->goes($tl, $roster, $sideOf, $credit, $healers, $dmg);

        $goRows = [];
        foreach ($goes as $side => $list) {
            $other = $side === 'us' ? 'them' : 'us';
            foreach ($list as $go) {
                $goRows[] = $this->describeGo($go, $side, $other, $tl, $deaths, $locked, $healers, $roster, $sideOf, $credit, $dmg, $interrupts, $goes);
            }
        }

        // The defending side's damage defensives back as each go started (the cooldown ledger).
        $cover = $this->cover($lines, $metadata, $roster, $sideOf, $tl);
        foreach ($goRows as &$row) {
            $row['cover'] = $cover ? $cover($row['side'] === 'us' ? 'them' : 'us', (float) $row['from']) : null;
        }
        unset($row);

        return [
            'version' => self::VERSION,
            'won' => (int) ($metadata['result'] ?? 0) === 3,
            'mmr' => $this->mmr($end, $metadata),
            'players' => $this->players($metadata, $roster, $sideOf, $logger['id'] ?? null),
            'goes' => $goRows,
            'deaths' => $this->withAnswers(
                array_map(fn ($d) => $this->killRead($d, $tl, $dmg, $locked, $healers, $roster, $sideOf, $credit, $goes), $deaths),
                $lines, $metadata, $sideOf, $credit, $named, $failed, $locked, $goes,
            ),
            'defensives' => $this->defensives($tl, $sideOf, $goes, $firstDeath, $roster, $locked),
            'overlaps' => $this->overlaps($tl, $buffs, $sideOf, $firstDeath),
            'kicks' => $this->kicks($interrupts, $sideOf, $credit, $healers, $goes),
            'lockout' => array_map(fn ($iv) => round($this->len($iv), 1), $locked),
            'breakdown' => $this->breakdown($roster, $sideOf, $credit, array_merge($dmg, $swingOnly), $heals, $absorbs, $casts, $locked, $deaths, $metadata),
            'checks' => $this->checks($tl, $roster, $sideOf, $deaths, $metadata, $this->overlaps($tl, $buffs, $sideOf, $firstDeath)),
            'dispels' => $this->dispels($dispelEvents, $sideOf, $credit),
            'debuffs' => $this->debuffs($debuffSpans, $sideOf, $credit),
        ];
    }

    // ------------------------------------------------------------------ spells used both ways

    /** @var array<string, true>|null names read per press, from contextual-cooldowns.json */
    private ?array $contextual = null;

    /**
     * Spells used both ways (Vanish, Mass Invisibility, Master's Call...) are classified per press,
     * not per spell (version 8). A single tag is wrong for half their presses: the tag audit
     * (tools/match-review/tagaudit.php, 2026-10-04) found each going out defensively in about half
     * and offensively in about a third.
     *
     * A press is DEFENSIVE when its presser was in danger (35% health or lower, or lost 25% in the
     * 3s before) or the other team was in a go (an offensive cooldown of theirs in the 10s before)
     * and their own was not (none of their team's within 5s). Otherwise it is UTILITY, and counts
     * as neither a defensive nor the start of a go. The list is data, not code:
     * data/arena-logs/spell-classification/contextual-cooldowns.json.
     */
    private function classifyContextual(array &$tl, array $dmg, callable $sideOf): void
    {
        $this->contextual ??= array_fill_keys(array_column(
            json_decode((string) @file_get_contents(base_path('data/arena-logs/spell-classification/contextual-cooldowns.json')), true) ?: [], 'name'), true);
        if (! $this->contextual) {
            return;
        }

        $hp = [];
        foreach ($dmg as $x) {
            if ($x['hpAfter'] !== null) {
                $hp[$x['dst']][] = [$x['t'], $x['hpAfter']];
            }
        }
        $hpAt = function (string $who, float $t) use ($hp) {
            $last = null;
            foreach ($hp[$who] ?? [] as [$x, $p]) {
                if ($x > $t) {
                    break;
                }
                $last = $p;
            }

            return $last;
        };
        $offensive = [];
        foreach ($tl['commitments'] as $c) {
            if (in_array($c['cat'], ['offensive', 'mixed'], true) && ! isset($this->contextual[$c['spell']])) {
                $offensive[$sideOf($c['who'])][] = (float) $c['t'];
            }
        }

        foreach ($tl['commitments'] as &$c) {
            if (! isset($this->contextual[$c['spell']])) {
                continue;
            }
            $t = (float) $c['t'];
            $side = $sideOf($c['who']);
            $now = $hpAt($c['who'], $t);
            $before = $hpAt($c['who'], $t - 3);
            $danger = $now !== null && ($now <= 35 || ($before !== null && $before - $now >= 25));
            $theirGo = collect($offensive[$side === 'us' ? 'them' : 'us'] ?? [])->contains(fn ($x) => $x <= $t && $x >= $t - 10);
            $ourGo = collect($offensive[$side] ?? [])->contains(fn ($x) => abs($x - $t) <= 5);
            $c['cat'] = $danger || ($theirGo && ! $ourGo) ? 'defensive' : 'utility';
            $c['contextual'] = true;
        }
        unset($c);
    }

    // ------------------------------------------------------------------ dispels and the ledger

    /**
     * Every dispel that took a debuff off a teammate (or yourself). Enemy-side dispels of buffs
     * (Mass Dispel, Purge) are a different job and are not kept.
     *
     * @return array<int, array{t: float, by: string, on: string, spell: string, removed: string}>
     */
    private function dispels(array $events, callable $sideOf, callable $credit): array
    {
        $out = [];
        foreach ($events as $e) {
            $by = $credit($e['src']);
            if ($e['type'] !== 'DEBUFF' || $sideOf($by) === null || $sideOf($by) !== $sideOf($e['dst'])) {
                continue;
            }
            $out[] = ['t' => $e['t'], 'by' => $by, 'on' => $e['dst'], 'spell' => $e['spell'], 'removed' => $e['removed']];
        }

        return $out;
    }

    /**
     * Each player's debuffs from the other side, merged per debuff name. What could have been
     * dispelled is decided later, against what any dispel was SEEN removing across stored games:
     * the spell data has no dispel type.
     *
     * @return array<string, array<int, array{0: string, 1: float, 2: float}>> guid => [name, from, to]
     */
    private function debuffs(array $spans, callable $sideOf, callable $credit): array
    {
        $byName = [];
        foreach ($spans as $s) {
            $src = $sideOf($credit($s['src']));
            if ($src === null || $src === $sideOf($s['dst'])) {
                continue;
            }
            $byName[$s['dst']][$s['name']][] = [$s['from'], $s['to']];
        }

        $out = [];
        foreach ($byName as $guid => $names) {
            foreach ($names as $name => $iv) {
                foreach ($this->union($iv) as [$from, $to]) {
                    $out[$guid][] = [$name, round($from, 1), round($to, 1)];
                }
            }
        }

        return $out;
    }

    /**
     * The answer sheet for each of our deaths (version 7): every button our team had for the go that
     * killed, and what happened to it between the go starting and the death: pressed in the go,
     * ready and never pressed, or already on cooldown when it began. Plus each player's lockout in
     * that stretch, and the free moment before their longest lockout: the last chance to press
     * something before losing the chance (3 Oct, 11:50: the healer was free 14.1-15.8s, then locked
     * out nine seconds while the Druid died).
     *
     * It lists what was THERE; it does not say what would have won. Which answer fits which threat
     * is the player's read, and the card shows the threats beside it. A round is never left
     * unanalysed because of it.
     */
    private function withAnswers(array $deaths, array $lines, array $metadata, callable $sideOf, callable $credit,
        array $named, array $failed, array $locked, array $goes): array
    {
        if (! collect($deaths)->contains('side', 'us')) {
            return $deaths;
        }

        try {
            $pressed = [];
            foreach ($named as $c) {
                $who = $credit($c['src']);
                if ($sideOf($who) !== null) {
                    $pressed[$who][$c['spell']][] = (float) $c['t'];
                }
            }
            $kits = $this->kits($lines, $metadata, $sideOf, $pressed);
        } catch (\Throwable $e) {
            report($e);

            return $deaths;
        }

        // Our first death only: after it the round is usually decided, and a shuffle round ends there.
        $first = collect($deaths)->where('side', 'us')->sortBy('t')->keys()->first();
        foreach ($deaths as $i => &$d) {
            if ($i !== $first) {
                continue;
            }
            $to = (float) $d['t'];
            $from = $d['goStartedAgo'] !== null ? $to - $d['goStartedAgo'] : max(0.0, $to - 30);

            $rows = [];
            foreach ($kits as $who => $kit) {
                if ($sideOf($who) !== 'us') {
                    continue;
                }
                foreach ($kit as $k) {
                    $times = $pressed[$who][$k['spell']] ?? [];
                    $in = collect($times)->first(fn ($p) => $p >= $from - 0.5 && $p <= $to);
                    $earlier = array_filter($times, fn ($p) => $p < $from - 0.5 && $from < $p + $k['cd']);
                    $down = $in === null && count($earlier) >= $k['charges'];
                    $rows[] = [
                        'who' => $who, 'spell' => $k['spell'], 'kind' => $k['kind'], 'cd' => $k['cd'],
                        'state' => $in !== null ? 'pressed' : ($down ? 'down' : 'ready'),
                        'at' => $in !== null ? round($in, 1) : null,
                        'back' => $down ? round(min(array_map(fn ($p) => $p + $k['cd'], $earlier)), 1) : null,
                        // Tried and refused by crowd control, range or line of sight ("Can't do that
                        // while fleeing"): the press was meant. "Not yet recovered" is the global
                        // cooldown being pressed through, and says nothing.
                        'tried' => collect($failed)->filter(fn ($f) => $f['who'] === $who && $f['spell'] === $k['spell'] && $f['t'] >= $from && $f['t'] <= $to
                                && preg_match('/while|line of sight|range/i', $f['why']))
                            ->map(fn ($f) => ['t' => round($f['t'], 1), 'why' => $f['why']])->unique('why')->values()->all(),
                    ];
                }
            }

            $lockout = [];
            foreach ($kits as $who => $kit) {
                if ($sideOf($who) !== 'us') {
                    continue;
                }
                $iv = array_values(array_filter(array_map(fn ($x) => [max($x[0], $from), min($x[1], $to)], $locked[$who] ?? []), fn ($x) => $x[1] > $x[0]));
                if (! $iv) {
                    continue;
                }
                // Back-to-back lockouts (a fear into a stun) are one stretch to the player.
                $stretches = [];
                foreach ($iv as [$a, $b]) {
                    if ($stretches && $a - $stretches[count($stretches) - 1][1] <= 0.3) {
                        $stretches[count($stretches) - 1][1] = max($stretches[count($stretches) - 1][1], $b);
                    } else {
                        $stretches[] = [$a, $b];
                    }
                }
                usort($stretches, fn ($x, $y) => ($y[1] - $y[0]) <=> ($x[1] - $x[0]));
                [$la, $lb] = $stretches[0];
                $before = collect($stretches)->filter(fn ($s) => $s[1] <= $la)->max(fn ($s) => $s[1]) ?? $from;
                $lockout[$who] = [
                    'seconds' => round($this->len($iv), 1),
                    'longest' => [round($la, 1), round($lb, 1)],
                    'freeBefore' => $la - $before >= 0.5 ? [round($before, 1), round($la, 1)] : null,
                ];
            }

            $d['answers'] = ['from' => round($from, 1), 'rows' => $rows, 'lockout' => $lockout];
        }
        unset($d);

        return $deaths;
    }

    /**
     * Each player's buttons that can answer a go: defensives and the trinket, crowd control with a
     * cooldown that takes a player out (a peel), and interrupts. From their spec's matchup profile
     * (the default build), their own PvP talents, and anything else they pressed that the data calls
     * defensive or crowd control; a talent they did not take is dropped (CooldownLedgerService::takes).
     * Cooldowns are their own, talent-resolved.
     *
     * @return array<string, array<int, array{spell: string, kind: string, cd: float, charges: int}>>
     */
    private function kits(array $lines, array $metadata, callable $sideOf, array $pressed): array
    {
        $ccById = $this->moments->crowdControl();
        $ccByName = \App\Models\Spell::where('patch_id', \App\Models\Patch::where('is_current', true)->value('id'))
            ->whereIn('spell_id', array_keys($ccById))->get(['spell_id', 'name'])
            ->mapWithKeys(fn ($s) => [$s->display_name => $ccById[$s->spell_id]])->all();
        $classes = $this->arena->offensiveDefensiveClassification();
        // byName is keyed by the name exactly as the classification file spells it.
        $defensive = fn (string $n) => (bool) (($classes['byName'][$n] ?? $classes['byName'][strtolower($n)] ?? [])['defensive'] ?? false);
        $peel = fn (?string $dr) => in_array($dr, self::LOCKOUT, true);

        $raw = implode("\n", $lines);
        $kits = [];
        foreach ($metadata['units'] ?? [] as $u) {
            $guid = $u['id'] ?? '';
            $spec = $sideOf($guid) ? $this->arena->specForExternalId((string) $u['spec']) : null;
            if (! $spec) {
                continue;
            }
            $build = $this->ledger->build($this->arena->extractCombatantInfoFromLog($raw, $guid) ?? [], $spec);
            $profile = $this->ledger->profile($spec->gameClass?->slug, $spec->slug);

            $want = [];
            foreach ($profile['answers'] ?? [] as $a) {
                $want[$a['name']] = ($a['kind'] ?? '') === 'trinket' ? 'trinket' : 'defensive';
            }
            foreach ($profile['control'] ?? [] as $c) {
                if ($peel($c['drCategory'] ?? null) && ($c['cooldown'] ?? 0) >= 15) {
                    $want[$c['name']] ??= 'control';
                }
            }
            foreach ($profile['interrupts'] ?? [] as $i) {
                $want[$i['name']] ??= 'interrupt';
            }
            // Their own talents and PvP talents: Roar of Sacrifice is a talent, not in the profile.
            foreach ($build['names'] as $name) {
                if ($defensive($name)) {
                    $want[$name] ??= 'defensive';
                } elseif ($peel($ccByName[$name] ?? null)) {
                    $want[$name] ??= 'control';
                }
            }
            foreach (array_keys($pressed[$guid] ?? []) as $name) {
                // A spell used both ways (Vanish) is one of their answers when they have it.
                if ($defensive($name) || isset($this->contextual[$name])) {
                    $want[$name] ??= 'defensive';
                } elseif ($peel($ccByName[$name] ?? null)) {
                    $want[$name] ??= 'control';
                }
            }

            foreach ($want as $name => $kind) {
                // Pressing it proves they have it; otherwise a talent they did not take is not theirs.
                if (! isset($pressed[$guid][$name]) && ! $this->ledger->takes($name, $spec, $build)) {
                    continue;
                }
                $cd = $this->ledger->cooldown($name, $spec, $build);
                // A short-cooldown "defensive" found by name is a heal on a rotation (Power Word:
                // Radiance, Healing Stream Totem, Wild Growth), not an answer held for a go. The
                // profile's own answers (Fade, 20s) are kept whatever their cooldown.
                $fromProfile = in_array($name, array_column($profile['answers'] ?? [], 'name'), true);
                if (! $cd || ($kind === 'control' && $cd['cd'] < 15) || ($kind === 'defensive' && ! $fromProfile && $cd['cd'] < 30)) {
                    continue;
                }
                $kits[$guid][] = ['spell' => $name, 'kind' => $kind, 'cd' => $cd['cd'], 'charges' => $cd['charges']];
            }
        }

        return $kits;
    }

    /**
     * A function from (side, moment) to that side's damage defensives back at that moment. Null when
     * the cooldowns cannot be resolved; a round is never left unanalysed because of the ledger.
     */
    private function cover(array $lines, array $metadata, array $roster, callable $sideOf, array $tl): ?\Closure
    {
        try {
            $presses = [];
            foreach ($tl['commitments'] as $c) {
                if ($c['cat'] === 'defensive') {
                    $presses[$c['who']][$c['spell']][] = (float) $c['t'];
                }
            }

            $raw = implode("\n", $lines);
            $answers = ['us' => [], 'them' => []];
            foreach ($metadata['units'] ?? [] as $u) {
                $guid = $u['id'] ?? '';
                $side = $sideOf($guid);
                $spec = $side ? $this->arena->specForExternalId((string) $u['spec']) : null;
                if (! $spec) {
                    continue;
                }
                $build = $this->ledger->build($this->arena->extractCombatantInfoFromLog($raw, $guid) ?? [], $spec);
                $names = array_unique(array_merge($this->ledger->profileAnswers($spec->gameClass?->slug, $spec->slug), array_keys($presses[$guid] ?? [])));
                foreach ($names as $name) {
                    if ($cd = $this->ledger->cooldown($name, $spec, $build)) {
                        $answers[$side][] = ['who' => $guid, 'spell' => $name, 'cd' => $cd['cd'], 'charges' => $cd['charges']];
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return fn (string $side, float $t) => CooldownLedgerService::coverage($answers[$side], $presses, $t);
    }

    // ------------------------------------------------------------------ reading

    /**
     * Damage with spell names, pet owners, buff auras, interrupts, heals, absorbs and casts, on
     * readTimeline's clock. Heal and absorb offsets are CombatantThroughputService's measured ones
     * (amount -5 and overhealing -3 on a heal; shield caster -10, shield name -5 and absorbed -3 on
     * SPELL_ABSORBED, checked on a 2 Oct line), read from the end of the line.
     */
    private function secondPass(array $lines, array $roster): array
    {
        $t0 = null;
        $dmg = [];
        $owner = [];
        $buffs = [];
        $open = [];
        $interrupts = [];
        $end = null;
        $heals = [];
        $absorbs = [];
        $casts = [];
        // Melee seen as SWING_DAMAGE only. $dmg reads SWING_DAMAGE_LANDED alone, which every measure
        // above was checked against, so the extra hits go to the breakdown only. One hit is often
        // logged as both events: collapsed on timestamp, source, target and amount, the way
        // CombatantThroughputService does.
        $landed = [];
        $swingOnly = [];
        $dispels = [];
        $debuffs = [];
        $openDebuffs = [];
        $named = [];
        $failed = [];
        $t = 0.0;

        foreach ($lines as $line) {
            $s = $this->seconds($line);
            if ($s === null) {
                continue;
            }
            $t0 ??= $s;
            $t = round($s - $t0, 2);
            $body = explode('  ', $line, 2)[1] ?? '';
            $event = strtok($body, ',');

            if ($event === 'ARENA_MATCH_END') {
                $end = explode(',', $body);

                continue;
            }
            if ($event === 'SPELL_SUMMON') {
                $f = str_getcsv($body);
                $owner[$f[5]] = $f[1];

                continue;
            }
            if ($event === 'SPELL_CAST_SUCCESS') {
                $f = str_getcsv($body);
                if (! str_starts_with($f[1], 'Player-') && str_starts_with($f[13] ?? '', 'Player-')) {
                    $owner[$f[1]] ??= $f[13];
                }
                if (isset($roster[$f[1]])) {
                    $casts[$f[1]][] = $t;
                }
                // Every press by name, pets included (credited later), for the answer sheet.
                $named[] = ['src' => $f[1], 'spell' => $f[10], 't' => $t];

                continue;
            }
            if ($event === 'SPELL_CAST_FAILED') {
                $f = str_getcsv($body);
                if (isset($roster[$f[1]])) {
                    $failed[] = ['who' => $f[1], 'spell' => $f[10], 't' => $t, 'why' => $f[count($f) - 1]];
                }

                continue;
            }
            if ($event === 'SPELL_HEAL' || $event === 'SPELL_PERIODIC_HEAL') {
                $f = str_getcsv($body);
                $amount = $f[count($f) - 5] ?? null;
                $over = $f[count($f) - 3] ?? null;
                if (isset($roster[$f[5]]) && is_numeric($amount) && is_numeric($over)) {
                    $heals[] = ['src' => $f[1], 'dst' => $f[5], 'spell' => $f[10], 'eff' => (int) $amount - (int) $over, 'over' => (int) $over];
                }

                continue;
            }
            if ($event === 'SPELL_ABSORBED') {
                $f = str_getcsv($body);
                $amount = $f[count($f) - 3] ?? null;
                if (isset($roster[$f[5]]) && is_numeric($amount)) {
                    $absorbs[] = ['src' => $f[count($f) - 10] ?? '', 'dst' => $f[5], 'spell' => $f[count($f) - 5] ?? '?', 'amount' => (int) $amount];
                }

                continue;
            }
            if ($event === 'SPELL_INTERRUPT') {
                $f = str_getcsv($body);
                if (isset($roster[$f[5]])) {
                    $interrupts[] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'spell' => $f[10]];
                }

                continue;
            }
            if ($event === 'SPELL_DISPEL') {
                // ...,dispel spell id,name,school,removed id,removed name,school,BUFF|DEBUFF
                $f = str_getcsv($body);
                if (isset($roster[$f[5]])) {
                    $dispels[] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'spell' => $f[10], 'removed' => $f[13] ?? '?', 'type' => $f[15] ?? ''];
                }

                continue;
            }
            if (($event === 'SPELL_AURA_APPLIED' || $event === 'SPELL_AURA_REMOVED') && str_contains($body, ',DEBUFF')) {
                $f = str_getcsv($body);
                if (isset($roster[$f[5]])) {
                    $k = $f[5].'|'.$f[10].'|'.$f[1];
                    if ($event === 'SPELL_AURA_APPLIED') {
                        $openDebuffs[$k] ??= ['dst' => $f[5], 'src' => $f[1], 'name' => $f[10], 'from' => $t];
                    } elseif (isset($openDebuffs[$k])) {
                        $debuffs[] = $openDebuffs[$k] + ['to' => $t];
                        unset($openDebuffs[$k]);
                    }
                }

                continue;
            }
            if (($event === 'SPELL_AURA_APPLIED' || $event === 'SPELL_AURA_REMOVED') && str_contains($body, ',BUFF')) {
                $f = str_getcsv($body);
                if (isset($roster[$f[5]])) {
                    $k = $f[5].'|'.$f[10];
                    if ($event === 'SPELL_AURA_APPLIED') {
                        $open[$k] = [$t, $f[1]];
                    } elseif (isset($open[$k])) {
                        $buffs[] = ['name' => $f[10], 'on' => $f[5], 'from' => $open[$k][0], 'to' => $t, 'by' => $open[$k][1]];
                        unset($open[$k]);
                    }
                }

                continue;
            }
            if ($event === 'SWING_DAMAGE') {
                $f = str_getcsv($body);
                $amount = $f[count($f) - 10] ?? null;
                if (is_numeric($amount) && isset($roster[$f[5]]) && $f[1] !== $f[5]) {
                    $swingOnly[strtok($line, ' ').'|'.$s.'|'.$f[1].'|'.$f[5].'|'.$amount] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'amount' => (int) $amount, 'spell' => 'Melee'];
                }

                continue;
            }
            if (! in_array($event, self::DAMAGE_EVENTS, true)) {
                continue;
            }
            $f = str_getcsv($body);
            $swing = str_starts_with($event, 'SWING');
            $amount = $f[count($f) - ($swing ? 10 : 11)] ?? null;
            if (! is_numeric($amount) || ! isset($roster[$f[5]]) || $f[1] === $f[5]) {
                continue;
            }
            if ($swing) {
                $landed[strtok($line, ' ').'|'.$s.'|'.$f[1].'|'.$f[5].'|'.$amount] = true;
            }
            $base = $swing ? 9 : 12;
            $cur = $f[$base + 2] ?? null;
            $max = $f[$base + 3] ?? null;
            $dmg[] = [
                't' => $t, 'src' => $f[1], 'dst' => $f[5], 'amount' => (int) $amount,
                'spell' => $swing ? 'Melee' : $f[10],
                'hpAfter' => is_numeric($cur) && is_numeric($max) && $max > 0 ? round(100 * $cur / $max, 1) : null,
            ];
        }

        $swingOnly = array_values(array_diff_key($swingOnly, $landed));
        // A debuff still up when the log stops ran to the end of the round.
        foreach ($openDebuffs as $o) {
            $debuffs[] = $o + ['to' => $t];
        }

        return [$dmg, $owner, $buffs, $interrupts, $end, $heals, $absorbs, $casts, $swingOnly, $dispels, $debuffs, $named, $failed];
    }

    // ------------------------------------------------------------------ breakdown and checks

    /**
     * Each player's output by ability: damage onto the other team's players, healing (effective and
     * overheal) and absorbs onto their own team, and time spent not pressing anything. A pet's
     * ability is credited to its owner as "pet: Name". The top abilities are kept and the rest summed,
     * so a stored round stays small.
     */
    private function breakdown(array $roster, callable $sideOf, callable $credit, array $dmg, array $heals, array $absorbs,
        array $casts, array $locked, array $deaths, array $metadata): array
    {
        $out = [];
        $label = fn (string $src, string $spell) => isset($roster[$src]) ? $spell : 'pet: '.$spell;
        $add = function (string $who, string $kind, string $spell, int $amount, int $over = 0) use (&$out) {
            $out[$who][$kind][$spell] ??= ['amount' => 0, 'hits' => 0, 'over' => 0];
            $out[$who][$kind][$spell]['amount'] += $amount;
            $out[$who][$kind][$spell]['hits']++;
            $out[$who][$kind][$spell]['over'] += $over;
        };

        foreach ($dmg as $x) {
            $who = $credit($x['src']);
            if (isset($roster[$who]) && $sideOf($x['dst']) !== null && $sideOf($x['dst']) !== $sideOf($who)) {
                $add($who, 'damage', $label($x['src'], $x['spell']), $x['amount']);
            }
        }
        foreach ($heals as $x) {
            $who = $credit($x['src']);
            if (isset($roster[$who]) && $sideOf($x['dst']) === $sideOf($who)) {
                $add($who, 'healing', $label($x['src'], $x['spell']), $x['eff'], $x['over']);
            }
        }
        foreach ($absorbs as $x) {
            $who = $credit($x['src']);
            if (isset($roster[$who]) && $sideOf($x['dst']) === $sideOf($who)) {
                $add($who, 'absorbs', $x['spell'], $x['amount']);
            }
        }

        $duration = (float) ($metadata['durationInSeconds'] ?? 0);
        $diedAt = array_column($deaths, 't', 'who');
        $rows = [];
        foreach ($roster as $g => $r) {
            $alive = max(1.0, min($diedAt[$g] ?? $duration, $duration ?: ($diedAt[$g] ?? 1.0)));
            $rows[$g] = [
                'alive' => round($alive, 1),
                'idle' => round($this->idle($casts[$g] ?? [], $locked[$g] ?? [], $alive), 1),
                'casts' => count(array_filter($casts[$g] ?? [], fn ($t) => $t <= $alive)),
            ];
            foreach (['damage', 'healing', 'absorbs'] as $kind) {
                $rows[$g][$kind] = $this->topAbilities($out[$g][$kind] ?? []);
            }
        }

        return $rows;
    }

    /** The top abilities by amount, with everything else summed into one "Other" row. */
    private function topAbilities(array $byAbility, int $keep = 10): array
    {
        uasort($byAbility, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $rows = [];
        $other = ['amount' => 0, 'hits' => 0, 'over' => 0];
        $i = 0;
        foreach ($byAbility as $spell => $v) {
            if ($v['amount'] <= 0 && $v['over'] <= 0) {
                continue;
            }
            if ($i++ < $keep) {
                $rows[] = ['spell' => $spell] + $v;
            } else {
                $other['amount'] += $v['amount'];
                $other['hits'] += $v['hits'];
                $other['over'] += $v['over'];
            }
        }
        if ($other['hits'] > 0) {
            $rows[] = ['spell' => 'Other'] + $other;
        }

        return $rows;
    }

    /**
     * Seconds with nothing pressed, exactly as specread.php counts it: between the first and the last
     * cast, a gap over IDLE_GAP counts its length beyond one global (1.5s), less any time spent
     * locked out in it.
     */
    private function idle(array $castTimes, array $lockedIv, float $alive): float
    {
        sort($castTimes);
        $idle = 0.0;
        $prev = null;
        foreach ($castTimes as $t) {
            if ($t > $alive) {
                break;
            }
            if ($prev !== null && $t - $prev > self::IDLE_GAP) {
                $idle += max(0, ($t - $prev - 1.5) - $this->cross($lockedIv, [[$prev + 1.5, $t]]));
            }
            $prev = $t;
        }

        return $idle;
    }

    /**
     * Losses the log and the spell data can show by themselves, for your side only.
     *
     * - An OFFENSIVE cooldown left sitting ready for at least one whole cooldown, counted from the
     *   gates to the first press, between presses, and from the last press to your death or the end.
     *   Cooldowns are the data's base values (rule 34): a talent that shortens one makes the real
     *   ready time longer, so this is a lower bound. Defensives are left out on purpose: a defensive
     *   held because nothing threatened you is not a loss.
     * - A defensive put on a teammate who was already immune (IMMUNITIES), taken from the overlap
     *   rows: the second one did nothing.
     */
    private function checks(array $tl, array $roster, callable $sideOf, array $deaths, array $metadata, array $overlaps): array
    {
        $duration = (float) ($metadata['durationInSeconds'] ?? 0);
        $diedAt = array_column($deaths, 't', 'who');
        $out = [];

        $presses = [];
        foreach ($tl['commitments'] as $c) {
            if (in_array($c['cat'], ['offensive', 'mixed'], true) && $sideOf($c['who']) === 'us' && ($c['cooldown'] ?? 0) >= 30) {
                $presses[$c['who'].'|'.$c['spell']][] = $c;
            }
        }
        foreach ($presses as $list) {
            usort($list, fn ($a, $b) => $a['t'] <=> $b['t']);
            $cd = (float) $list[0]['cooldown'];
            $end = min($diedAt[$list[0]['who']] ?? $duration, $duration);
            $ready = $list[0]['t'];
            for ($i = 1; $i < count($list); $i++) {
                $ready += max(0, $list[$i]['t'] - $list[$i - 1]['t'] - $cd);
            }
            $ready += max(0, $end - end($list)['t'] - $cd);
            $lost = (int) floor($ready / $cd);
            if ($lost >= 1) {
                $out[] = ['kind' => 'cooldown-ready', 'who' => $list[0]['who'], 'spell' => $list[0]['spell'],
                    'ready' => round($ready), 'cooldown' => (int) $cd, 'presses' => count($list), 'lost' => $lost];
            }
        }

        foreach ($overlaps['rows'] as $o) {
            if ($o['side'] === 'us' && in_array($o['first']['spell'], self::IMMUNITIES, true) && $o['second']['by'] !== null && $o['second']['by'] !== $o['on']) {
                $out[] = ['kind' => 'on-immune', 'who' => $o['second']['by'], 'spell' => $o['second']['spell'], 'on' => $o['on'],
                    'immunity' => $o['first']['spell'], 't' => round($o['t'], 1)];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ goes

    /**
     * Goes as chains (match-review-operations.md, "What a go is"): offensive casts and CC that
     * landed on the enemy, linked from the chain's END; 5s links make a good go, links up to 10s
     * join only through an offensive cooldown and make a bad go; no offensive cooldown, no go.
     */
    private function goes(array $tl, array $roster, callable $sideOf, callable $credit, array $healers, array $dmg): array
    {
        $goes = ['us' => [], 'them' => []];

        foreach (['us', 'them'] as $side) {
            $other = $side === 'us' ? 'them' : 'us';
            $events = [];
            foreach ($tl['commitments'] as $c) {
                if ($sideOf($c['who']) === $side && in_array($c['cat'], ['offensive', 'mixed'], true)) {
                    $events[] = ['t' => $c['t'], 'end' => $c['t'] + self::OFFENSIVE_HOLD, 'spell' => $c['spell'], 'cat' => 'offensive', 'on' => null, 'by' => $c['who']];
                }
            }
            foreach ($tl['control'] as $c) {
                if (in_array($c['dr'], self::LOCKOUT, true) && $sideOf($c['on']) === $other && $sideOf($credit($c['by'])) === $side) {
                    $events[] = ['t' => $c['from'], 'end' => $c['to'], 'spell' => $c['spell'], 'cat' => 'control', 'on' => $c['on'], 'by' => $credit($c['by'])];
                }
            }
            usort($events, fn ($a, $b) => $a['t'] <=> $b['t']);

            $chains = [];
            $cur = [];
            $end = -INF;
            $endByOffensive = false;
            foreach ($events as $e) {
                $gap = $e['t'] - $end;
                $loose = $e['cat'] === 'offensive' || $endByOffensive;
                if ($cur && ($gap > self::LOOSE_LINK || ($gap > self::TIGHT_LINK && ! $loose))) {
                    $chains[] = $cur;
                    $cur = [];
                    $end = -INF;
                    $endByOffensive = false;
                }
                $e['gap'] = $cur ? round(max(0, $e['t'] - $end), 1) : 0.0;
                $cur[] = $e;
                if ($e['end'] >= $end) {
                    $end = $e['end'];
                    $endByOffensive = $e['cat'] === 'offensive';
                }
            }
            if ($cur) {
                $chains[] = $cur;
            }

            foreach ($chains as $ch) {
                $offs = array_values(array_filter($ch, fn ($e) => $e['cat'] === 'offensive'));
                if (! $offs) {
                    continue;
                }
                $from = $ch[0]['t'];
                $to = max(end($offs)['t'] + self::GO_TAIL, max(array_column($ch, 'end')));
                $hit = [];
                foreach ($dmg as $x) {
                    if ($x['t'] >= $from && $x['t'] <= $to && $sideOf($x['dst']) === $other && $sideOf($credit($x['src'])) === $side) {
                        $hit[$x['dst']] = ($hit[$x['dst']] ?? 0) + $x['amount'];
                    }
                }
                arsort($hit);
                $target = array_key_first($hit);
                foreach ($ch as &$e) {
                    if ($e['cat'] === 'control') {
                        $e['role'] = ($healers[$other] ?? null) === $e['on'] ? 'healer' : ($e['on'] === $target ? 'target' : 'cross');
                    }
                }
                unset($e);
                $goes[$side][] = ['from' => $from, 'to' => $to, 'casts' => $ch, 'target' => $target,
                    'firstOffensive' => $offs[0]['t'], 'maxGap' => max(array_column($ch, 'gap'))];
            }
        }

        return $goes;
    }

    private function describeGo(array $go, string $side, string $other, array $tl, array $deaths, array $locked, array $healers,
        array $roster, callable $sideOf, callable $credit, array $dmg, array $interrupts, array $goes): array
    {
        [$from, $to] = [$go['from'], $go['to']];
        $defs = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $other && $c['cat'] === 'defensive' && $c['t'] >= $from && $c['t'] <= $to));
        $drained = count(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $other && $c['cat'] === 'defensive' && $c['t'] < $from && $from < $c['t'] + $c['cooldown']));
        $kill = collect($deaths)->first(fn ($d) => $d['side'] === $other && $d['t'] >= $from && $d['t'] <= $to);
        $killLater = collect($deaths)->contains(fn ($d) => $d['side'] === $other && $d['t'] >= $from && $d['t'] <= $to + self::KILL_LATER);

        // Peak burst: the 6s of this go with the most damage from the attacking DPS.
        $hits = array_values(array_filter($dmg, function ($x) use ($credit, $sideOf, $roster, $side, $other, $from, $to) {
            $who = $credit($x['src']);

            return $x['t'] >= $from && $x['t'] <= $to && $sideOf($x['dst']) === $other && $sideOf($who) === $side && isset($roster[$who]) && ! $roster[$who]['healer'];
        }));
        $peak = ['sum' => 0, 'from' => $from];
        for ($i = 0, $j = 0, $run = 0; $i < count($hits); $i++) {
            $run += $hits[$i]['amount'];
            while ($hits[$i]['t'] - $hits[$j]['t'] > self::PEAK) {
                $run -= $hits[$j]['amount'];
                $j++;
            }
            if ($run > $peak['sum']) {
                $peak = ['sum' => $run, 'from' => $hits[$j]['t']];
            }
        }
        $byAbility = [];
        $byPlayer = [];
        $burstRows = [];
        foreach ($hits as $x) {
            if ($x['t'] >= $peak['from'] && $x['t'] <= $peak['from'] + self::PEAK) {
                $who = $credit($x['src']);
                $byAbility[$roster[$who]['name'].': '.$x['spell']] = ($byAbility[$roster[$who]['name'].': '.$x['spell']] ?? 0) + $x['amount'];
                $byPlayer[$who] = ($byPlayer[$who] ?? 0) + $x['amount'];
                $k = $who.'|'.$x['spell'];
                $burstRows[$k] ??= ['who' => $who, 'spell' => $x['spell'], 'amount' => 0];
                $burstRows[$k]['amount'] += $x['amount'];
            }
        }
        arsort($byAbility);
        usort($burstRows, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $defHealer = $healers[$other] ?? null;
        $atkHealer = $healers[$side] ?? null;
        $peakWin = [[$peak['from'], $peak['from'] + self::PEAK]];
        $peakKicks = array_filter($interrupts, fn ($x) => $x['t'] >= $peak['from'] && $x['t'] <= $peak['from'] + self::PEAK && $sideOf($credit($x['src'])) === $side && $x['dst'] === $defHealer);
        $peakCcBy = array_values(array_unique(array_map(fn ($c) => $credit($c['by']), array_filter($tl['control'], fn ($c) => $c['on'] === $defHealer
            && in_array($c['dr'], self::LOCKOUT, true) && $c['from'] < $peak['from'] + self::PEAK && $c['to'] > $peak['from']))));
        $goCcOnHealerBy = array_values(array_unique(array_map(fn ($c) => $credit($c['by']), array_filter($tl['control'], fn ($c) => $c['on'] === $defHealer
            && in_array($c['dr'], self::LOCKOUT, true) && $c['from'] >= $from && $c['from'] <= $to))));
        $burst = [[$go['firstOffensive'] - 1, $go['firstOffensive'] + 4]];

        return [
            'side' => $side,
            'from' => $from,
            'to' => round($to, 2),
            'good' => $go['maxGap'] <= self::TIGHT_LINK,
            'chain' => implode(', ', array_map(fn ($e) => $e['spell'].(isset($e['role']) ? ' > '.$e['role'] : ''), $go['casts'])),
            // The go as rows, for drawing it like a guide sequence: each link in order, who pressed
            // or applied it, on whom, seconds from the go's start, and the gap that joined it.
            'links' => array_map(fn ($e) => [
                't' => round($e['t'] - $from, 1), 'spell' => $e['spell'], 'cat' => $e['cat'],
                'role' => $e['role'] ?? null, 'by' => $e['by'] ?? null, 'on' => $e['on'], 'gap' => $e['gap'],
            ], $go['casts']),
            'forced' => array_map(fn ($c) => ['t' => round($c['t'] - $from, 1), 'spell' => $c['spell'], 'who' => $c['who']], $defs),
            'burst' => array_slice($burstRows, 0, 6),
            'target' => $go['target'],
            'healerCc' => count(array_filter($go['casts'], fn ($e) => ($e['role'] ?? null) === 'healer')),
            'defs' => count($defs),
            'defNames' => array_column($defs, 'spell'),
            'drained' => $drained,
            'kill' => $kill ? $kill['who'] : null,
            'killLater' => $killLater,
            'peak' => [
                'damage' => $peak['sum'],
                'joint' => count($byPlayer) >= 2 && min($byPlayer) / max(1, array_sum($byPlayer)) >= 0.25,
                'healerLocked' => $defHealer ? round($this->cross($locked[$defHealer], $peakWin), 1) : 0.0,
                'healerKicked' => count($peakKicks) > 0,
                'healerCcBy' => $peakCcBy,
                'abilities' => array_slice($byAbility, 0, 5, true),
            ],
            'ownHealerLockedAtCds' => $atkHealer ? $this->cross($locked[$atkHealer], $burst) > 0 : false,
            'healerCcBy' => $goCcOnHealerBy,
        ];
    }

    // ------------------------------------------------------------------ deaths

    private function killRead(array $d, array $tl, array $dmg, array $locked, array $healers, array $roster, callable $sideOf, callable $credit, array $goes): array
    {
        $killer = $d['side'] === 'us' ? 'them' : 'us';
        $win = array_values(array_filter($dmg, fn ($x) => $x['dst'] === $d['who'] && $x['t'] <= $d['t'] + 0.05 && $x['t'] >= $d['t'] - 10));
        $shares = [];
        foreach ($win as $x) {
            $who = $credit($x['src']);
            $key = isset($roster[$who]) ? $roster[$who]['name'] : 'pets/other';
            $shares[$key] = ($shares[$key] ?? 0) + $x['amount'];
        }
        arsort($shares);
        $total = max(1, array_sum($shares));
        $kb = end($win) ?: null;
        $before = null;
        foreach ($win as $x) {
            if ($x === $kb) {
                break;
            }
            $before = $x['hpAfter'] ?? $before;
        }

        $healer = $healers[$d['side']] ?? null;
        $state = 'none';
        $endedAgo = null;
        if ($healer === $d['who']) {
            $state = 'was the kill';
        } elseif ($healer) {
            foreach ($locked[$healer] as [$a, $b]) {
                if ($a <= $d['t'] && $b >= $d['t'] - 0.1) {
                    $state = 'locked';
                } elseif ($b < $d['t'] && $b >= $d['t'] - 10 && $state === 'none') {
                    $state = 'ended';
                    $endedAgo = round($d['t'] - $b, 1);
                }
            }
        }

        $go = collect($goes[$killer])->first(fn ($g) => $g['from'] <= $d['t'] && $g['to'] >= $d['t'] - 0.1);
        $defs = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $d['side'] && $c['cat'] === 'defensive' && $c['t'] >= $d['t'] - 30 && $c['t'] <= $d['t']));
        $medallion = $healer ? array_values(array_map(fn ($c) => $c['t'], array_filter($tl['commitments'], fn ($c) => $c['who'] === $healer && $c['spell'] === "Gladiator's Medallion" && $c['t'] <= $d['t']))) : [];

        return [
            't' => $d['t'],
            'who' => $d['who'],
            'side' => $d['side'],
            'killingBlow' => $kb ? ['spell' => $kb['spell'], 'amount' => $kb['amount'], 'hpBefore' => $before] : null,
            'shares' => array_map(fn ($v) => round(100 * $v / $total), array_slice($shares, 0, 4, true)),
            'healer' => ['state' => $state, 'endedAgo' => $endedAgo, 'medallionUsedAt' => $medallion],
            'goStartedAgo' => $go ? round($d['t'] - $go['from'], 1) : null,
            'defensives30s' => array_map(fn ($c) => ['spell' => $c['spell'], 'who' => $roster[$c['who']]['name'] ?? '?', 'ago' => round($d['t'] - $c['t'])], $defs),
        ];
    }

    // ------------------------------------------------------------------ the rest

    /**
     * Defensives each side spent before the first death, and how many outside the other side's goes.
     * `beforeLockout` (version 7): the presser was locked out within BEFORE_LOCKOUT seconds after.
     * A defensive has to go out before its owner loses the chance to press it, so one pressed then
     * is not "outside their go" in any sense that matters (the 3 Oct game this came from: Pain
     * Suppression held at 14s, the healer locked out 15.8-24.7s, the Druid dead at 22.8s).
     */
    private function defensives(array $tl, callable $sideOf, array $goes, float $firstDeath, array $roster, array $locked): array
    {
        $out = [];
        foreach (['us' => 'them', 'them' => 'us'] as $side => $opp) {
            $spent = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $side && $c['cat'] === 'defensive' && $c['t'] <= $firstDeath));
            $isOutside = fn ($c) => ! collect($goes[$opp])->contains(fn ($g) => $c['t'] >= $g['from'] && $c['t'] <= $g['to']);
            $before = fn ($c) => collect($locked[$c['who']] ?? [])->contains(fn ($iv) => $iv[0] > $c['t'] && $iv[0] <= $c['t'] + self::BEFORE_LOCKOUT);
            $out[$side] = [
                'spent' => count($spent),
                'outsideTheirGoes' => count(array_filter($spent, $isOutside)),
                'rows' => array_map(fn ($c) => ['t' => $c['t'], 'spell' => $c['spell'], 'who' => $c['who'], 'outside' => $isOutside($c), 'beforeLockout' => $before($c)], $spent),
            ];
        }

        return $out;
    }

    /** Two different defensives on one player at once for a second or more, before the first death. */
    private function overlaps(array $tl, array $buffs, callable $sideOf, float $firstDeath): array
    {
        $names = array_unique(array_column(array_filter($tl['commitments'], fn ($c) => $c['cat'] === 'defensive'), 'spell'));
        $db = array_values(array_filter($buffs, fn ($b) => in_array($b['name'], $names, true)));
        $out = ['us' => 0, 'them' => 0, 'rows' => []];
        for ($i = 0; $i < count($db); $i++) {
            for ($j = $i + 1; $j < count($db); $j++) {
                $both = min($db[$i]['to'], $db[$j]['to']) - max($db[$i]['from'], $db[$j]['from']);
                if ($db[$i]['on'] === $db[$j]['on'] && $db[$i]['name'] !== $db[$j]['name'] && $db[$i]['from'] <= $firstDeath && $both >= 1.0) {
                    $out[$sideOf($db[$i]['on'])]++;
                    // The one applied SECOND is the overlap: it went on while the first was up.
                    [$first, $second] = $db[$i]['from'] <= $db[$j]['from'] ? [$db[$i], $db[$j]] : [$db[$j], $db[$i]];
                    $out['rows'][] = [
                        'side' => $sideOf($db[$i]['on']), 'on' => $first['on'], 't' => $second['from'], 'seconds' => round($both, 1),
                        'first' => ['spell' => $first['name'], 'by' => $first['by'] ?? null],
                        'second' => ['spell' => $second['name'], 'by' => $second['by'] ?? null],
                    ];
                }
            }
        }

        return $out;
    }

    private function kicks(array $interrupts, callable $sideOf, callable $credit, array $healers, array $goes): array
    {
        $out = [];
        foreach ($interrupts as $x) {
            $side = $sideOf($credit($x['src']));
            if ($side === null) {
                continue;
            }
            $out[] = [
                'side' => $side,
                'onHealer' => ($healers[$sideOf($x['dst'])] ?? null) === $x['dst'],
                'inOwnGo' => collect($goes[$side])->contains(fn ($g) => $x['t'] >= $g['from'] && $x['t'] <= $g['to']),
            ];
        }

        return $out;
    }

    /** Both teams' MMR from ARENA_MATCH_END (fields 3 and 4); ours is the one the metadata recorded. */
    private function mmr(?array $end, array $metadata): array
    {
        $ours = $metadata['playerTeamRating'] ?? null;
        if ($end === null || $ours === null || ! isset($end[3], $end[4])) {
            return ['us' => $ours, 'them' => null];
        }

        return ['us' => (int) $ours, 'them' => (int) $end[3] === (int) $ours ? (int) $end[4] : (int) $end[3]];
    }

    private function players(array $metadata, array $roster, callable $sideOf, ?string $logger): array
    {
        $out = [];
        foreach ($metadata['units'] ?? [] as $u) {
            if (! isset($roster[$u['id']])) {
                continue;
            }
            $spec = $this->arena->specForExternalId((string) $u['spec']);
            $out[] = [
                'guid' => $u['id'],
                'name' => $u['name'],
                'spec' => trim(($spec?->name ?? '?').' '.($spec?->gameClass?->name ?? '')),
                'specExternalId' => (int) $u['spec'],
                'classSlug' => $spec?->gameClass?->slug,
                'side' => $sideOf($u['id']),
                'healer' => $roster[$u['id']]['healer'],
                'logger' => $u['id'] === $logger,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ intervals

    private function union(array $iv): array
    {
        usort($iv, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($iv as [$a, $b]) {
            if ($out && $a <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    private function len(array $iv): float
    {
        return array_sum(array_map(fn ($x) => $x[1] - $x[0], $iv));
    }

    private function cross(array $a, array $b): float
    {
        $s = 0;
        foreach ($a as $x) {
            foreach ($b as $y) {
                $s += max(0, min($x[1], $y[1]) - max($x[0], $y[0]));
            }
        }

        return $s;
    }

    /** Same clock as ArenaMomentService::seconds(): only differences matter. */
    private function seconds(string $line): ?float
    {
        if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
            return null;
        }

        return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
    }
}
