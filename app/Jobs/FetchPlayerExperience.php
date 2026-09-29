<?php

namespace App\Jobs;

use App\Http\Services\PlayerExperienceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Looks up the experience of every player in an uploaded game, so "Your analysis" can show it
 * without making a page wait on Blizzard (about a second per character). One job per game: six
 * characters sit well inside the workers' 90-second timeout, and anyone already cached is skipped.
 */
class FetchPlayerExperience implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 80;

    public int $tries = 1;

    /** @param  array<int, string>  $players  `Name-Realm-Region`, as the log records them */
    public function __construct(public array $players) {}

    public function handle(PlayerExperienceService $experience): void
    {
        foreach (array_unique($this->players) as $player) {
            $experience->lookup($player);
        }
    }
}
