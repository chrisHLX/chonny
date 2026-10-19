<?php

namespace App\Jobs;

use App\Http\Services\PlayerExperienceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Looks up the experience of every player in an uploaded game, so "Your analysis" can show it
 * without making a page wait on Blizzard (about a second per character). One job per game: six
 * characters sit well inside the workers' 90-second timeout, and anyone already cached is skipped.
 *
 * The uploader's pages are built by another job (BuildCoachPages) that usually finishes first, so
 * their cards said "not looked up yet" until the next upload (Cbags' first games, 2026-10-11). When
 * this job fetched anyone new, it asks for those pages again; BuildCoachPages is unique until it
 * starts, so a lobby's six lookups queue one rebuild, and only changed pages are drawn.
 *
 * One job per set of players while it waits (2026-10-12): a Solo Shuffle lobby's six rounds hold the
 * same six players and queued six identical jobs; the duplicates now drop. Dispatched with a short
 * delay (DELAY_SECONDS) so the page build, on the same two workers, goes first on a busy evening.
 */
class FetchPlayerExperience implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 80;

    public int $tries = 1;

    public const DELAY_SECONDS = 30;

    /** Long enough to cover a lobby being uploaded; the lookups themselves are cached for days. */
    public int $uniqueFor = 600;

    /**
     * @param  array<int, string>  $players  `Name-Realm-Region`, as the log records them
     * @param  int|null  $rebuildFor  the uploader, whose pages show these players
     */
    public function __construct(public array $players, public ?int $rebuildFor = null) {}

    public function uniqueId(): string
    {
        $players = array_values(array_unique($this->players));
        sort($players);

        return md5(implode('|', $players)).':'.($this->rebuildFor ?? '-');
    }

    public function handle(PlayerExperienceService $experience): void
    {
        $players = array_values(array_unique($this->players));
        $new = count(array_filter($experience->cachedMany($players), fn ($hit) => $hit === null));

        foreach ($players as $player) {
            $experience->lookup($player);
        }

        if ($new > 0 && $this->rebuildFor !== null && is_dir(BuildCoachPages::dir($this->rebuildFor))) {
            BuildCoachPages::cardsFirst($this->rebuildFor);
        }
    }
}
