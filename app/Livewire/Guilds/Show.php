<?php

namespace App\Livewire\Guilds;

use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Models\Guild;
use App\Models\PageViewEvent;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A guild's page: its members, its guides, and the join button.
 *
 * NOT auth-gated, because this URL is the invite — somebody following a link pasted in Discord may
 * not have an account yet, and bouncing them to a login screen with no explanation of what they
 * were invited to is the wrong first impression. A signed-out visitor sees what the guild is and a
 * prompt to sign in; guides are never listed to them.
 */
class Show extends Component
{
    public Guild $guild;

    public ?string $notice = null;

    public function mount(Guild $guild): void
    {
        $this->guild = $guild;

        PageViewEvent::log('guild_show');
    }

    #[Computed]
    public function isMember(): bool
    {
        return $this->guild->hasMember(auth()->user());
    }

    #[Computed]
    public function isOwner(): bool
    {
        return $this->guild->isOwnedBy(auth()->user());
    }

    #[Computed]
    public function members()
    {
        return $this->guild->members()->get();
    }

    /**
     * Guides visible to this guild, best-rated first.
     *
     * Members only — a guild page is a listing, and listing a guild's guides to a non-member would
     * make guild visibility meaningless. Includes public guides shared with the guild too, since
     * an author who picked this guild meant it to appear here whatever else it is.
     */
    #[Computed]
    public function guides()
    {
        if (! $this->isMember) {
            return collect();
        }

        return $this->guild->guides()
            ->where('status', UserGuideStatus::Published->value)
            ->whereIn('visibility', [UserGuideVisibility::Guild->value, UserGuideVisibility::Public->value])
            ->with(['user', 'members.specialization.gameClass', 'enemies.specialization.gameClass'])
            ->orderByDesc('like_count')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function join(): void
    {
        if (! auth()->check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $this->guild->join(auth()->user());
        $this->notice = "You joined {$this->guild->name}.";
        $this->refresh();
    }

    public function leave(): void
    {
        if (! auth()->check()) {
            return;
        }

        // The owner cannot leave — see Guild::leave(). Say why rather than silently doing nothing.
        $this->notice = $this->guild->leave(auth()->user())
            ? "You left {$this->guild->name}."
            : 'You own this guild, so you cannot leave it. Delete it instead.';

        $this->refresh();
    }

    /** Remove someone else. Owner only, and never the owner themselves. */
    public function remove(int $userId): void
    {
        if (! $this->isOwner || $userId === $this->guild->owner_id) {
            return;
        }

        $this->guild->members()->detach($userId);
        $this->refresh();
    }

    /**
     * Delete the guild. Guides shared with it are NOT deleted — guild_id nulls out and they fall
     * back to being readable by their author and explicit viewers only. Somebody's written work
     * must never disappear because a group they were in was disbanded.
     */
    public function destroy(): void
    {
        if (! $this->isOwner) {
            return;
        }

        $this->guild->delete();
        $this->redirect(route('guilds.index'), navigate: true);
    }

    private function refresh(): void
    {
        $this->guild->refresh();
        unset($this->isMember, $this->isOwner, $this->members, $this->guides);
    }

    public function render()
    {
        return view('livewire.guilds.show')->layout('layouts.app', [
            'title' => "{$this->guild->name} | MindCollector",
            'description' => $this->guild->description ?: "Arena guides shared within {$this->guild->name}.",
        ]);
    }
}
