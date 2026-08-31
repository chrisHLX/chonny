<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Pulls more high-MMR matches specifically for specs that currently have the FEWEST real
 * examples on file, rather than an even/blind sweep — built 2026-08-15 on direct user request
 * ("pull more example of higher MMR matches that we don't already have, prioritising on
 * classes who have the least examples").
 *
 * "Examples" is measured as the count of records in
 * data/arena-logs/kill-sequences/{classSlug}/{specSlug}.jsonl — i.e. the exact number the
 * WowComps "Kill Sequence" tab displays as its sample-size badge. **Corrected 2026-08-15,
 * same day as first built** — the original version counted any stored match containing that
 * spec's player at all (win or loss), which is NOT what the UI shows: findPreKillWindow()
 * only ever records the WINNING team's sequence, so a spec that lost a stored match
 * contributes zero rows here regardless of how many total matches mention it. Confirmed
 * directly: Balance Druid showed 5 "any-side" matches but only 1 real kill-sequence record —
 * 4 of the 5 were losses. Counting the wrong thing meant the first run of this command left
 * genuinely under-covered specs (by the metric that actually matters) untouched while
 * "already_on_disk"-skipping specs that merely looked well-covered by the wrong measure.
 *
 * Direct consequence of the win-only recording: roughly half of any newly-pulled match will
 * NOT produce a new kill-sequence row for the targeted spec (it may have lost). --top/--pages
 * defaults are raised accordingly from the first version (3/3 → 5/4) to compensate — pulling
 * more candidates per spec to get a comparable net yield of actual winning examples.
 *
 * Uses ArenaLogService::pullTopMatchesForSpec() per targeted spec — already respects
 * MIN_MATCH_DURATION_SECONDS (the "not just high MMR, also decent duration" floor added after
 * the 18-second-match incident) and sorts candidates by rating descending before taking the
 * top N, so this pulls the highest-rated AND long-enough-to-be-useful matches available in the
 * scanned window, same as every other match-pulling command in this codebase.
 *
 * Every newly-fetched match has ALL its real players extracted into spell-usage files (not
 * just the one targeted spec — free coverage for whichever other 5 players were in that
 * match), same behavior as wow:pull-missing-cc-matches. Kill-sequence records are regenerated
 * for the full match set at the end (idempotent, skips already-recorded matches) so the new
 * matches immediately feed the Kill Sequence tab too.
 *
 * Note on tank specs (2026-08-15, direct user instruction): Monk/Brewmaster, Death
 * Knight/Blood, and Demon Hunter/Vengeance were confirmed genuinely scarce-to-absent in this
 * data source even at --pages=8 (400 recent 3v3 matches scanned) — see arena-log-api.md's
 * "Tank specs are genuinely scarce" section for the full trace. Decision: don't keep spending
 * search budget deliberately chasing these three; they'll surface naturally as incidental
 * byproducts of other specs' pulls (every match's full roster gets extracted regardless of
 * which spec was targeted) if/when tanks actually get played in this bracket.
 *
 * Usage: php artisan wow:pull-scarce-specs --min-count=8 --top=5 --pages=4
 *
 * Any new matches this adds to the archive leave every match-derived live surface (Burst
 * Windows, mechanics, Crowd Control, Class Guide) stale until refreshed — run
 * `php artisan wow:refresh-match-derived` afterward. See that command's own docblock.
 */
class PullScarceSpecs extends Command
{
    protected $signature = 'wow:pull-scarce-specs
        {--bracket=3v3}
        {--min-count=8 : Pull for any spec with fewer than this many recorded kill-sequence examples}
        {--top=5 : How many highest-rated matches to pull per targeted spec}
        {--pages=4 : Search depth per spec (offset-paged, 50 per page)}';

    protected $description = 'Pull more high-rated matches for the specs with the fewest kill-sequence examples on file';

    public function handle(ArenaLogService $service): int
    {
        $minCount = (int) $this->option('min-count');
        $bracket = $this->option('bracket');
        $topN = (int) $this->option('top');
        $pages = (int) $this->option('pages');

        $counts = [];
        foreach (\App\Models\Specialization::with('gameClass')->get() as $s) {
            if (!$s->gameClass) {
                continue;
            }
            $path = base_path("data/arena-logs/kill-sequences/{$s->gameClass->slug}/{$s->slug}.jsonl");
            $counts[$s->external_spec_id] = File::exists($path) ? count(File::lines($path)->filter()) : 0;
        }

        $specs = \App\Models\Specialization::with('gameClass')->get()
            ->filter(fn ($s) => $s->gameClass !== null)
            ->map(fn ($s) => [
                'spec' => $s,
                'count' => $counts[$s->external_spec_id] ?? 0,
            ])
            ->filter(fn ($row) => $row['count'] < $minCount)
            ->sortBy('count')
            ->values();

        if ($specs->isEmpty()) {
            $this->info("Every spec already has at least {$minCount} kill-sequence examples. Nothing to pull.");

            return self::SUCCESS;
        }

        $this->info("{$specs->count()} spec(s) below {$minCount} examples, scarcest first:");
        foreach ($specs as $row) {
            $this->line("  {$row['count']}  {$row['spec']->gameClass->name} / {$row['spec']->name}");
        }
        $this->newLine();

        $newMatchIds = [];
        $summary = ['stored' => 0, 'already_on_disk' => 0, 'fetch_failed' => 0];

        foreach ($specs as $row) {
            $spec = $row['spec'];
            $label = "{$spec->gameClass->name} / {$spec->name}";
            $this->info("=== {$label} (currently {$row['count']} example(s)) ===");

            $results = $service->pullTopMatchesForSpec($spec->external_spec_id, $bracket, $pages, $topN);

            if ($results === []) {
                $this->warn('  No matches found in the scanned window (spec may be genuinely rare in this bracket).');

                continue;
            }

            foreach ($results as $r) {
                $this->line("  {$r['matchId']} ({$r['rating']} rating) — {$r['status']}");
                $summary[$r['status']]++;

                if ($r['status'] === 'stored') {
                    $newMatchIds[$r['matchId']] = true;
                }
            }
        }

        $this->newLine();
        $this->info("Fetch summary: {$summary['stored']} newly stored, {$summary['already_on_disk']} already on disk, {$summary['fetch_failed']} failed.");

        if ($newMatchIds === []) {
            $this->info('No new matches to extract from.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Extracting all real players from '.count($newMatchIds).' new match(es)...');

        foreach (array_keys($newMatchIds) as $matchId) {
            $players = $service->extractCastSpellsByPlayer($matchId);

            foreach ($players as $player) {
                if ($player['specId'] === null) {
                    continue;
                }

                $class = GameClass::find($player['classId']);
                $spec = \App\Models\Specialization::find($player['specId']);
                $service->mergeSpellUsage($class->slug, $spec->slug, $matchId, $player['spells']);
            }

            $this->line("  {$matchId}: ".count($players).' real player(s) extracted.');
        }

        $this->newLine();
        $this->info('Regenerating kill-sequence records for the full match set (idempotent)...');
        $this->call('wow:record-kill-sequences', ['--all' => true]);

        return self::SUCCESS;
    }
}
