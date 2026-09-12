<?php

namespace App\Jobs;

use App\Http\Services\BattlenetCharacterSyncService;
use App\Models\BattlenetCharacter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fetch one character's exp, ratings, gear and talents after a Battle.net link.
 *
 * One job per character rather than one per account: a character is ~8 API calls and a few seconds,
 * an account can have dozens, and the production workers kill anything over 90s.
 *
 * No retries: syncDetails() never throws — it writes the failure to the character's `sync_error`,
 * where the player sees it next to a Refresh button. A retry loop would only repeat a request that
 * Blizzard just refused.
 */
class SyncBattlenetCharacter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $characterId) {}

    public function handle(BattlenetCharacterSyncService $sync): void
    {
        $character = BattlenetCharacter::find($this->characterId);

        // Unlinked, or dropped from the account, between dispatch and now.
        if ($character) {
            $sync->syncDetails($character);
        }
    }
}
