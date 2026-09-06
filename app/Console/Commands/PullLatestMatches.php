<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Generic "grow the archive with whatever's been played lately that we don't already have"
 * puller — NO comp/spec targeting, unlike wow:pull-scarce-specs / wow:discover-all-specs
 * (spec-targeted) or wow:pull-comp-log (one exact comp).
 *
 * Walks latestMatches(bracket) by offset (0, 50, 100, ...), skips any match whose metadata
 * file already exists in the archive (config('arena_logs.archive_path')/metadata/), and
 * fetch+stores the rest via ArenaLogService::fetchMatch()/storeMatch() until --count NEW
 * matches have landed or the recent-window feed is exhausted / --max-pages is hit.
 *
 * `latestMatches` only searches a recent window with no full-history paging (see
 * arena-log-api.md) — re-run later to pick up more as new matches get uploaded.
 *
 * Fetch-only: writes raw/{id}.log.gz + metadata/{id}.json, nothing else. Run the usual
 * follow-ups afterwards against the enlarged set — e.g.:
 *   php artisan wow:record-kill-sequences --all
 *   php artisan wow:discover-all-specs           (spell-usage extraction + diff)
 *   php artisan wow:refresh-match-derived         (always — see that command's own docblock)
 *
 * READ CLAUDE.md's "Spell Acquisition Model" section before a bulk pull if the archive was
 * recently culled (e.g. the 2026-09-05 removal of the oldest 500 matches, from the first
 * ~2 weeks of the current expansion, judged no longer representative of the settled meta) —
 * that section documents the reasoning and the pattern to follow for any future cull.
 *
 * Usage:
 *   php artisan wow:pull-latest-matches
 *   php artisan wow:pull-latest-matches --count=100 --bracket=3v3
 *   php artisan wow:pull-latest-matches --count=300 --max-age-days=7
 *
 * --max-age-days (added 2026-09-05, direct request: "pull another 300 matches without pulling
 * matches older than a week") filters on each match's own real `startTime` (requested directly
 * from the search feed, ArenaLogService::searchLatestMatches() — confirmed live 2026-09-05 that
 * the API actually returns it on the search stub, not just the full match fetch) — checked
 * BEFORE fetching full match data, so a too-old match never costs an extra API call just to be
 * discarded. The feed is confirmed newest-first (spot-checked live, same date): once an entire
 * 50-match page has zero matches within the age window, everything after it is guaranteed older
 * too, so the scan stops there rather than paging pointlessly through the rest of --max-pages.
 */
class PullLatestMatches extends Command
{
    protected $signature = 'wow:pull-latest-matches
        {--count=100 : How many NEW (not-already-on-disk) matches to fetch}
        {--bracket=3v3}
        {--max-pages=40 : Safety cap on how many 50-match pages of the recent feed to scan}
        {--delay=0 : Seconds to sleep between fetches (courtesy; no rate limit observed)}
        {--max-age-days= : Skip (and eventually stop on) any match older than this many days}';

    protected $description = "Pull the latest bracket matches we don't already have, no comp/spec targeting";

    public function handle(ArenaLogService $service): int
    {
        $target = (int) $this->option('count');
        $bracket = $this->option('bracket');
        $maxPages = (int) $this->option('max-pages');
        $delay = (int) $this->option('delay');
        $maxAgeDays = $this->option('max-age-days') !== null ? (float) $this->option('max-age-days') : null;
        $cutoffMs = $maxAgeDays !== null ? (now()->getTimestampMs() - $maxAgeDays * 86400 * 1000) : null;

        $ageNote = $maxAgeDays !== null ? ", no older than {$maxAgeDays} day(s)" : '';
        $this->info("Pulling up to {$target} new {$bracket} matches (scanning up to {$maxPages} pages of 50{$ageNote})...");
        $this->line('Archive: '.config('arena_logs.archive_path'));
        $this->newLine();

        $new = 0;
        $skipped = 0;
        $tooOld = 0;
        $failed = 0;
        $scanned = 0;

        for ($page = 0; $page < $maxPages && $new < $target; $page++) {
            $combats = $service->searchLatestMatches($bracket, $page * 50, 50);

            if ($combats === []) {
                $this->warn("Page {$page}: feed returned no usable matches — stopping.");
                break;
            }

            $pageHadFreshMatch = false;

            foreach ($combats as $c) {
                if ($new >= $target) {
                    break;
                }

                $scanned++;
                $matchId = $c['matchId'];

                if ($cutoffMs !== null && $c['startTime'] !== null && $c['startTime'] < $cutoffMs) {
                    $tooOld++;

                    continue;
                }

                $pageHadFreshMatch = true;

                if (File::exists($service->metadataPath($matchId))) {
                    $skipped++;

                    continue;
                }

                $match = $service->fetchMatch($matchId);

                if ($match === null) {
                    $failed++;
                    $this->line("  <fg=red>fetch failed</> {$matchId}");

                    continue;
                }

                $stored = $service->storeMatch($matchId, $match);

                if (isset($stored['error'])) {
                    $failed++;
                    $this->line("  <fg=red>store failed</> {$matchId}: {$stored['error']}");

                    continue;
                }

                $new++;
                $this->line("  [{$new}/{$target}] {$matchId}  rating {$c['rating']}  {$c['durationInSeconds']}s");

                if ($delay > 0) {
                    sleep($delay);
                }
            }

            // Feed is newest-first (confirmed live) — a whole page with nothing inside the age
            // window means every later page is guaranteed older too. Stop instead of burning
            // through the rest of --max-pages for nothing.
            if ($cutoffMs !== null && !$pageHadFreshMatch) {
                $this->warn("Page {$page}: no matches within the last {$maxAgeDays} day(s) — recent window exhausted for this cutoff, stopping.");
                break;
            }
        }

        $this->newLine();
        $ageSummary = $maxAgeDays !== null ? ", {$tooOld} skipped (older than {$maxAgeDays}d)" : '';
        $this->info("Done: {$new} new, {$skipped} already on disk{$ageSummary}, {$failed} failed ({$scanned} feed entries scanned).");

        if ($new < $target) {
            $this->warn("Stopped short of {$target} — recent feed exhausted (or the age cutoff) or --max-pages hit. Re-run later for more.");
        }

        if ($new > 0) {
            $this->newLine();
            $this->warn('New matches landed — every live surface derived from match data (Burst Windows, mechanics, Crowd Control, Class Guide) is now stale until you refresh it. Run:');
            $this->line('  php artisan wow:refresh-match-derived');
        }

        return self::SUCCESS;
    }
}
