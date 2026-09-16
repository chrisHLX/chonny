<?php

namespace App\Livewire\Guides;

use App\Http\Services\UserGuideChainService;
use App\Models\UserGuideSection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One section's "add an ability" palette, mounted inside the guide builder.
 *
 * Split out of Builder on 2026-09-17 for speed. As part of the builder, every click (adding an
 * ability included) rebuilt the open palette and sent it back: ~240ms and ~140KB on a 3-spec
 * guide, for content that adding a step never changes. Livewire leaves a child component alone
 * when its parent re-renders, so now it is built once when the palette opens and is not touched
 * again until the builder's wire:key for it changes (a spec, talent build or opponent changed).
 *
 * It holds no actions of its own. Clicking an ability calls Builder::addSpell() through
 * $wire.$parent, and the builder still does all the checks: whose section it is, and whether this
 * palette really offers that ability.
 */
class Palette extends Component
{
    #[Locked]
    public int $sectionId;

    public function mount(int $sectionId): void
    {
        $section = UserGuideSection::with('guide')->find($sectionId);

        abort_unless($section && $section->guide->isEditableBy(auth()->user()), 403);

        $this->sectionId = $sectionId;
    }

    public function render()
    {
        $section = UserGuideSection::with(['guide', 'opponentSpec.gameClass'])->findOrFail($this->sectionId);

        return view('livewire.guides.palette', [
            'section' => $section,
            'guide' => $section->guide,
            'palette' => app(UserGuideChainService::class)->palette($section),
        ]);
    }
}
