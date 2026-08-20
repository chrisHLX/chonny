<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use Illuminate\Console\Command;

/**
 * Given a comp (a set of spec IDs — e.g. the same 3 specs a WowComps slot picker would
 * produce), searches wowarenalogs.com for the highest-rated recent WIN by that exact comp
 * and fetches+stores it under data/arena-logs/, but only if it's better than whatever is
 * already on file for that comp signature — see ArenaLogService::pullBestWinForComp() for
 * the actual logic (search → verify each result's real winning-team spec set against the
 * query, not just trust the filter → compare against data/arena-logs/comp-index.json →
 * fetch+store only on a genuine improvement).
 *
 * First pass, deliberately scoped narrow: one exact comp, best win regardless of opponent,
 * command-only (not wired into the live WowComps Livewire page — that would mean touching a
 * real, already-shipped page and triggering a third-party HTTP search on a live user
 * interaction, both worth a separate explicit decision). "Find one example of this comp
 * against every distinct opposing comp" is a stated future step, not built here.
 *
 * Usage:
 *   php artisan wow:pull-comp-log 256,64,261
 *   php artisan wow:pull-comp-log 256,64,261 --bracket=3v3 --count=50
 */
class PullCompArenaLog extends Command
{
    protected $signature = 'wow:pull-comp-log
        {specs : Comma-separated Blizzard spec IDs, e.g. 256,64,261 for Disc Priest/Frost Mage/Sub Rogue}
        {--bracket=3v3 : Bracket string as wowarenalogs.com reports it, e.g. 3v3, 2v2, "Rated Solo Shuffle"}
        {--count=50 : How many recent matches to search — there is no server-side rating sort, so this bounds how far back "highest recent rating" looks}';

    protected $description = 'Search for the highest-rated recent win by an exact comp and store it, only if better than what is already on file';

    public function handle(ArenaLogService $service): int
    {
        $specIds = array_map('intval', explode(',', $this->argument('specs')));

        if (count($specIds) < 2) {
            $this->error('Provide at least 2 comma-separated spec IDs.');

            return self::FAILURE;
        }

        $bracket = $this->option('bracket');
        $count = (int) $this->option('count');

        $this->info('Searching for comp ['.implode(', ', $specIds)."] wins in {$bracket}, scanning the latest {$count} matches...");

        $result = $service->pullBestWinForComp($specIds, $bracket, $count);

        switch ($result['status']) {
            case 'no_match_found':
                $this->warn("No recent win found for this exact comp in {$bracket} (searched the latest {$count} matches). Not a failure — this comp may just not have appeared recently; try a larger --count.");

                return self::SUCCESS;

            case 'already_best':
                $this->info("Already have the best known win for this comp: match {$result['matchId']} at {$result['existingRating']} rating (found comp was at {$result['foundRating']}, not higher).");

                return self::SUCCESS;

            case 'fetch_failed':
                $this->error("Found a qualifying match ({$result['matchId']}) but failed to fetch/store it: ".($result['error'] ?? 'unknown error'));

                return self::FAILURE;

            case 'stored_new':
                $prev = $result['previousRating'] !== null ? " (previous best: {$result['previousRating']})" : ' (first time storing this comp)';
                $this->info("Stored match {$result['matchId']} — {$result['rating']} rating{$prev}.");
                $this->line($service->rawLogPath($result['matchId']));
                $this->line($service->metadataPath($result['matchId']));
                $this->line('data/arena-logs/comp-index.json updated.');

                return self::SUCCESS;

            default:
                $this->error('Unexpected result: '.json_encode($result));

                return self::FAILURE;
        }
    }
}
