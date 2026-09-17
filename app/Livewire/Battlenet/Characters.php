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

    /** At most this many characters are listed until the player asks for the rest. */
    public const SHOWN_LIMIT = 9;

    /** Whether the characters left out of the default list are listed too. */
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

    /**
     * Max-level characters only, and when there are more than SHOWN_LIMIT of them, the ones with
     * the highest item level — an account's alts rarely matter for PvP. "Show more" lists
     * everything, in the old level-then-exp order.
     */
    #[Computed]
    public function characters()
    {
        if (! $this->account) {
            return collect();
        }

        if ($this->showLowLevel) {
            return $this->account->characters
                ->sortBy([
                    fn ($a, $b) => $b->level <=> $a->level,
                    fn ($a, $b) => ($b->item_level ?? 0) <=> ($a->item_level ?? 0),
                    fn ($a, $b) => ($b->bestExp()['rating'] ?? 0) <=> ($a->bestExp()['rating'] ?? 0),
                    fn ($a, $b) => strcmp($a->name, $b->name),
                ])
                ->values();
        }

        return $this->account->characters
            ->filter(fn (BattlenetCharacter $c) => $c->level >= $this->maxLevel())
            ->sortBy([
                fn ($a, $b) => ($b->item_level ?? 0) <=> ($a->item_level ?? 0),
                fn ($a, $b) => ($b->bestExp()['rating'] ?? 0) <=> ($a->bestExp()['rating'] ?? 0),
                fn ($a, $b) => strcmp($a->name, $b->name),
            ])
            ->take(self::SHOWN_LIMIT)
            ->values();
    }

    /** How many characters the default list leaves out. */
    #[Computed]
    public function hiddenCount(): int
    {
        if (! $this->account) {
            return 0;
        }

        $shown = min(self::SHOWN_LIMIT, $this->account->characters->where('level', '>=', $this->maxLevel())->count());

        return $this->account->characters->count() - $shown;
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
            'maxLevel' => $this->maxLevel(),
        ])->layout('layouts.app', [
            'title' => 'Your characters | MindCollector',
            'description' => 'Your linked Battle.net characters — exp, ratings, gear and talents.',
        ]);
    }

    private function maxLevel(): int
    {
        return (int) config('services.battlenet.max_level', 90);
    }

    private function minLevel(): int
    {
        return (int) config('services.battlenet.detail_min_level', 70);
    }
}
