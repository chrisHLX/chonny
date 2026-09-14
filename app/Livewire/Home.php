<?php

namespace App\Livewire;

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideType;
use App\Enums\UserGuideVisibility;
use App\Http\Services\BattlenetClient;
use App\Http\Services\FriendshipService;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellDataUpdate;
use App\Models\UserGuide;
use Illuminate\Support\Collection;
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
     * The stream: guides and game-data updates, newest first.
     *
     * @return array{items: array<int, array>, hasMore: bool}
     */
    #[Computed]
    public function feed(): array
    {
        $guides = $this->feedGuides($this->feedLimit + 1);
        $hasMore = $guides->count() > $this->feedLimit;
        $guides = $guides->take($this->feedLimit);

        $items = $guides->map(fn (UserGuide $g) => $this->guideItem($g))->all();

        // Game-data updates belong to everyone, so they are not in the friends & guilds view.
        // Only updates newer than the oldest guide shown are merged in, so "load more" pages
        // through one timeline rather than stacking every update at the bottom.
        if ($this->feedScope === 'everyone') {
            $since = $hasMore ? $guides->last()?->updated_at : null;
            foreach ($this->dataUpdates($since) as $update) {
                $items[] = $update;
            }
        }

        usort($items, fn ($a, $b) => $b['at'] <=> $a['at']);

        $this->attachPreviewSpells($items);

        return ['items' => $items, 'hasMore' => $hasMore];
    }

    /** The most-liked public guide, shown to a brand-new player as an example of a finished plan. */
    #[Computed]
    public function exampleGuide(): ?UserGuide
    {
        return UserGuide::listed()
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

    // ------------------------------------------------------------------ feed internals

    /** Published guides this player may read, newest activity first. */
    private function feedGuides(int $limit): Collection
    {
        $user = auth()->user();
        $friendIds = $user->friendIds();
        $guildIds = $user->guilds()->pluck('guilds.id');

        $query = UserGuide::query()
            ->where('status', UserGuideStatus::Published->value)
            ->with([
                'user', 'lastEditor', 'authorCharacter.gameClass',
                'members.specialization.gameClass', 'enemies.specialization.gameClass',
                'sections.blocks',
            ]);

        if ($this->feedScope === 'circle') {
            if ($friendIds->isEmpty() && $guildIds->isEmpty()) {
                return collect();
            }

            $query->where('user_id', '!=', $user->id)->where(fn ($q) => $q
                ->where(fn ($f) => $f->whereIn('user_id', $friendIds)
                    ->where('visibility', UserGuideVisibility::Public->value))
                ->orWhere(fn ($g) => $g->whereIn('guild_id', $guildIds)
                    ->where('visibility', UserGuideVisibility::Guild->value))
                ->orWhere(fn ($v) => $v->whereIn('user_id', $friendIds)
                    ->whereHas('viewers', fn ($w) => $w->whereKey($user->id))));
        } else {
            $query->where(fn ($q) => $q
                ->where('visibility', UserGuideVisibility::Public->value)
                ->orWhere('user_id', $user->id)
                ->orWhere(fn ($g) => $g->whereIn('guild_id', $guildIds)
                    ->where('visibility', UserGuideVisibility::Guild->value))
                ->orWhereHas('viewers', fn ($w) => $w->whereKey($user->id)));
        }

        return $query->orderByDesc('updated_at')->limit($limit)->get();
    }

    private function guideItem(UserGuide $guide): array
    {
        // "Published" while the latest activity is the publish itself; "updated" after that.
        $isNew = $guide->published_at !== null
            && $guide->updated_at->diffInMinutes($guide->published_at, true) < 30;

        $actor = $guide->lastEditor ?? $guide->user;

        // The first sequence with abilities in it — what the guide is actually about, shown as
        // icons in order. Structured knowledge is the point of a MindCollector post, so the feed
        // shows the plan, not just its title.
        $preview = null;
        foreach ($guide->sections as $section) {
            if ($section->kind !== UserGuideSectionKind::Sequence) {
                continue;
            }
            $ids = $section->blocks
                ->filter(fn ($b) => $b->block_type === UserGuideBlockType::Spell)
                ->map(fn ($b) => $b->externalSpellId())
                ->filter()
                ->values();
            if ($ids->isNotEmpty()) {
                $preview = ['title' => $section->title, 'ids' => $ids->take(7)->all(), 'more' => max(0, $ids->count() - 7)];
                break;
            }
        }

        return [
            'type' => 'guide',
            'at' => $guide->updated_at,
            'guide' => $guide,
            'verb' => $isNew ? 'published' : 'updated',
            'actor' => $actor,
            'byCollaborator' => $actor && $actor->id !== $guide->user_id,
            'preview' => $preview,
        ];
    }

    /** Recent game-data updates as feed items, each with its numeric changes resolved. */
    private function dataUpdates($since): array
    {
        return SpellDataUpdate::query()
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->with(['changes.spell'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (SpellDataUpdate $update) {
                $numeric = $update->changes->filter(fn ($c) => $c->isNumeric() && $c->spell)->values();
                $tooltips = $update->changes->where('field', 'description')->pluck('spell_id')->unique()->count();

                // More than a hundred reworded tooltips in one run is this codebase's parser
                // changing, not the game — say nothing rather than claim a patch rewrote them.
                if ($tooltips > 100) {
                    $tooltips = 0;
                }

                if ($numeric->isEmpty() && $tooltips === 0) {
                    return null;
                }

                return [
                    'type' => 'data',
                    'at' => $update->created_at,
                    'update' => $update,
                    'changes' => $numeric,
                    'tooltips' => $tooltips,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** One query for every preview icon on the page, by Blizzard's external spell id. */
    private function attachPreviewSpells(array &$items): void
    {
        $ids = collect($items)->pluck('preview.ids')->flatten()->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $patchId = Patch::where('is_current', true)->value('id');
        $spells = Spell::query()
            ->when($patchId, fn ($q) => $q->where('patch_id', $patchId))
            ->whereIn('spell_id', $ids)
            ->get()
            ->keyBy('spell_id');

        foreach ($items as &$item) {
            if (($item['preview'] ?? null) !== null) {
                $item['preview']['spells'] = collect($item['preview']['ids'])
                    ->map(fn ($id) => $spells->get($id))
                    ->filter()
                    ->values();
            }
        }
    }

    public function render()
    {
        return view('livewire.home')->layout('layouts.app', [
            'title' => 'Home | MindCollector',
            'description' => 'Game plans from WoW arena players, and updates to the game data behind them.',
        ]);
    }
}
