<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * Builds one player's pages for /wow/coach: the same `wow:game-cards` the desktop app runs, into
 * storage/app/coach/{user}, with web image paths. Built here, once per upload, and served as files,
 * so opening a page costs nothing: a full build peaked at 324 MB for 186 games (2026-10-05), which
 * no page view could afford on a 2 GB, one-core box.
 *
 * Unique until it starts, so a second upload while one builds queues one more build, never a pile.
 * It must finish inside the queue's 90s retry_after or Redis hands it out twice; only changed games
 * are drawn again, and a first full build is about 30s.
 */
class BuildCoachPages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public static function dir(int $userId): string
    {
        return storage_path("app/coach/{$userId}");
    }

    public function handle(): void
    {
        Artisan::call('wow:game-cards', ['--user' => $this->userId, '--dir' => self::dir($this->userId), '--web' => true]);
    }
}
