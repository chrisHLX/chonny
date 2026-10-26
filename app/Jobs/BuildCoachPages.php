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
 * IN TWO PARTS (2026-10-19). A game's arrival builds its card alone (`cardsOnly`, about a second
 * whatever the account's size), on the `cards` queue, which both workers take before `default`, so
 * a burst of Battle.net syncs or player lookups cannot get in front of a finished game. That job
 * then queues the full build (Improve, Comps and Classes, 15-35s on a 400-game account) on
 * `default`. Before, every upload and every finished player lookup redrew all of it, twice a game:
 * the largest account's cards came about two minutes after the game, and its builds hit the
 * timeout 25 times in one re-measure.
 *
 * Unique until it starts, per player and part, so uploads during a build queue one more of each,
 * never a pile. It must finish inside the queue's 90s retry_after or Redis hands it out twice.
 */
class BuildCoachPages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CARDS_QUEUE = 'cards';

    public int $tries = 1;

    public int $timeout = 85;

    /**
     * The backup after an upload (2026-10-26), in seconds. The app asks for the pages ("done") only
     * once it has sent every log; a first sync of 113 rounds stopped after the last upload and before
     * "done", and the player had no pages at all. Every stored round now also queues this backup,
     * once per player (its own unique id, so a normal "done" is never held behind it); it only asks
     * for the usual build, which redraws nothing when "done" already built everything.
     */
    public const BACKUP_DELAY = 120;

    /**
     * Declared with a default, not promoted: a build queued before this field existed is unserialized
     * without it, and a promoted property would be uninitialized there.
     */
    public bool $backup = false;

    public function __construct(public int $userId, public bool $cardsOnly = false, bool $backup = false)
    {
        $this->backup = $backup;
        if ($cardsOnly) {
            $this->onQueue(self::CARDS_QUEUE);
        }
    }

    /** The backup build after an upload: the pages are built even if the app never says "done". */
    public static function backup(int $userId): void
    {
        static::dispatch($userId, false, true)->delay(now()->addSeconds(self::BACKUP_DELAY));
    }

    /** A game's card first, then the rest: what an upload or a finished lookup asks for. */
    public static function cardsFirst(int $userId): void
    {
        static::dispatch($userId, true);
    }

    public function uniqueId(): string
    {
        return $this->userId.($this->backup ? ':backup' : ($this->cardsOnly ? ':cards' : ':all'));
    }

    public static function dir(int $userId): string
    {
        return storage_path("app/coach/{$userId}");
    }

    public function handle(): void
    {
        if ($this->backup) {
            static::cardsFirst($this->userId);

            return;
        }

        Artisan::call('wow:game-cards', array_filter([
            '--user' => $this->userId,
            '--dir' => self::dir($this->userId),
            '--web' => true,
            '--cards-only' => $this->cardsOnly,
        ]));

        if ($this->cardsOnly) {
            static::dispatch($this->userId);
        }
    }
}
