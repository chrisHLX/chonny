<?php

namespace App\Http\Services;

use App\Enums\UserGuideBlockType;
use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellDataUpdate;
use App\Models\User;
use App\Models\UserGuide;
use Illuminate\Support\Collection;

/**
 * The feed of game plans: guides being published or updated, and game-data updates, newest first.
 *
 * One definition shared by the signed-in Home page and the public front page (Landing), so what a
 * guide looks like in the stream, and which guides a viewer may see, cannot drift between them.
 *
 * WHO SEES WHAT. With a viewer, the same rule UserGuide::isReadableBy() applies, as a query:
 * public, yours, shared with one of your guilds, or shared with you by name — never a draft.
 * Without one (a visitor on the front page), public guides only.
 *
 * THE FRONT PAGE IS SHOWN TO STRANGERS, so three things differ there (2026-09-16):
 *   - reworded-tooltip counts are dropped. "Tooltip text changed on 2 abilities" means nothing to
 *     someone who has never used the site, and an update with nothing else in it is left out;
 *   - one author can fill at most MAX_PER_AUTHOR items, so an afternoon editing five guides does
 *     not turn the front page into one person's history;
 *   - while few guides exist, the stream is topped up with the most-liked public guides, marked as
 *     popular rather than presented as recent activity.
 */
class GuideFeed
{
    public const MAX_PER_AUTHOR = 2;

    /** Below this many recent guides on the first page, the public stream is topped up. */
    public const THIN_FEED = 6;

    /**
     * @param  'everyone'|'circle'  $scope  circle = friends and guilds; needs a viewer
     * @return array{items: array<int, array>, hasMore: bool}
     */
    public function build(?User $viewer, string $scope, int $limit, int $firstPage): array
    {
        $public = $viewer === null;

        // A visitor's stream is capped per author, so fetch generously and trim afterwards —
        // otherwise one prolific author could leave a page short even when more guides exist.
        $fetch = $public ? ($limit + 1) * 4 : $limit + 1;
        $guides = $this->guides($viewer, $scope, $fetch);

        if ($public) {
            $guides = $this->capPerAuthor($guides);
        }

        $hasMore = $guides->count() > $limit;
        $guides = $guides->take($limit)->values();

        $items = $guides->map(fn (UserGuide $g) => $this->guideItem($g))->all();

        // Game-data updates belong to everyone, so they are not in the friends & guilds view.
        // Only updates newer than the oldest guide shown are merged in, so "load more" pages
        // through one timeline rather than stacking every update at the bottom.
        if ($scope === 'everyone') {
            $since = $hasMore ? $guides->last()?->updated_at : null;
            foreach ($this->dataUpdates($since, numericOnly: $public) as $update) {
                $items[] = $update;
            }
        }

        usort($items, fn ($a, $b) => $b['at'] <=> $a['at']);

        // Topped up after sorting, so popular guides sit below the recent activity instead of
        // being interleaved with it by a date that says nothing about why they are shown.
        if ($public && ! $hasMore && $limit <= $firstPage && $guides->count() < self::THIN_FEED) {
            foreach ($this->popular($guides->pluck('id'), self::THIN_FEED - $guides->count()) as $guide) {
                $items[] = $this->guideItem($guide, popular: true);
            }
        }

        $this->attachPreviewSpells($items);

        return ['items' => $items, 'hasMore' => $hasMore];
    }

    /** Published guides this viewer may read, newest activity first. */
    private function guides(?User $viewer, string $scope, int $limit): Collection
    {
        $query = UserGuide::query()
            ->where('status', UserGuideStatus::Published->value)
            // Machine-drafted guides are not player activity — they have their own page.
            ->humanAuthored()
            ->with([
                'user', 'lastEditor', 'authorCharacter.gameClass',
                'members.specialization.gameClass', 'enemies.specialization.gameClass',
                'sections.blocks',
            ]);

        if ($viewer === null) {
            $query->where('visibility', UserGuideVisibility::Public->value);

            return $query->orderByDesc('updated_at')->limit($limit)->get();
        }

        $friendIds = $viewer->friendIds();
        $guildIds = $viewer->guilds()->pluck('guilds.id');

        if ($scope === 'circle') {
            if ($friendIds->isEmpty() && $guildIds->isEmpty()) {
                return collect();
            }

            $query->where('user_id', '!=', $viewer->id)->where(fn ($q) => $q
                ->where(fn ($f) => $f->whereIn('user_id', $friendIds)
                    ->where('visibility', UserGuideVisibility::Public->value))
                ->orWhere(fn ($g) => $g->whereIn('guild_id', $guildIds)
                    ->where('visibility', UserGuideVisibility::Guild->value))
                ->orWhere(fn ($v) => $v->whereIn('user_id', $friendIds)
                    ->whereHas('viewers', fn ($w) => $w->whereKey($viewer->id))));
        } else {
            $query->where(fn ($q) => $q
                ->where('visibility', UserGuideVisibility::Public->value)
                ->orWhere('user_id', $viewer->id)
                ->orWhere(fn ($g) => $g->whereIn('guild_id', $guildIds)
                    ->where('visibility', UserGuideVisibility::Guild->value))
                ->orWhereHas('viewers', fn ($w) => $w->whereKey($viewer->id)));
        }

        return $query->orderByDesc('updated_at')->limit($limit)->get();
    }

    /** Keeps each author's newest MAX_PER_AUTHOR guides, preserving order. */
    private function capPerAuthor(Collection $guides): Collection
    {
        $seen = [];

        return $guides->filter(function (UserGuide $guide) use (&$seen) {
            $seen[$guide->user_id] = ($seen[$guide->user_id] ?? 0) + 1;

            return $seen[$guide->user_id] <= self::MAX_PER_AUTHOR;
        })->values();
    }

    /** The most-liked public guides not already in the stream. */
    private function popular(Collection $excludeIds, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        return UserGuide::listed()->humanAuthored()
            ->whereNotIn('id', $excludeIds)
            ->with([
                'user', 'lastEditor', 'authorCharacter.gameClass',
                'members.specialization.gameClass', 'enemies.specialization.gameClass',
                'sections.blocks',
            ])
            ->orderByDesc('like_count')
            ->orderByDesc('view_count')
            ->limit($count)
            ->get();
    }

    private function guideItem(UserGuide $guide, bool $popular = false): array
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
            'popular' => $popular,
            'preview' => $preview,
        ];
    }

    /** Recent game-data updates as feed items, each with its numeric changes resolved. */
    private function dataUpdates($since, bool $numericOnly): array
    {
        return SpellDataUpdate::query()
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->with(['changes.spell'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (SpellDataUpdate $update) use ($numericOnly) {
                $numeric = $update->changes->filter(fn ($c) => $c->isNumeric() && $c->spell)->values();
                $tooltips = $update->changes->where('field', 'description')->pluck('spell_id')->unique()->count();

                // More than a hundred reworded tooltips in one run is this codebase's parser
                // changing, not the game — say nothing rather than claim a patch rewrote them.
                if ($numericOnly || $tooltips > 100) {
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
}
