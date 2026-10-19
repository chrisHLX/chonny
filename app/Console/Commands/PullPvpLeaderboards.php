<?php

namespace App\Console\Commands;

use App\Http\Services\PvpLeaderboardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Blizzard's PvP leaderboards, every board of the current season, into pvp_leaderboard_entries and
 * the rating history. Run daily by cron on the server (DEPLOY.md); a few minutes, about 170 calls.
 */
class PullPvpLeaderboards extends Command
{
    protected $signature = 'wow:pvp-leaderboards {--region=* : us, eu, kr, tw (default us and eu)}';

    protected $description = "Pull Blizzard's PvP leaderboards into the player pages and their rating history";

    public function handle(PvpLeaderboardService $service): int
    {
        $lock = Cache::lock('wow:pvp-leaderboards', 3600);
        if (! $lock->get()) {
            $this->warn('Already running.');

            return self::SUCCESS;
        }

        try {
            foreach ($this->option('region') ?: ['us', 'eu'] as $region) {
                $started = microtime(true);
                $r = $service->pull($region, fn ($board, $n, $s) => $this->output->isVerbose() ? $this->line("  {$board}: {$n} entries, {$s} changed") : null);
                $this->info(sprintf('%s season %s: %d boards, %d entries, %d ratings changed, %.0fs%s',
                    strtoupper($region), $r['season'] ?? '?', $r['boards'], $r['entries'], $r['snapshots'], microtime(true) - $started,
                    $r['failed'] ? '; failed: '.implode(', ', $r['failed']) : ''));
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
