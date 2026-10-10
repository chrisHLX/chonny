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
 * rebuild, not one per batch. About 10 seconds; the queue's limit is 90.
 */
class RefreshPopulationNorms implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** How long after the last upload the rebuild waits, gathering the rest of the burst. */
    public const DELAY_SECONDS = 120;

    public int $tries = 1;

    public int $timeout = 85;

    public function handle(): void
    {
        ini_set('memory_limit', '1G');
        Artisan::call('wow:population-refresh');
    }
}
