<?php

namespace App\Http\Services;

use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Support\Facades\File;

/**
 * Measures, from real archived matches, WHO each crowd-control ability is actually used on — the
 * enemy healer, or one of their damage dealers.
 *
 * Built 2026-09-06 in direct response to "I don't really want the curated chain, I was hoping that
 * we could get the info by looking at the match data so it can pick these things up
 * automatically." That is both possible and better than the alternatives, and this class does it.
 *
 * WHY THIS BEATS BOTH THINGS IT REPLACES. Burst Guides previously answered "where does this
 * control go?" from `spells.chain_target` — hand-curated, hence only 20 of 132 CC-tagged spells —
 * falling back to an inference from `dr_category` for the rest. The inference could never be more
 * than a constraint ("this survives damage, so it *could* go on the kill target"), and reading it
 * as guidance produced real reported errors. Observation answers the actual question directly, and
 * covers 80 of those 132 spells with no curation at all.
 *
 * This is direct real-world observation, not a structural heuristic — the distinction
 * spell-acquisition-model.md draws explicitly. A real player, in a real rated match, cast a real
 * ability on a real opponent whose spec is recorded in that match's own metadata. That is the same
 * evidence tier `wow:diff-arena-spells --apply` already writes on without per-line review, and a
 * different thing entirely from the indirect signals this project has tried and reverted
 * (`spell_relationships` inference, the abandoned `alwaysAvailableAbilityIds()`).
 *
 * ---------------------------------------------------------------------------------------------
 * THE BASELINE IS THE WHOLE TRICK. A 3v3 team is one healer and two damage dealers, so control
 * distributed at random lands on the healer a third of the time. A raw "27% of Kidney Shots hit a
 * healer" therefore does NOT mean Kidney Shot is a healer ability used badly — it means it is
 * used on the healer *less* than chance would predict. Every figure here is a ratio against the
 * expected share computed from that match's own real roster (measured at 33.3% across the
 * archive, confirming 3v3 with one healer, but computed per match rather than assumed so an
 * unusual composition cannot skew it).
 *
 * TWO EVENT SOURCES, because one is not enough:
 *  - `SPELL_AURA_APPLIED ... DEBUFF` is the primary signal: the control actually LANDED on that
 *    player. Preferred wherever available, since a cast that was immune/line-of-sighted teaches
 *    nothing about who got controlled.
 *  - `SPELL_CAST_SUCCESS` with a player destination is the fallback, and it is load-bearing rather
 *    than belt-and-braces: ground-targeted zone control never applies an aura under its own
 *    spell_id at all. Solar Beam — the exact ability first reported as mislabelled — has ZERO aura
 *    events across all 689 archived matches, but 298 casts, each naming the player whose location
 *    was beamed. Aura-only, it was invisible; with the fallback it resolves at 60% healer (1.8x
 *    random), which is the right answer.
 *
 * Only control landing on the OPPOSING team counts (compared via each match's `reaction` field,
 * the same team split `resolveOpposingTeamSpecs()` uses), and self-application is excluded, so a
 * defensive or friendly application can never be read as targeting.
 *
 * The output is a rate per ability, never a label — `BurstGuideBuilder` decides how to bucket it,
 * and the page shows the measured number alongside so a reader can see the evidence rather than
 * take a one-word verdict on trust.
 */
class CcTargetingAnalyzer
{
    /** Below this many observations an ability's rate is noise; it is reported but marked unusable. */
    public const MIN_SAMPLE = 20;

    public function __construct(private ArenaLogService $arenaLog) {}

    public function outputPath(): string
    {
        return base_path('data/arena-logs/cc-targeting.json');
    }

    /**
     * Reads the promoted result, keyed by external spell_id. Returns an empty array when the file
     * is missing — every consumer treats "no observation" as a normal state and falls back.
     *
     * @return array<int, array{name:string, healerShare:float, ratio:float, observations:int, source:string}>
     */
    public function load(): array
    {
        $path = $this->outputPath();
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || empty($decoded['spells'])) {
            return [];
        }

        $byId = [];
        foreach ($decoded['spells'] as $entry) {
            foreach ($entry['spellIds'] as $id) {
                $byId[(int) $id] = $entry;
            }
        }

