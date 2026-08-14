<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use Illuminate\Console\Command;

/**
 * Runs ArenaLogService::recordKillSequence() — either for one match or every match currently
 * on file — persisting each winning real player's pre-kill sequence (with winning/losing comp
 * and kill-target context) into data/arena-logs/kill-sequences/{classSlug}/{specSlug}.jsonl.
 *
 * Safe to re-run at any time (e.g. after pulling new matches via any of the other wow:*
 * arena-log commands) — recordKillSequence() is idempotent per (matchId, playerSpec), so
 * already-recorded matches are skipped, not duplicated.
 *
 * Usage:
 *   php artisan wow:record-kill-sequences --all
 *   php artisan wow:record-kill-sequences --matchId=141d49b74f206a4da3c77720ff88ccdc
 */
class RecordKillSequences extends Command
{
    protected $signature = 'wow:record-kill-sequences
        {--matchId= : Record just this one match}
        {--all : Record every match currently on file}
        {--window=20 : Seconds before the kill to capture}';

    protected $description = 'Persist pre-kill sequences (with comp + kill-target context) into data/arena-logs/kill-sequences/';

    public function handle(ArenaLogService $service): int
    {
        $window = (int) $this->option('window');

        if ($this->option('matchId')) {
            $matchIds = [$this->option('matchId')];
        } elseif ($this->option('all')) {
            $matchIds = array_map(fn ($f) => basename($f, '.log.gz'), glob(base_path('data/arena-logs/raw/*.log.gz')));
        } else {
            $this->error('Pass --matchId=<id> or --all.');

            return self::FAILURE;
        }

        $this->info('Processing '.count($matchIds).' match(es)...');

        $totalRecorded = 0;
        $totalAlready = 0;
        $totalSkipped = 0;

        foreach ($matchIds as $matchId) {
            $result = $service->recordKillSequence($matchId, $window);

            if ($result['recorded'] === 0 && $result['alreadyPresent'] === 0) {
                $totalSkipped++;

                continue;
            }

            $totalRecorded += $result['recorded'];
            $totalAlready += $result['alreadyPresent'];

            if ($result['recorded'] > 0) {
                $this->line("  {$matchId}: recorded {$result['recorded']} player sequence(s)".($result['alreadyPresent'] > 0 ? ", {$result['alreadyPresent']} already present" : ''));
            }
        }

        $this->newLine();
        $this->info("Done: {$totalRecorded} new sequence(s) recorded, {$totalAlready} already present, {$totalSkipped} match(es) had no usable death/window.");

        return self::SUCCESS;
    }
}
