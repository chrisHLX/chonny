<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Tests a specific, falsifiable claim (direct user request, 2026-08-29): "higher-rated DPS
 * players press most of their offensive kit in the opener, and lower-rated players don't."
 * Pulls real matches from wowarenalogs.com via ArenaLogService (not simulated/guessed data) at
 * two rating tiers for one DPS spec, resolves each target player's REAL selected talent build
 * from that match's own COMBATANT_INFO line, and checks — against the project's own
 * arena-log-verified offensive/defensive classification — what fraction of that player's real
 * available offensive kit got cast within the first `--opener` seconds of the match.
 *
 * "Offensive kit" = the player's own resolved PvE talents + PvP talents, filtered to spells the
 * classify-cooldowns.php-derived offensive-spells.json/offensive-buffs.json/mixed-cooldowns.json
 * files (loaded via ArenaLogService::offensiveDefensiveClassification()) mark offensive. Not a
 * guess at "what should be offensive" — reuses the same real-data-verified classification the
 * rest of this project already trusts for Offensive/Defensive cooldown tabs.
 *
 * "Opener" = the match's own earliest logged timestamp to +$opener seconds (default 10) — see
 * ArenaLogService::findOpenerWindow()'s own docblock for why the log's first line is a reliable
 * match-start anchor.
 *
 * Healer specs are refused outright (isHealerSpec()) — the theory is specifically about DPS.
 *
 * This is a one-off analysis tool, not a page/feature — output is a console report only,
 * nothing is persisted or promoted anywhere.
 */
class TestOpenerTheory extends Command
{
    protected $signature = 'wow:test-opener-theory
        {classSlug : e.g. priest}
        {specSlug : e.g. shadow}
        {--bracket=3v3}
        {--pages=4 : search pages to scan per rating tier (50 matches per page)}
        {--top=8 : how many high-rated matches to pull}
        {--low=8 : how many low-rated matches to pull}
        {--ceiling=2100 : low-tier rating ceiling}
        {--floor=0 : low-tier rating floor}
        {--opener=10 : opener window length in seconds}';

    protected $description = 'Tests whether higher-rated DPS players press more of their available offensive kit in the opener than lower-rated players, using real pulled arena matches.';