        return $byId;
    }

    /**
     * Scans every archived match and aggregates control targeting per ability.
     *
     * Aggregated by DISPLAY NAME, not spell_id, then written back against every spell_id that
     * shares that name — the standard same-name recovery in this codebase. Necessary here because
     * the spell_id a log records for a landing is routinely not the one carrying the curated
     * `dr_category` (Intimidation, Freezing Trap and Rake all have several real copies).
     *
     * @param  callable|null  $progress  called with (matchesScanned, totalMatches)
     * @return array{matches:int, landings:int, spells:array<int, array>}
     */
    public function analyze(?callable $progress = null): array
    {
        $archive = rtrim((string) config('arena_logs.archive_path'), '/\\');
        $files = File::glob("{$archive}/raw/*.log.gz") ?: [];

        $patchId = Patch::where('is_current', true)->value('id');
        if (! $patchId) {
            return ['matches' => 0, 'landings' => 0, 'spells' => []];
        }

        [$nameBySpellId, $spellIdsByName, $drByName] = $this->ccLookups($patchId);
        if ($nameBySpellId === []) {
            return ['matches' => 0, 'landings' => 0, 'spells' => []];
        }

        $isHealerBySpec = $this->healerLookup();

        $tally = ['aura' => [], 'cast' => []];
        $matches = 0;
        $landings = 0;
        $total = count($files);

        foreach ($files as $index => $rawPath) {
            $matchId = basename($rawPath, '.log.gz');
            $roster = $this->rosterFor($archive, $matchId, $isHealerBySpec);
            if ($roster === null) {
                continue;
            }

            $raw = @gzdecode((string) @File::get($rawPath));
            if ($raw === false || $raw === '') {
                continue;
            }
            $matches++;

            foreach ($this->eventPatterns() as $kind => $pattern) {
                preg_match_all($pattern, $raw, $found, PREG_SET_ORDER);
                foreach ($found as [$_, $source, $dest, $spellId]) {
                    $name = $nameBySpellId[(int) $spellId] ?? null;
                    if ($name === null || $source === $dest) {
                        continue;
                    }

                    $src = $roster['players'][$source] ?? null;
                    $dst = $roster['players'][$dest] ?? null;
                    // Both must be known players on OPPOSING teams — this is what stops a friendly
                    // or self application counting as "who you use it on".
                    if ($src === null || $dst === null || $src['reaction'] === $dst['reaction']) {
                        continue;
                    }

                    $side = $roster['sides'][$dst['reaction']] ?? null;
                    if ($side === null || $side['players'] === 0) {
                        continue;
                    }

                    $tally[$kind][$name]['n'] = ($tally[$kind][$name]['n'] ?? 0) + 1;
                    $tally[$kind][$name]['healer'] = ($tally[$kind][$name]['healer'] ?? 0) + ($dst['healer'] ? 1 : 0);
                    // Expected healer share for THIS match's real enemy roster, accumulated per
                    // observation so the comparison never assumes a 3v3 one-healer composition.
                    $tally[$kind][$name]['expected'] = ($tally[$kind][$name]['expected'] ?? 0) + ($side['healers'] / $side['players']);
                    $landings++;
                }
            }

            unset($raw);

            if ($progress !== null) {
                $progress($index + 1, $total);
            }
        }

        return [
            'matches' => $matches,
            'landings' => $landings,
            'spells' => $this->summarise($tally, $spellIdsByName, $drByName),
        ];
    }

    /**
     * @return array{0: array<int,string>, 1: array<string,array<int,int>>, 2: array<string,string>}
     */
    private function ccLookups(int $patchId): array
    {
        $spells = Spell::where('patch_id', $patchId)->whereNotNull('dr_category')->get();

        $nameBySpellId = [];
        $spellIdsByName = [];
        $drByName = [];
        foreach ($spells as $spell) {
            $name = $spell->display_name;
            $nameBySpellId[(int) $spell->spell_id] = $name;
            $spellIdsByName[$name][] = (int) $spell->spell_id;
            $drByName[$name] = $spell->dr_category;
        }

        return [$nameBySpellId, $spellIdsByName, $drByName];
    }

    /** @return array<int, bool> Blizzard external spec id => is a healer spec */
    private function healerLookup(): array
    {
        $classSlugs = GameClass::pluck('slug', 'id');

        $lookup = [];
        foreach (Specialization::whereNotNull('external_spec_id')->get() as $spec) {
            $classSlug = $classSlugs[$spec->class_id] ?? null;
            if ($classSlug !== null) {
                $lookup[(int) $spec->external_spec_id] = $this->arenaLog->isHealerSpec($classSlug, $spec->slug);
            }
        }

        return $lookup;
    }

    /**
     * One match's player roster plus each side's healer count, from its own metadata.
     *
     * @return array{players: array<string, array{reaction:int, healer:bool}>, sides: array<int, array{players:int, healers:int}>}|null
     */
    private function rosterFor(string $archive, string $matchId, array $isHealerBySpec): ?array
    {
        $metaPath = "{$archive}/metadata/{$matchId}.json";
        if (! File::exists($metaPath)) {
            return null;
        }

        $meta = json_decode(File::get($metaPath), true);
        if (! is_array($meta) || empty($meta['units'])) {
            return null;
        }

        $players = [];
        $sides = [];
        foreach ($meta['units'] as $unit) {
            $id = (string) ($unit['id'] ?? '');
            // Players only — pets and totems carry GUIDs too and are not who control is aimed at.
            if (! str_starts_with($id, 'Player-') || (string) ($unit['spec'] ?? '0') === '0') {
                continue;
            }

            $reaction = (int) ($unit['reaction'] ?? 0);
            $healer = $isHealerBySpec[(int) $unit['spec']] ?? false;

            $players[$id] = ['reaction' => $reaction, 'healer' => $healer];
            $sides[$reaction]['players'] = ($sides[$reaction]['players'] ?? 0) + 1;
            $sides[$reaction]['healers'] = ($sides[$reaction]['healers'] ?? 0) + ($healer ? 1 : 0);
        }

        return count($players) >= 2 ? ['players' => $players, 'sides' => $sides] : null;
    }

    /** @return array<string, string> */
    private function eventPatterns(): array
    {
        return [
            'aura' => '/SPELL_AURA_APPLIED,(Player-[^,]*),"[^"]*",[^,]*,[^,]*,(Player-[^,]*),"[^"]*",[^,]*,[^,]*,(\d+),"[^"]*",[^,]*,DEBUFF/',
            'cast' => '/SPELL_CAST_SUCCESS,(Player-[^,]*),"[^"]*",[^,]*,[^,]*,(Player-[^,]*),"[^"]*",[^,]*,[^,]*,(\d+),"/',
        ];
    }

    /**
     * Picks the better signal per ability and turns the tallies into rates.
     *
     * Aura events win whenever there are enough of them, because a landing is stronger evidence
     * than a cast. The cast fallback exists for ground-targeted zone control, which never applies
     * an aura under its own spell_id — without it, Solar Beam has no data at all.
     *
     * @return array<int, array>
     */
    private function summarise(array $tally, array $spellIdsByName, array $drByName): array
    {
        $names = array_unique(array_merge(array_keys($tally['aura']), array_keys($tally['cast'])));
        $rows = [];

        foreach ($names as $name) {
            $aura = $tally['aura'][$name] ?? null;
            $cast = $tally['cast'][$name] ?? null;

            $source = ($aura !== null && $aura['n'] >= self::MIN_SAMPLE) ? 'aura' : 'cast';
            $chosen = $tally[$source][$name] ?? $aura ?? $cast;
            if ($chosen === null || $chosen['n'] === 0) {
                continue;
            }

            $share = $chosen['healer'] / $chosen['n'];
            $expected = $chosen['expected'] / $chosen['n'];

            $rows[] = [
                'name' => $name,
                'drCategory' => $drByName[$name] ?? null,
                'spellIds' => $spellIdsByName[$name] ?? [],
                'observations' => $chosen['n'],
                'healerShare' => round($share, 3),
                'expectedShare' => round($expected, 3),
                'ratio' => $expected > 0 ? round($share / $expected, 2) : 0.0,
                'source' => $source,
                'usable' => $chosen['n'] >= self::MIN_SAMPLE,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['observations'] <=> $a['observations']);

        return $rows;
    }
}
