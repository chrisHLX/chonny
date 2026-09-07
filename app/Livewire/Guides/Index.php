<?php

namespace App\Livewire\Guides;

use App\Enums\UserGuideSectionKind;
use App\Enums\UserGuideStatus;
use App\Enums\UserGuideVisibility;
use App\Models\PageViewEvent;
use App\Models\UserGuide;
use App\Models\UserGuideSection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A user's own guides, plus the private ones other people have shared with them.
 *
 * Deliberately not a public browse of everyone's guides: nothing user-authored is listed site-wide
 * yet. A public guide is reachable by its link (see Guides\Show), which is a decision the author
 * makes per guide — a global listing is a separate product decision about promoting player content
 * alongside derived content, and is not implied by "let people share a link".
 */
class Index extends Component
{
    #[Computed]
    public function guides()
    {
        return auth()->user()
            ->guides()
            ->with(['members.specialization.gameClass', 'sections'])
            ->orderByDesc('updated_at')
            ->get();
    }

    #[Computed]
    public function shared()
    {
        return auth()->user()
            ->sharedGuides()
            ->with(['user', 'members.specialization.gameClass'])
            ->where('status', UserGuideStatus::Published->value)
            ->orderByDesc('user_guides.updated_at')
            ->get();
    }

    public function mount(): void
    {
        PageViewEvent::log('guides_index');
    }

    /**
     * Create a draft with one starter section and go straight to the builder.
     *
     * The starter section exists so the builder is never a blank page with no obvious first move —
     * the chosen kind is what the author said they wanted to write, so it is also the section they
     * are most likely to fill in first. They can rename, delete or add to it freely; nothing about
     * the guide is fixed to that kind (which is exactly why kind lives on the section).
     *
     * No "name it first" step: a guide is easier to title once it exists, and a draft is private by
     * default. The placeholder title is what the slug is generated from, and the slug never changes
     * afterwards (see UserGuide::booted()), so it is written to be a reasonable permanent URL.
     */
    public function create(string $kind)
    {
        $sectionKind = UserGuideSectionKind::tryFrom($kind);
        if ($sectionKind === null) {
            return null;
        }

        $guide = UserGuide::create([
            'user_id' => auth()->id(),
            'status' => UserGuideStatus::Draft,
            'visibility' => UserGuideVisibility::Invited,
            'title' => match ($sectionKind) {
                UserGuideSectionKind::Go => 'Untitled go',
                UserGuideSectionKind::Chain => 'Untitled CC chain',
                default => 'Untitled guide',
            },
        ]);

        UserGuideSection::create([
            'user_guide_id' => $guide->id,
            'kind' => $sectionKind,
            'title' => $sectionKind->label(),
            'row' => 0,
            'column' => 0,
        ]);

        return $this->redirectRoute('guides.edit', ['guide' => $guide->slug], navigate: true);
    }

    public function delete(int $guideId): void
    {
        // Scoped to the author's own guides, so an id from anywhere else simply matches nothing.
        auth()->user()->guides()->where('id', $guideId)->first()?->delete();

        unset($this->guides);
    }

    public function render()
    {
        return view('livewire.guides.index')
            ->layout('layouts.app', [
                'title' => 'My Guides | MindCollector',
                'description' => 'Your own arena guides — CC chains, gos, and matchup notes.',
            ]);
    }
}
