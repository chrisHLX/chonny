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
 *
 * Usage:
 *   php artisan wow:pull-latest-matches
 *   php artisan wow:pull-latest-matches --count=100 --bracket=3v3
 */
class PullLatestMatches extends Command
{
    protected $signature = 'wow:pull-latest-matches
        {--count=100 : How many NEW (not-already-on-disk) matches to fetch}
        {--bracket=3v3}
        {--max-pages=40 : Safety cap on how many 50-match pages of the recent feed to scan}
        {--delay=0 : Seconds to sleep between fetches (courtesy; no rate limit observed)}';

    protected $description = "Pull the latest bracket matches we don't already have, no comp/spec targeting";

    public function handle(ArenaLogService $service): int
    {
        $target = (int) $this->option('count');
        $bracket = $this->option('bracket');
        $maxPages = (int) $this->option('max-pages');
        $delay = (int) $this->option('delay');

        $this->info("Pulling up to {$target} new {$bracket} matches (scanning up to {$maxPages} pages of 50)...");
        $this->line('Archive: '.config('arena_logs.archive_path'));
        $this->newLine();

        $new = 0;
        $skipped = 0;
        $failed = 0;
        $scanned = 0;

        for ($page = 0; $page < $maxPages && $new < $target; $page++) {
            $combats = $service->searchLatestMatches($bracket, $page * 50, 50);

            if ($combats === []) {
                $this->warn("Page {$page}: feed returned no usable matches — stopping.");
                break;
            }

            foreach ($combats as $c) {
                if ($new >= $target) {
                    break;
                }

                $scanned++;
                $matchId = $c['matchId'];

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
        }

        $this->newLine();
        $this->info("Done: {$new} new, {$skipped} already on disk, {$failed} failed ({$scanned} feed entries scanned).");

        if ($new < $target) {
            $this->warn("Stopped short of {$target} — recent feed exhausted or --max-pages hit. Re-run later for more.");
        }

        return self::SUCCESS;
    }
}
