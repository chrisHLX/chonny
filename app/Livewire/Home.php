<?php

namespace App\Livewire;

use App\Enums\UserGuideType;
use App\Http\Services\BattlenetClient;
use App\Http\Services\FriendshipService;
use App\Http\Services\GuideFeed;
use App\Models\PageViewEvent;
use App\Models\UserGuide;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Where a signed-in player lands: a feed of game plans, with their own things in a narrow column
 * beside it.
 *
 * FEED, 2026-09-14. This page used to be a dashboard — six equally weighted cards (build a guide,
 * characters, comps, your guides, friends' guides, popular guides) plus a side column — which read
 * as "here is everything MindCollector can do" rather than "here is what players are planning". It
 * is now one primary action (start a guide), one stream, and small secondary information.
 *
 * The stream holds exactly two kinds of item, both of them structured game knowledge rather than
 * chatter: a guide being published or updated, and a game-data update (abilities whose cooldown,
 * charges or duration changed in an import — see SpellChangeRecorder). The second matters for a
 * reason beyond usefulness: it is content the site produces on its own, so the feed is never an
 * empty social network while there are few players writing.
 *
 * The stream itself is built by GuideFeed, shared with the public front page (Landing).
 *
 * Nothing new is stored for the feed. A guide appears once, at its latest activity (updated_at),
 * and whether it reads "published" or "updated" is derived from published_at. Visibility is the
 * same rule UserGuide::isReadableBy() applies, written as a query: public, shared with one of your
 * guilds, shared with you by name, or yours — never a draft.
 *
 * FIRST RUN (2026-09-14): a player with no guide of their own also gets a "Build your first game
 * plan" block above the feed, with a link to the most-liked public guide as an example.
 */
class Home extends Component
{
    public const FEED_PAGE = 12;

    public const FEED_MAX = 60;

    /** everyone | circle. Locked: only setFeedScope() changes it, and it validates. */
    #[Locked]
    public string $feedScope = 'everyone';

    /** Locked so a tampered request cannot ask for the whole table; loadMore() caps it. */
    #[Locked]
    public int $feedLimit = self::FEED_PAGE;

    public function mount(): void
    {
        PageViewEvent::log('home');
    }

    public function setFeedScope(string $scope): void
    {
        if (! in_array($scope, ['everyone', 'circle'], true) || $scope === $this->feedScope) {
            return;
        }

        // A real switch into a tab — never the tab landed on, never a re-click. Surfaced by
        // Admin\PageUsage::getHomeEngagementProperty(), outside PAGES (it is not a page).
        PageViewEvent::log('home_feed', slot: $scope);

        $this->feedScope = $scope;
        $this->feedLimit = self::FEED_PAGE;
        unset($this->feed);
    }

    public function loadMore(): void
    {
        PageViewEvent::log('home_feed', slot: 'more');

        $this->feedLimit = min($this->feedLimit + self::FEED_PAGE, self::FEED_MAX);
        unset($this->feed);
    }

    #[Computed]
    public function myGuides()
    {
        return auth()->user()->guides()
            ->with(['members.specialization.gameClass'])
            ->orderByDesc('updated_at')
            ->limit(5)
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
     * The stream: guides and game-data updates, newest first. Built by GuideFeed, which the public
     * front page (Landing) shares, so the two cannot drift.
     *
     * @return array{items: array<int, array>, hasMore: bool}
     */
    #[Computed]
    public function feed(): array
    {
        return app(GuideFeed::class)->build(auth()->user(), $this->feedScope, $this->feedLimit, self::FEED_PAGE);
    }

    /** The most-liked public guide, shown to a brand-new player as an example of a finished plan. */
    #[Computed]
    public function exampleGuide(): ?UserGuide
    {
        return UserGuide::listed()
            ->humanAuthored()
            ->with(['user', 'members.specialization.gameClass'])
            ->orderByDesc('like_count')
            ->orderByDesc('view_count')
            ->first();
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

    /** The player's characters, best exp first. */
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
        unset($this->friendRequests, $this->feed, $this->collaborating);
    }

    /** Top class quiz players by questions answered, for the leaderboard in the side column. */
    #[Computed]
    public function quizLeaderboard()
    {
        return app(\App\Quiz\QuizService::class)->leaderboard('wow', 5);
    }

    public function render()
    {
        return view('livewire.home')->layout('layouts.app', [
            'title' => 'Home | MindCollector',
            'description' => 'Game plans from WoW arena players, and updates to the game data behind them.',
        ]);
    }
}
