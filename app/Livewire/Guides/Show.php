<?php

namespace App\Livewire\Guides;

use App\Http\Services\UserGuideChainService;
use App\Models\PageViewEvent;
use App\Models\User;
use App\Models\UserGuide;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The read view for a shared guide, at /g/{username}/{slug}.
 *
 * DELIBERATELY ITS OWN ROUTE NAMESPACE, not alongside the site's derived guides. Everything else on
 * this site earns its credibility from being derived from real match evidence and never guessed —
 * curation files, verified overrides, the standing flag-don't-guess rule. A player-written guide is
 * the opposite trust tier by construction, and if it rendered at the same URL shape with the same
 * framing as a corpus-derived guide it would spend that credibility. So the URL names its author,
 * the page names its author, and the page says plainly that this is one player's plan rather than
 * measured data. That separation is cheap now and expensive to retrofit once guides are linked.
 *
 * Access is decided entirely by UserGuide::isReadableBy() — the author, anyone for a public
 * published guide, and only explicitly invited accounts for a private one. A draft is visible to
 * nobody but its author, so an unfinished guide cannot leak through a guessed URL.
 */
class Show extends Component
{
    public UserGuide $guide;

    public function mount(string $username, UserGuide $guide): void
    {
        // The username in the URL must actually be this guide's author. Without this check the
        // slug alone would resolve, and any username would serve any author's guide — a broken
        // canonical URL, and a confusing one to share.
        $author = User::where('username', $username)->first();
        abort_unless($author && $author->id === $guide->user_id, 404);

        // 404 rather than 403 for a guide the viewer may not read: a 403 confirms the guide exists,
        // which for a private guide leaks the fact that this person wrote something at this URL.
        abort_unless($guide->isReadableBy(auth()->user()), 404);

        $this->guide = $guide;

        PageViewEvent::log('guide_show');
    }

    #[Computed]
    public function rows()
    {
        return $this->guide->sections()->with('opponentSpec.gameClass')->get()->groupBy('row');
    }

    #[Computed]
    public function members()
    {
        return $this->guide->members()->with('specialization.gameClass')->get();
    }

    /** Resolved steps + metrics per section — the same service the builder renders through. */
    #[Computed]
    public function resolved(): array
    {
        $svc = app(UserGuideChainService::class);
        $out = [];

        foreach ($this->guide->sections()->get() as $section) {
            if (! $section->kind->isSequence()) {
                continue;
            }

            $out[$section->id] = [
                'steps' => $svc->resolve($section),
                'metrics' => $svc->metrics($section),
            ];
        }

        return $out;
    }

    public function render()
    {
        $comp = $this->members
            ->map(fn ($m) => $m->specialization?->name.' '.$m->specialization?->gameClass?->name)
            ->filter()
            ->implode(' / ');

        return view('livewire.guides.show')->layout('layouts.app', [
            'title' => "{$this->guide->title} by {$this->guide->user?->username} | MindCollector",
            'description' => $this->guide->summary
                ?: trim('A player-written arena guide'.($comp ? " for {$comp}" : '').'.'),
        ]);
    }
}
