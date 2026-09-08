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
 * SORTS BY RATING BY DEFAULT, not recency. A listing that leads with the newest thing rewards
 * posting; one that leads with the best-rated rewards writing something worth reading, which is
 * the only version of this feature worth having. "Newest" is still offered, because a brand new
 * guide with no ratings yet has to be findable or nothing ever gets its first rating.
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

    #[Url(except: 'rating')]
    public string $sort = 'rating';

    public function mount(): void
    {
        PageViewEvent::log('guides_browse');
    }

    public function updated($property): void
    {
        // Any filter change invalidates the page number — staying on page 4 of a result set that
        // now has one page shows an empty listing and reads as "no results".
        if (in_array($property, ['search', 'classSlug', 'sort'], true)) {
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
            ->with(['user', 'members.specialization.gameClass', 'enemies.specialization.gameClass'])
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

        return $query
            ->when(
                $this->sort === 'new',
                fn ($q) => $q->orderByDesc('created_at'),
                // Unrated last rather than first: "nobody has said" is weaker evidence than a low
                // score, but it must not outrank a guide people actually liked.
                fn ($q) => $q->orderByRaw('rating_avg IS NULL, rating_avg DESC')
                    ->orderByDesc('rating_count')
                    ->orderByDesc('created_at'),
            )
            ->paginate(self::PER_PAGE);
    }

    public function render()
    {
        return view('livewire.guides.browse')->layout('layouts.app', [
            'title' => 'Player guides | MindCollector',
            'description' => 'Arena guides written by players — comps, openers and matchups, rated by the people who used them.',
        ]);
    }
}
