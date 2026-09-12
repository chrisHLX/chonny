<?php

namespace App\Livewire\Battlenet;

use App\Http\Services\BattlenetCharacterSyncService;
use App\Http\Services\BattlenetClient;
use App\Models\BattlenetCharacter;
use App\Models\PageViewEvent;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Your linked Battle.net account and the characters on it, at /characters.
 *
 * Detail (exp, ratings, gear, talents) arrives from queued SyncBattlenetCharacter jobs after a
 * link, so the page polls while any character is still waiting — but only for a few minutes after
 * the link, so a page left open with no queue worker running does not poll forever. Every row also
 * has a Refresh that syncs inline, which is what makes the page usable even with no worker at all.
 */
class Characters extends Component
{
    /** How long after a link the page keeps polling for queued syncs to land. */
    private const POLL_WINDOW_MINUTES = 10;

    /** Whether characters below the detail-sync level are listed too. */
    public bool $showLowLevel = false;

    public function mount(): void
    {
        PageViewEvent::log('battlenet_characters');
    }

    #[Computed]
    public function account()
    {
        return auth()->user()->battlenetAccount()
            ->with(['characters.gameClass', 'characters.specialization.gameClass'])
            ->first();
    }

    /** Level first, then the best exp — the order a player scans their own roster in. */
    #[Computed]
    public function characters()
    {
        if (! $this->account) {
            return collect();
        }

        $min = $this->minLevel();

        return $this->account->characters
            ->filter(fn (BattlenetCharacter $c) => $this->showLowLevel || $c->level >= $min)
            ->sortBy([
                fn ($a, $b) => $b->level <=> $a->level,
                fn ($a, $b) => ($b->bestExp()['rating'] ?? 0) <=> ($a->bestExp()['rating'] ?? 0),
                fn ($a, $b) => strcmp($a->name, $b->name),
            ])
            ->values();
    }

    #[Computed]
    public function hiddenCount(): int
    {
        return $this->account ? $this->account->characters->where('level', '<', $this->minLevel())->count() : 0;
    }

    /** Whether queued syncs are plausibly still landing — see POLL_WINDOW_MINUTES. */
    #[Computed]
    public function isAwaitingSync(): bool
    {
        if (! $this->account?->characters_synced_at
            || $this->account->characters_synced_at->lt(now()->subMinutes(self::POLL_WINDOW_MINUTES))) {
            return false;
        }

        return $this->account->characters->contains(
            fn (BattlenetCharacter $c) => $c->level >= $this->minLevel() && ! $c->isSynced() && ! $c->sync_error,
        );
    }

    /** Re-fetch one character now, without waiting for the queue. Owner-scoped. */
    public function refresh(int $characterId): void
    {
        $character = $this->account?->characters->firstWhere('id', $characterId);

        if ($character) {
            app(BattlenetCharacterSyncService::class)->syncDetails($character);
            unset($this->account, $this->characters, $this->isAwaitingSync);
        }
    }

    public function render()
    {
        return view('livewire.battlenet.characters', [
            'configured' => app(BattlenetClient::class)->isConfigured(),
        ])->layout('layouts.app', [
            'title' => 'Your characters | MindCollector',
            'description' => 'Your linked Battle.net characters — exp, ratings, gear and talents.',
        ]);
    }

    private function minLevel(): int
    {
        return (int) config('services.battlenet.detail_min_level', 70);
    }
}
