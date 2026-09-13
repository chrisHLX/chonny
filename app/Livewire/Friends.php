<?php

namespace App\Livewire;

use App\Http\Services\FriendshipService;
use App\Models\Friendship;
use App\Models\PageViewEvent;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Friends: add players by their handle, answer requests, and see who you play with.
 *
 * Friendship matters beyond the list because it is one of the two ways into editing somebody's
 * guide (UserGuide::isEditableBy()), so every write goes through FriendshipService, which is where
 * the "both players agreed" rule lives.
 *
 * Suggestions come from the player's guilds — people they already share guides with and have not
 * friended yet. That is the one place on the site that already knows who plays together, so it
 * is the honest source; nothing here guesses at strangers.
 */
class Friends extends Component
{
    public string $handle = '';

    public ?string $message = null;

    public bool $messageIsError = false;

    public function mount(): void
    {
        // Everyone needs a handle to be added by. Assigned on first visit rather than at signup,
        // same as publishing a guide does, so no account is blocked behind a profile step.
        auth()->user()->resolveUsername();

        PageViewEvent::log('friends');
    }

    #[Computed]
    public function friends()
    {
        return auth()->user()->friends()
            ->with(['battlenetCharacters.gameClass', 'battlenetCharacters.specialization.gameClass'])
            ->get();
    }

    #[Computed]
    public function incoming()
    {
        return auth()->user()->incomingFriendRequests()->get();
    }

    #[Computed]
    public function outgoing()
    {
        return Friendship::pending()
            ->where('requester_id', auth()->id())
            ->with('addressee')
            ->latest()
            ->get();
    }

    /** Guildmates who are not friends yet and have no request either way. */
    #[Computed]
    public function suggestions()
    {
        $me = auth()->user();
        $guildIds = $me->guilds()->pluck('guilds.id');

        if ($guildIds->isEmpty()) {
            return collect();
        }

        $involved = Friendship::involving($me->id)
            ->get(['requester_id', 'addressee_id'])
            ->map(fn (Friendship $f) => $f->otherUserId($me->id));

        return User::whereHas('guilds', fn ($q) => $q->whereIn('guilds.id', $guildIds))
            ->whereKeyNot($me->id)
            ->whereNotIn('id', $involved)
            ->orderBy('name')
            ->limit(12)
            ->get();
    }

    public function add(FriendshipService $service): void
    {
        $this->reset('message', 'messageIsError');

        $target = $service->findByHandle($this->handle);

        if (! $target) {
            $this->flash('No player uses that handle. Handles are what appears in their guide links, like /g/handle/…', true);

            return;
        }

        $this->flash(match ($service->request(auth()->user(), $target)) {
            'self' => ['That\'s your own handle.', true],
            'already_friends' => ['You\'re already friends with @'.$target->handle().'.', false],
            'already_sent' => ['Request already sent — waiting on @'.$target->handle().'.', false],
            'accepted' => ['@'.$target->handle().' had already asked — you\'re friends now.', false],
            'sent' => ['Request sent to @'.$target->handle().'.', false],
        });

        $this->handle = '';
        $this->refreshLists();
    }

    public function addUser(int $userId, FriendshipService $service): void
    {
        $target = User::find($userId);

        if ($target) {
            $service->request(auth()->user(), $target);
        }

        $this->refreshLists();
    }

    public function accept(int $friendshipId, FriendshipService $service): void
    {
        $service->accept(auth()->user(), $friendshipId);
        $this->refreshLists();
    }

    public function decline(int $friendshipId, FriendshipService $service): void
    {
        $service->decline(auth()->user(), $friendshipId);
        $this->refreshLists();
    }

    public function remove(int $userId, FriendshipService $service): void
    {
        $service->remove(auth()->user(), $userId);
        $this->refreshLists();
    }

    /** @param  string|array{0: string, 1: bool}  $message */
    private function flash(string|array $message, bool $isError = false): void
    {
        [$this->message, $this->messageIsError] = is_array($message) ? $message : [$message, $isError];
    }

    private function refreshLists(): void
    {
        auth()->user()->forgetFriendCache();
        unset($this->friends, $this->incoming, $this->outgoing, $this->suggestions);
    }

    public function render()
    {
        return view('livewire.friends')->layout('layouts.app', [
            'title' => 'Friends | MindCollector',
            'description' => 'Add the players you queue with, and work on arena guides together.',
        ]);
    }
}
