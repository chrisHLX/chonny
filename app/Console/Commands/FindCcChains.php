<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Scans every locally-stored arena log for continuous CC chains landing on real healers, ranked
 * by total time-in-CC — the CC-side counterpart to wow-arena-archive's offensive-rotations.php
 * (see that script + WowComps::getOffensiveRotationsProperty()'s docblocks), built 2026-08-22,
 * direct request: "nearly all crowd control is tagged in our database but setups and chains
 * don't exist anywhere... I want to look at the top chains by duration length to see if we can
 * develop a formula that follows a similar decision-making system to what the players are
 * doing." Same underlying idea as the DPS work — filter long combat logs down to the specific
 * windows that matter — applied to CC instead of damage, made possible by the same
 * SPELL_AURA_APPLIED/REFRESH/REMOVED event pairing FindCcDuration already uses for single-spell
 * duration analysis, extended here to MERGE overlapping/near-consecutive real intervals across
 * however many different abilities/casters contributed, which is the actual "how do we calculate
 * time in CC" mechanism the request asked for.
 *
 * DEFINITIONS
 * - "Healer": one of ArenaLogService::HEALER_SPEC_SLUGS' 7 specs (exposed via the new
 *   isHealerSpec() public method, added alongside this command so the classification isn't
 *   duplicated a third time — analyzeKillCausally() and WowComps::SPEC_ROLES each already have
 *   their own version for their own purposes).
 * - "CC": any spell_id with a non-null dr_category for the current patch — the same, already-
 *   hand-curated definition used everywhere else in this project (CcChainBuilder, WowComps'
 *   Synergies tab, analyzeKillCausally()'s own $ccByCategory). Nothing new is inferred here.
 * - "Opposing only": a CC application only counts if source and destination units have different
 *   `reaction` values (the metadata's own Friendly/Hostile field, relative to whichever client
 *   recorded the match) — this naturally excludes self-CC and any same-team noise without
 *   needing to know actual team names.
 * - "Chain": one or more real APPLIED(or REFRESH)->REMOVED intervals on the same healer, merged
 *   together whenever the next interval starts no more than --gap seconds after the current
 *   merged window's END (not the previous single interval's own end — a window can be extended
 *   by an interval that started while an earlier one was still active, e.g. a Stun landing while
 *   a Fear is still ticking). The reported "time in CC" is this merged window's own span
 *   (last end - first start) — the union of real intervals, not a naive sum of individual
 *   durations, which would double-count any overlap.
 * - A REFRESH mid-window resets that spell's own start time to the refresh's own timestamp,
 *   exactly matching FindCcDuration's already-established convention (remaining duration resets
 *   to full in-game, not additive) — see that command's docblock for the full reasoning.
 *
 * Real, in-game DR is already baked into the raw timings themselves (Blizzard's server enforces
 * it before the log is ever written), so no separate DR-percentage math is needed for
 * correctness — but each step's txt output is still annotated "(DR'd)" when its own dr_category
 * already appeared earlier in the SAME chain, since knowing a stun was probably at 50% duration
 * is directly useful context for the requested "reverse-engineer the decision" reading.
 *
 * OUTPUT: one txt file per healer spec (data/arena-logs/cc-chains/{class}/{spec}.txt) — chosen
 * over one single combined file to mirror the DPS pipeline's own per-spec structure ("roughly
 * the same data we stored when running dps analysis" was per-spec, not one global file); plain
 * text rather than JSON since this is explicitly for direct human reading, not another live-app
 * consumer. Each file lists its spec's longest chains, longest first, with the full ordered
 * ability sequence, timing offsets, real durations, and which class/spec cast each piece.
 * --json (added 2026-08-23) additionally writes a {spec}.json with every field the txt only
 * renders as text — spellId, sourceClassSlug/sourceSpecSlug/sourceIsHealer — for programmatic
 * pattern analysis that needs to join on the real spell_id rather than fuzzy-match a display
 * name (which silently picks the wrong copy whenever an ability has duplicate spell_id
 * records — the exact imprecision a same-day cast_type analysis ran into before this existed).
 *
 * Usage: php artisan wow:find-cc-chains
 * Usage: php artisan wow:find-cc-chains --gap=1 --min-abilities=3 --top=10
 */
