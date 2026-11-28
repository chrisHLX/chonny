<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * Rebuilds the live norms after uploads (wow:population-refresh, 2026-10-11), so every player's
 * comparisons include the games uploaded since. Queued from /api/coach/done with a short delay and
 * unique until it starts: a burst of uploads (a backfill sends a batch every few seconds) gives one
 * rebuild, not one per batch. About 25 seconds for 3,000 games alone (2026-11-26), longer when a page
 * build shares the CPU; the job's limit is 85 (docs/automation.md, "Known timeouts").
 */
class RefreshPopulationNorms implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** How long after the last upload the rebuild waits, gathering the rest of the burst. */
    public const DELAY_SECONDS = 120;

    public int $tries = 1;

    public int $timeout = 85;

    /**
     * Seconds a rebuild queued during a deploy waits before trying again (2026-11-28). deploy.sh
     * keeps storage/app/deploying while it runs; its spell import and kit rebuild take the one vCPU,
     * and the rebuild that ran into them timed out (2026-11-27 09:26, two minutes into a deploy).
     * A marker older than half an hour is a deploy that died without clearing it, and is ignored.
     */
    public const DEPLOY_WAIT = 300;

    public function handle(): void
    {
        $marker = storage_path('app/deploying');
        if (is_file($marker) && time() - filemtime($marker) < 1800) {
            static::dispatch()->delay(now()->addSeconds(self::DEPLOY_WAIT));

            return;
        }

        ini_set('memory_limit', '1G');
        Artisan::call('wow:population-refresh');
    }
}
