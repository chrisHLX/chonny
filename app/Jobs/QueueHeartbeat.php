<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/**
 * Proves a queue worker is taking jobs: ops:daily-report queues one and waits for it to write its
 * token back. Nothing else uses it.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const KEY = 'ops:queue-heartbeat';

    public function __construct(public string $token) {}

    public function handle(): void
    {
        Cache::put(self::KEY, $this->token, 3600);
    }
}