class FindCcChains extends Command
{
    /**
     * The 4 dr_category values that actually diminish each other and represent a genuine
     * lockdown decision (matches WowComps::GROUP_CATEGORIES' "Diminishing Returns Groups"
     * bucket exactly, same reasoning). Deliberately EXCLUDES Knockback/Disarm/Slow/Root by
     * default — a first real run against the full archive surfaced why: the #1 "chain" it found
     * was 116s long and almost entirely Consecration/Chilled-type Slow effects overlapping for
     * the length of an entire fight, which is incidental low-commitment uptime, not the
     * deliberate "how do teams decide to chain-lock a healer" mechanic this command exists to
     * study. --include-utility opts back into the full 8-category set for anyone who wants that
     * broader (much noisier) picture too.
     */
    private const HARD_CC_CATEGORIES = ['Stun', 'Silence', 'Incapacitate', 'Disorient'];

    protected $signature = 'wow:find-cc-chains
        {--gap=2.0 : Max seconds between one CC ending and the next starting to still count as one continuous chain}
        {--min-abilities=2 : Minimum CC applications a merged window must have to be reported as a chain}
        {--top=20 : How many of the longest chains to keep per healer spec}
        {--include-utility : Also count Knockback/Disarm/Slow/Root — off by default, see HARD_CC_CATEGORIES\' docblock for why}
        {--json : Also write a {spec}.json alongside the .txt, preserving every field the txt only renders as text (spellId, caster classSlug/specSlug/isHealer) — added 2026-08-23 so pattern analysis can join on real spell_id instead of fuzzy-matching display names, which silently picks the wrong copy whenever an ability has duplicate spell_id records (the exact imprecision that motivated this flag). Exports every chain that passed --min-abilities, not just the --top slice shown in the txt.}';

    protected $description = 'Scan every locally-stored arena log for the longest continuous CC chains landing on real healers, saved per healer spec for cooldown/setup-formula study';

    public function handle(ArenaLogService $arenaLogService): int
    {
        $patch = Patch::where('is_current', true)->first();

        if (!$patch) {
            $this->error('No current patch on file.');

            return self::FAILURE;
        }

        // spell_id => dr_category, the same already-curated CC definition used everywhere else
        // in this project (CcChainBuilder, WowComps' Synergies tab, analyzeKillCausally()) —
        // narrowed to the 4 hard-CC categories by default, see HARD_CC_CATEGORIES' docblock.
        $includeUtility = (bool) $this->option('include-utility');
        $ccSpells = Spell::where('patch_id', $patch->id)
            ->whereNotNull('dr_category')
            ->when(!$includeUtility, fn ($q) => $q->whereIn('dr_category', self::HARD_CC_CATEGORIES))
            ->get();
        $ccByCategory = $ccSpells->pluck('dr_category', 'spell_id')->all();

        // Real English ability name from our own spells table, keyed by spell_id — NOT the raw
        // combat-log text captured per match (which reflects whatever locale that specific
        // match's client was running, e.g. real Chinese text on a CN-recorded match). Every
        // spell_id here is guaranteed to exist in our own DB already (that's how it got into
        // $ccByCategory), so this always resolves — unlike the rotation pipeline's own name
        // fallback, which genuinely needs the raw log text for spell_ids outside our imported
        // data entirely (racials/trinkets). Player/realm names are untouched — those are real
        // names, not a translation problem.
        $namesBySpellId = $ccSpells->mapWithKeys(fn (Spell $s) => [$s->spell_id => $s->display_name])->all();

        $gapTolerance = (float) $this->option('gap');
        $minAbilities = (int) $this->option('min-abilities');
        $topN = (int) $this->option('top');
        $writeJson = (bool) $this->option('json');

        $files = glob(config('arena_logs.archive_path').'/raw/*.log.gz');

        if ($files === []) {
            $this->error('No arena logs on file — run wow:fetch-arena-log or a wow:pull-* command first.');

            return self::FAILURE;
        }

        $this->info('Scanning '.count($files).' match(es) for CC chains landing on real healers (gap tolerance '.$gapTolerance.'s, min '.$minAbilities.' abilities)...');

        // "classSlug|specSlug" => list of chain records
        $chainsBySpec = [];
        $matchesScanned = 0;
        $specCache = [];

        foreach ($files as $file) {
            $matchId = basename($file, '.log.gz');
            $metaPath = $arenaLogService->metadataPath($matchId);

            if (!File::exists($metaPath)) {
                continue;
            }

            $meta = json_decode(File::get($metaPath), true);
            $roster = $this->buildRoster($meta['units'] ?? [], $arenaLogService, $specCache);

            $healerGuids = array_keys(array_filter($roster, fn ($r) => $r['isHealer']));

            if ($healerGuids === []) {
                continue;
            }

            $raw = gzdecode(File::get($file));
            $intervalsByHealer = $this->extractCcIntervals($raw, $roster, $ccByCategory, $namesBySpellId);

            if ($intervalsByHealer === []) {
                continue;
            }

            $matchesScanned++;

            foreach ($intervalsByHealer as $healerGuid => $intervals) {
                usort($intervals, fn ($a, $b) => $a['start'] <=> $b['start']);
                $windows = $this->mergeIntoChains($intervals, $gapTolerance);
                $healerInfo = $roster[$healerGuid];

                if (!$healerInfo['classSlug'] || !$healerInfo['specSlug']) {
                    continue;
                }

                foreach ($windows as $chain) {
                    if (count($chain['steps']) < $minAbilities) {
                        continue;
                    }

                    $key = "{$healerInfo['classSlug']}|{$healerInfo['specSlug']}";
                    $chainsBySpec[$key][] = [
                        'matchId' => $matchId,
                        'healerName' => $healerInfo['name'],
                        'durationSeconds' => round($chain['end'] - $chain['start'], 2),
                        'distinctCasters' => count(array_unique(array_column($chain['steps'], 'source'))),
                        'steps' => $chain['steps'],
                    ];
                }
            }
        }

        if ($chainsBySpec === []) {
            $this->warn("Scanned {$matchesScanned} match(es) with a real healer on file — no chain met --min-abilities={$minAbilities}. Try lowering it or widening --gap.");

            return self::SUCCESS;
        }

        $this->writeReports($chainsBySpec, $topN, $gapTolerance, $minAbilities, $includeUtility, $writeJson);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $units
     * @param  array<string, ?Specialization>  $specCache  keyed by external_spec_id, memoized across matches
     * @return array<string, array{name: string, reaction: mixed, classSlug: ?string, specSlug: ?string, label: ?string, isHealer: bool}>
     */
    private function buildRoster(array $units, ArenaLogService $arenaLogService, array &$specCache): array
    {
        $roster = [];

        foreach ($units as $u) {
            if (!str_starts_with($u['id'] ?? '', 'Player-') || !isset($u['spec']) || (int) $u['spec'] === 0) {
                continue;
            }

            $extSpecId = (int) $u['spec'];

            if (!array_key_exists($extSpecId, $specCache)) {
                $specCache[$extSpecId] = Specialization::with('gameClass')->where('external_spec_id', $extSpecId)->first();
            }

            $spec = $specCache[$extSpecId];
            $classSlug = $spec?->gameClass?->slug;
            $specSlug = $spec?->slug;

            $roster[$u['id']] = [
                'name' => $u['name'] ?? $u['id'],
                'reaction' => $u['reaction'] ?? null,
                'classSlug' => $classSlug,
                'specSlug' => $specSlug,
                'label' => $spec ? "{$spec->name} {$spec->gameClass?->name}" : null,
                'isHealer' => $classSlug && $specSlug && $arenaLogService->isHealerSpec($classSlug, $specSlug),
            ];
        }

        return $roster;
    }

    /**
     * Every real APPLIED(or REFRESH)->REMOVED CC interval landing on a real healer from the
     * opposing side, grouped by the healer's own guid. Deliberately does not merge here — that's
     * mergeIntoChains()'s job, kept separate so the gap-tolerance rule stays in one place.
     *
     * @param  array<string, array<string, mixed>>  $roster
     * @param  array<int, string>  $ccByCategory  spell_id => dr_category
     * @param  array<int, string>  $namesBySpellId  spell_id => real English display name
     * @return array<string, array<int, array{start: float, end: float, name: string, spellId: int, drCategory: string, source: string}>>
     */
    private function extractCcIntervals(string $raw, array $roster, array $ccByCategory, array $namesBySpellId): array
    {
        // The trailing quoted name (capture group 6) is only ever used as a last-resort fallback
        // below — the raw combat-log text reflects whatever locale that specific match's client
        // was running, so it's never trusted as the primary source of an ability's display name.
        $pattern = '/^([\d\/: .-]+)\s+(SPELL_AURA_APPLIED|SPELL_AURA_REFRESH|SPELL_AURA_REMOVED),(Player-[^,]+),"[^"]*",[^,]*,[^,]*,(Player-[^,]+),"[^"]*",[^,]*,[^,]*,(\d+),"([^"]*)"/m';
        preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            return [];
        }

        $openWindows = []; // "dest|spellId|source" => start ts
        $intervalsByHealer = [];

        foreach ($matches as $m) {
            $spellId = (int) $m[5];

            if (!isset($ccByCategory[$spellId])) {
                continue;
            }

            $dest = $m[4];

            if (!isset($roster[$dest]) || !$roster[$dest]['isHealer']) {
                continue;
            }

            $source = $m[3];

            if (!isset($roster[$source])) {
                continue;
            }

            // Opposing-side only — excludes self-CC and same-team noise without needing to know
            // real team names, same trick analyzeKillCausally()'s own roster build relies on.
            if (($roster[$source]['reaction'] ?? null) === ($roster[$dest]['reaction'] ?? null)) {
                continue;
            }

            $event = $m[2];
            $key = "{$dest}|{$spellId}|{$source}";

            if ($event === 'SPELL_AURA_APPLIED' || $event === 'SPELL_AURA_REFRESH') {
                $openWindows[$key] = $this->parseTimestamp($m[1]);

                continue;
            }

            if ($event === 'SPELL_AURA_REMOVED' && isset($openWindows[$key])) {
                $intervalsByHealer[$dest][] = [
                    'start' => $openWindows[$key],
                    'end' => $this->parseTimestamp($m[1]),
                    'name' => $namesBySpellId[$spellId] ?? $m[6],
                    'spellId' => $spellId,
                    'drCategory' => $ccByCategory[$spellId],
                    'source' => $roster[$source]['label'] ?? $roster[$source]['name'],
                    'sourceClassSlug' => $roster[$source]['classSlug'],
                    'sourceSpecSlug' => $roster[$source]['specSlug'],
                    'sourceIsHealer' => $roster[$source]['isHealer'],
                ];
                unset($openWindows[$key]);
            }
        }

        return $intervalsByHealer;
    }

    /**
     * Merges pre-sorted (by start) real intervals into continuous "time in CC" windows. Two
     * intervals join the same chain when the next one starts no more than $gapTolerance seconds
     * after the running merged window's END — using the merged end (not the single previous
     * interval's own end) so a later-starting-but-longer overlap (e.g. a Stun landing partway
     * through an already-ticking Fear) correctly extends the window instead of being compared
     * against a stale, already-superseded end time.
     *
     * @param  array<int, array{start: float, end: float, name: string, spellId: int, drCategory: string, source: string}>  $intervals
     * @return array<int, array{start: float, end: float, steps: array}>
     */
    private function mergeIntoChains(array $intervals, float $gapTolerance): array
    {
        $chains = [];
        $current = null;

        foreach ($intervals as $iv) {
            if ($current === null) {
                $current = ['start' => $iv['start'], 'end' => $iv['end'], 'steps' => [$iv]];

                continue;
            }

            if ($iv['start'] <= $current['end'] + $gapTolerance) {
                $current['end'] = max($current['end'], $iv['end']);
                $current['steps'][] = $iv;
            } else {
                $chains[] = $current;
                $current = ['start' => $iv['start'], 'end' => $iv['end'], 'steps' => [$iv]];
            }
        }

        if ($current !== null) {
            $chains[] = $current;
        }

        return $chains;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $chainsBySpec
     */
    private function writeReports(array $chainsBySpec, int $topN, float $gapTolerance, int $minAbilities, bool $includeUtility, bool $writeJson): void
    {
        $outDir = base_path('data/arena-logs/cc-chains');
        File::ensureDirectoryExists($outDir);
        $categoryScope = $includeUtility
            ? 'all 8 dr_categories (Stun/Silence/Incapacitate/Disorient/Knockback/Disarm/Slow/Root)'
            : implode('/', self::HARD_CC_CATEGORIES).' only (--include-utility widens this)';

        foreach ($chainsBySpec as $key => $chains) {
            [$classSlug, $specSlug] = explode('|', $key);
            usort($chains, fn ($a, $b) => $b['durationSeconds'] <=> $a['durationSeconds']);
            $top = array_slice($chains, 0, $topN);

            $dir = "{$outDir}/{$classSlug}";
            File::ensureDirectoryExists($dir);
            $path = "{$dir}/{$specSlug}.txt";

            $lines = [];
            $lines[] = "# CC chains landing on real {$classSlug}/{$specSlug} healers (opposing side only)";
            $lines[] = '# Total qualifying chains found: '.count($chains).' | shown here: '.count($top);
            $lines[] = "# Gap tolerance: {$gapTolerance}s | Min abilities per chain: {$minAbilities} | Categories: {$categoryScope}";
            $lines[] = '# "(DR\'d)" marks a step whose dr_category already appeared earlier in the same chain — real in-game DR is already baked into its own timing, this is just a readability flag.';
            $lines[] = '';

            foreach ($top as $i => $chain) {
                $num = $i + 1;
                $lines[] = "=== #{$num} — {$chain['durationSeconds']}s total time-in-CC — {$chain['distinctCasters']} caster(s) — match {$chain['matchId']} — target: {$chain['healerName']} ===";

                $seenCategories = [];
                $chainStart = $chain['steps'][0]['start'];

                foreach ($chain['steps'] as $step) {
                    $offset = round($step['start'] - $chainStart, 2);
                    $duration = round($step['end'] - $step['start'], 2);
                    $drdFlag = isset($seenCategories[$step['drCategory']]) ? ' (DR\'d)' : '';
                    $seenCategories[$step['drCategory']] = true;

                    $lines[] = sprintf(
                        '  [+%6.2fs] %-24s (%-13s)%s  %5.2fs  by %s',
                        $offset,
                        $step['name'],
                        $step['drCategory'],
                        $drdFlag,
                        $duration,
                        $step['source']
                    );
                }

                $lines[] = '';
            }

            File::put($path, implode("\n", $lines));
            $this->info("Wrote {$path} — ".count($top)." chain(s), longest: {$top[0]['durationSeconds']}s");

            if ($writeJson) {
                $jsonPath = "{$dir}/{$specSlug}.json";
                File::put($jsonPath, json_encode(array_values($chains), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->info("Wrote {$jsonPath} — ".count($chains)." chain(s) (full set, not just the --top slice)");
            }
        }
    }

    private function parseTimestamp(string $raw): float
    {
        // Same date-agnostic time-of-day parse as FindCcDuration — only relative diffs within
        // one match matter, and no match in this project's data runs past midnight.
        if (!preg_match('/(\d{1,2}):(\d{2}):(\d{2})\.(\d+)/', trim($raw), $m)) {
            return 0.0;
        }

        return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3] + ((float) ('0.'.$m[4]));
    }
}
