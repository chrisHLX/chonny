<?php

namespace App\Console\Commands;

use App\Http\Services\BattlenetCharacterSyncService;
use App\Models\BattlenetCharacter;
use Illuminate\Console\Command;

/**
 * Re-fetch exp, ratings, gear and talents for linked characters, in bulk.
 *
 * Ratings move every week of a season, and nothing refreshes them on its own — production runs no
 * scheduler. This is the manual lever (and the thing to schedule if one is ever set up). Only
 * characters already detail-eligible are touched, oldest sync first. The character LIST is not
 * refreshed here and cannot be: that needs each player's own Battle.net sign-in.
 */
class SyncBattlenetCharacters extends Command
{
    protected $signature = 'battlenet:sync-characters
        {--user= : Only this user id}
        {--stale-hours=24 : Skip characters synced more recently than this}
        {--limit=200 : Stop after this many characters}';

    protected $description = 'Refresh exp, ratings, gear and talents for linked Battle.net characters';

    public function handle(BattlenetCharacterSyncService $sync): int
    {
        $query = BattlenetCharacter::query()
            ->where('level', '>=', (int) config('services.battlenet.detail_min_level', 70))
            ->where(fn ($q) => $q->whereNull('synced_at')
                ->orWhere('synced_at', '<', now()->subHours((int) $this->option('stale-hours'))))
            ->orderByRaw('synced_at IS NOT NULL, synced_at')
            ->limit((int) $this->option('limit'));

        if ($userId = $this->option('user')) {
            $query->whereHas('account', fn ($q) => $q->where('user_id', $userId));
        }

        $characters = $query->get();
        $failed = 0;

        foreach ($characters as $character) {
            $ok = $sync->syncDetails($character);
            $failed += $ok ? 0 : 1;
            $this->line(($ok ? '  ok   ' : '  FAIL ').$character->fullName().' ('.$character->region.')'
                .($ok ? '' : ' — '.$character->fresh()->sync_error));
        }

        $this->info("Synced {$characters->count()} characters, {$failed} failed.");

        return self::SUCCESS;
    }
}