    public function handle(ArenaLogService $service): int
    {
        $classSlug = $this->argument('classSlug');
        $specSlug = $this->argument('specSlug');
        $bracket = $this->option('bracket');
        $opener = (int) $this->option('opener');

        $class = GameClass::where('slug', $classSlug)->first();

        if (!$class) {
            $this->error("Unknown class slug: {$classSlug}");

            return self::FAILURE;
        }

        $spec = Specialization::where('class_id', $class->id)->where('slug', $specSlug)->first();

        if (!$spec) {
            $this->error("Unknown spec slug: {$specSlug} for class {$classSlug}");

            return self::FAILURE;
        }

        if ($service->isHealerSpec($classSlug, $specSlug)) {
            $this->error("{$class->name} {$spec->name} is a healer spec — this theory is specifically about DPS players.");

            return self::FAILURE;
        }

        if (!$spec->external_spec_id) {
            $this->error("{$class->name} {$spec->name} has no external_spec_id on file — can't query wowarenalogs.com for it.");

            return self::FAILURE;
        }

        $externalId = (int) $spec->external_spec_id;
        $pages = (int) $this->option('pages');

        $this->info("Pulling high-rated matches for {$class->name} {$spec->name} ({$bracket})...");
        $top = $service->pullTopMatchesForSpec($externalId, $bracket, $pages, (int) $this->option('top'));
        $this->line('  '.count($top).' candidate(s) found.');

        $this->info('Pulling low-rated matches (rating '.$this->option('floor').'-'.$this->option('ceiling').')...');
        $low = $service->pullLowRatedMatchesForSpec(
            $externalId,
            $bracket,
            $pages,
            (int) $this->option('low'),
            (int) $this->option('ceiling'),
            (int) $this->option('floor')
        );
        $this->line('  '.count($low).' candidate(s) found.');

        $classification = $service->offensiveDefensiveClassification();

        $this->newLine();
        $this->info('--- HIGH-RATED GROUP ---');
        $highResults = $this->analyzeGroup($service, $top, $spec->id, $externalId, $classification, $opener);

        $this->newLine();
        $this->info('--- LOW-RATED GROUP ---');
        $lowResults = $this->analyzeGroup($service, $low, $spec->id, $externalId, $classification, $opener);

        $this->printSummary($highResults, $lowResults, $opener);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{matchId: string, rating: int, kitAvailable: int, kitPressed: int, openerCastCount: int}>
     */
    private function analyzeGroup(ArenaLogService $service, array $matches, int $internalSpecId, int $externalSpecId, array $classification, int $opener): array
    {
        $results = [];

        foreach ($matches as $m) {
            $matchId = $m['matchId'];

            if ($m['status'] === 'fetch_failed') {
                $this->line("  {$matchId}: fetch failed, skipping");

                continue;
            }

            $analysis = $this->analyzeMatch($service, $matchId, $internalSpecId, $externalSpecId, $classification, $opener);

            if ($analysis === null) {
                $this->line("  {$matchId}: could not resolve a target-spec player's build/casts, skipping");

                continue;
            }

            if ($analysis['kitAvailable'] === 0) {
                $this->line("  {$matchId}: rating={$m['rating']} — no classified offensive kit resolved for this build, skipping");

                continue;
            }

            $pct = $analysis['kitPressed'] / $analysis['kitAvailable'] * 100;
            $results[] = array_merge($analysis, ['matchId' => $matchId, 'rating' => $m['rating']]);

            $this->line(sprintf(
                '  %s rating=%d  kit=%d/%d (%.0f%%)  total opener casts=%d',
                $matchId,
                $m['rating'],
                $analysis['kitPressed'],
                $analysis['kitAvailable'],
                $pct,
                $analysis['openerCastCount']
            ));
        }

        return $results;
    }

    /**
     * @return array{kitAvailable: int, kitPressed: int, openerCastCount: int}|null
     */
    private function analyzeMatch(ArenaLogService $service, string $matchId, int $internalSpecId, int $externalSpecId, array $classification, int $opener): ?array
    {
        $metaPath = $service->metadataPath($matchId);

        if (!File::exists($metaPath)) {
            return null;
        }

        $meta = json_decode(File::get($metaPath), true);

        $unit = collect($meta['units'] ?? [])
            ->first(fn ($u) => str_starts_with($u['id'], 'Player-') && (int) ($u['spec'] ?? 0) === $externalSpecId);

        if (!$unit) {
            return null;
        }

        $combatantInfo = $service->extractCombatantInfo($matchId, $unit['id']);

        if ($combatantInfo === null) {
            return null;
        }

        $resolved = $service->resolveCombatantTalents($combatantInfo, $internalSpecId);

        $allPicked = array_merge($resolved['talents'], $resolved['pvpTalents']);

        $availableOffensiveSpellIds = collect($allPicked)
            ->pluck('spellId')
            ->unique()
            ->filter(fn ($spellId) => $classification['bySpellId'][$spellId]['offensive'] ?? false)
            ->values();

        if ($availableOffensiveSpellIds->isEmpty()) {
            return ['kitAvailable' => 0, 'kitPressed' => 0, 'openerCastCount' => 0];
        }

        $openerWindow = $service->findOpenerWindow($matchId, $unit['id'], $opener);

        if ($openerWindow === null) {
            return null;
        }

        $castSpellIds = collect($openerWindow['casts'])->pluck('spellId')->unique();

        $pressed = $availableOffensiveSpellIds->filter(fn ($spellId) => $castSpellIds->contains($spellId));

        return [
            'kitAvailable' => $availableOffensiveSpellIds->count(),
            'kitPressed' => $pressed->count(),
            'openerCastCount' => $castSpellIds->count(),
        ];
    }

    private function printSummary(array $high, array $low, int $opener): void
    {
        $this->newLine();
        $this->info("=== SUMMARY (opener window: {$opener}s) ===");

        foreach (['HIGH-rated' => $high, 'LOW-rated' => $low] as $label => $group) {
            if ($group === []) {
                $this->line("{$label}: no analyzable matches.");

                continue;
            }

            $ratios = array_map(fn ($r) => $r['kitPressed'] / $r['kitAvailable'], $group);
            $avgPct = array_sum($ratios) / count($ratios) * 100;
            sort($ratios);
            $medianPct = $ratios[(int) floor(count($ratios) / 2)] * 100;
            $ratings = array_map(fn ($r) => $r['rating'], $group);

            $this->line(sprintf(
                '%s: n=%d, rating range %d-%d, mean %% of offensive kit pressed in opener = %.1f%%, median = %.1f%%',
                $label,
                count($group),
                min($ratings),
                max($ratings),
                $avgPct,
                $medianPct
            ));
        }

        if ($high !== [] && $low !== []) {
            $highAvg = array_sum(array_map(fn ($r) => $r['kitPressed'] / $r['kitAvailable'], $high)) / count($high) * 100;
            $lowAvg = array_sum(array_map(fn ($r) => $r['kitPressed'] / $r['kitAvailable'], $low)) / count($low) * 100;
            $diff = $highAvg - $lowAvg;

            $this->newLine();
            $this->line(sprintf('Difference (HIGH mean - LOW mean): %+.1f percentage points', $diff));
        }
    }
}
