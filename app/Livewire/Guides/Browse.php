<?php

namespace App\Livewire\Guides;

use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\UserGuide;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public listing of player-written guides — the first place on this site where user content is
 * discoverable rather than only reachable by a link its author handed you.
 *
 * SORTS BY POPULARITY BY DEFAULT, not recency. A listing that leads with the newest thing rewards
 * posting; one that leads with what people actually found useful rewards writing something worth
 * reading, which is the only version of this feature worth having. "Newest" is still offered,
 * because a brand new guide has to be findable or nothing ever gets its first like.
 *
 * Only ever lists PUBLIC, PUBLISHED guides (scopeListed). Guild and private guides are absent
 * entirely — not shown-but-locked, which would leak their existence and their titles.
 */
class Browse extends Component
{
    use WithPagination;

    public const PER_PAGE = 12;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $classSlug = '';

    /** The class a guide is written AGAINST — see the filter in guides() for why it is separate. */
    #[Url(except: '')]
    public string $opponentClassSlug = '';

    #[Url(except: 'popular')]
    public string $sort = 'popular';

    public function mount(): void
    {
        PageViewEvent::log('guides_browse');
    }

    public function updated($property): void
    {
        // Any filter change invalidates the page number — staying on page 4 of a result set that
        // now has one page shows an empty listing and reads as "no results".
        if (in_array($property, ['search', 'classSlug', 'opponentClassSlug', 'sort'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function classes()
    {
        return GameClass::orderBy('name')->get();
    }

    #[Computed]
    public function guides()
    {
        $query = UserGuide::query()
            ->listed()
            ->with(['user', 'authorCharacter', 'members.specialization.gameClass', 'enemies.specialization.gameClass'])
            ->withCount('comments');

        if ($this->search !== '') {
            // Title and summary only. Searching section bodies would need a real index to stay
            // fast, and a guide whose TITLE does not say what it is about is not the problem this
            // page is solving.
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('summary', 'like', $term));
        }

        if ($this->classSlug !== '') {
            // Matches the author's OWN comp, never the enemy team — "Rogue guides" means guides
            // for playing a Rogue, not guides about beating one.
            $query->whereHas(
                'members.specialization.gameClass',
                fn ($q) => $q->where('slug', $this->classSlug)
            );
        }

        if ($this->opponentClassSlug !== '') {
            // The other half of the same question, kept as its OWN filter rather than folded into
            // the one above. "Guides for playing a Rogue" and "guides for beating a Rogue" are
            // different searches that happen to name the same class, and a single control cannot
            // express which one you meant — so the class filter keeps its documented meaning and
            // this one carries the matchup.
            //
            // Matches either side a guide can name an opponent on: an enemy roster row (comp
            // guides) or the single opponent_spec_id (class guides, "Rogue vs Disc").
            $slug = $this->opponentClassSlug;

            $query->where(fn ($q) => $q
                ->whereHas('enemies.specialization.gameClass', fn ($s) => $s->where('slug', $slug))
                ->orWhereHas('opponentSpec.gameClass', fn ($s) => $s->where('slug', $slug)));
        }

        return $query
            ->when(
                $this->sort === 'new',
                fn ($q) => $q->orderByDesc('created_at'),
                // Likes first, views as the tie-break — a guide nobody has liked yet but forty
                // people have read is still the better of two unliked guides, and views alone
                // would rank whatever got linked the most rather than what people found useful.
                fn ($q) => $q->orderByDesc('like_count')
                    ->orderByDesc('view_count')
                    ->orderByDesc('created_at'),
            )
            ->paginate(self::PER_PAGE);
    }

    public function render()
    {
        return view('livewire.guides.browse')->layout('layouts.app', [
            'title' => 'Player guides | MindCollector',
            'description' => 'Arena guides written by players — comps, openers and matchups, from the people who play them.',
        ]);
    }
}
