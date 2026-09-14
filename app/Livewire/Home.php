<?php

namespace App\Livewire;

use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Http\Services\BattlenetClient;
use App\Http\Services\FriendshipService;
use App\Models\PageViewEvent;
use App\Models\UserGuide;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Where a signed-in player lands: the arena side of the site, not the quizzes.
 *
 * Replaced the old /dashboard (2026-09-13), which was entirely the learning profile — diagnostic,
 * concept mastery, a quiz leaderboard — so a player who signed up to plan comps landed on a page
 * about none of them. That page still exists, unchanged, at /training (DashboardController); it is
 * one link away here rather than the first thing anyone sees.
 *
 * The page is built around three things to do — write a guide, link Battle.net, open the comp
 * builder — then what the player's friends and guilds are writing, because the site is meant to
 * feel like a place players share plans, not a reference they read alone. On a phone this is also
 * the page that has to work without the sidebar, so every destination on it is a real link.
 *
 * FIRST RUN (2026-09-14): a player with no guide of their own gets a different top of the page —
 * one "Build your first game plan" block, a link to the most-liked public guide as an example,
 * and other players' plans above the friends block. The regular layout assumes someone who has
 * already written something and has people to share it with, and for a brand-new account that
 * meant "Welcome back", three equal cards, and a column of empty social blocks. The switch is
 * computed in the view from myGuides, so nothing is stored.
 */
class Home extends Component
{
    public function mount(): void
    {
        PageViewEvent::log('home');
    }

    #[Computed]
    public function myGuides()
    {
        return auth()->user()->guides()
            ->with(['members.specialization.gameClass'])
            ->orderByDesc('updated_at')
            ->limit(4)
            ->get();
    }

    /** Friends' and guildmates' guides this player may help edit. */
    #[Computed]
    public function collaborating()
    {
        return UserGuide::editableByCollaborator(auth()->user())
            ->with(['user', 'members.specialization.gameClass'])
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get();
    }

    /**
     * What the people this player plays with have published: a friend's public guides, and any
     * guide shared with one of their guilds. Only guides this player can already read — this is a
     * feed of existing access, never a way around it.
     */
    #[Computed]
    public function circleGuides()
    {
        $user = auth()->user();
        $friendIds = $user->friendIds();
        $guildIds = $user->guilds()->pluck('guilds.id');

        if ($friendIds->isEmpty() && $guildIds->isEmpty()) {
            return collect();
        }

        return UserGuide::where('status', UserGuideStatus::Published->value)
            ->where('user_id', '!=', $user->id)
            ->where(fn ($q) => $q
                ->where(fn ($f) => $f->whereIn('user_id', $friendIds)
                    ->where('visibility', UserGuideVisibility::Public->value))
                ->orWhere(fn ($g) => $g->whereIn('guild_id', $guildIds)
                    ->where('visibility', UserGuideVisibility::Guild->value)))
            ->with(['user', 'authorCharacter.gameClass', 'members.specialization.gameClass', 'enemies.specialization.gameClass'])
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();
    }

    /** The best public guides on the site, so there is always something to read here. */
    #[Computed]
    public function popularGuides()
    {
        return UserGuide::listed()
            ->with(['user', 'authorCharacter.gameClass', 'members.specialization.gameClass', 'enemies.specialization.gameClass'])
            ->orderByDesc('like_count')
            ->orderByDesc('view_count')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function friendRequests()
    {
        return auth()->user()->incomingFriendRequests()->limit(5)->get();
    }

    #[Computed]
    public function guilds()
    {
        return auth()->user()->guilds()->withCount('members')->get();
    }

    /** The player's characters, best exp first, for the Battle.net card. */
    #[Computed]
    public function characters()
    {
        return auth()->user()->battlenetCharacters()
            ->with(['gameClass', 'specialization.gameClass'])
            ->get()
            ->sortByDesc(fn ($c) => $c->bestExp()['rating'] ?? 0)
            ->take(3)
            ->values();
    }

    #[Computed]
    public function hasBattlenet(): bool
    {
        return auth()->user()->battlenetAccount()->exists();
    }

    #[Computed]
    public function battlenetAvailable(): bool
    {
        return app(BattlenetClient::class)->isConfigured();
    }

    #[Computed]
    public function presetComps(): array
    {
        return WowComps::presetLinks();
    }

    /** Start a guide and go straight to the builder — the page's main call to action. */
    public function createGuide(string $type)
    {
        $guideType = UserGuideType::tryFrom($type);
        if ($guideType === null) {
            return null;
        }

        $guide = UserGuide::startDraft(auth()->user(), $guideType);

        return $this->redirectRoute('guides.edit', ['guide' => $guide->slug], navigate: true);
    }

    public function acceptFriend(int $friendshipId, FriendshipService $service): void
    {
        $service->accept(auth()->user(), $friendshipId);
        $this->refreshSocial();
    }

    public function declineFriend(int $friendshipId, FriendshipService $service): void
    {
        $service->decline(auth()->user(), $friendshipId);
        $this->refreshSocial();
    }

    private function refreshSocial(): void
    {
        auth()->user()->forgetFriendCache();
        unset($this->friendRequests, $this->circleGuides, $this->collaborating);
    }

    public function render()
    {
        return view('livewire.home')->layout('layouts.app', [
            'title' => 'Dashboard | MindCollector',
            'description' => 'Build arena guides with your team, compare 3v3 comps, and link your WoW characters.',
        ]);
    }
}
