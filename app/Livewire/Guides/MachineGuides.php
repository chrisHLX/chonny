<?php

namespace App\Livewire\Guides;

use App\Models\PageViewEvent;
use App\Models\UserGuide;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "Claude's Comp Guides" — every published guide a model wrote, on its own page.
 *
 * DELIBERATELY NOT MIXED INTO /browse-guides OR THE FEED. Everything else on this site is either
 * derived from real match evidence or written by a player, and both of those earn their weight
 * differently. A model's draft is a third thing: the mechanics under it are derived, the plan on
 * top of it is a guess. Listing it beside player guides would make the two compete on the same
 * terms, which is the exact framing problem Guides\Show's own docblock explains at /g/.
 *
 * The point of these guides is to BE CORRECTED. They are specific enough to be wrong, every step
 * takes an anchored note (see the note-thread component), and those notes are exported back with
 * `guides:export-feedback` and used to correct arena-structure.md — which is where this project
 * keeps what it knows. That loop is the reason the page exists; the guides are the bait.
 *
 * Separate from /wow/claudes-guides, which is the same idea one level down: hand-authored JSON
 * per class/spec, rendered read-only with no comments. These are real user_guides rows, so they
 * carry a comp, live cooldowns, DR maths and a comment thread per step.
 */
class MachineGuides extends Component
{
    #[Computed]
    public function guides()
    {
        return UserGuide::listed()
            ->machineAuthored()
            ->with(['members.specialization.gameClass', 'enemies.specialization.gameClass', 'user'])
            ->withCount('comments')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function mount(): void
    {
        PageViewEvent::log('machine_guides');
    }

    public function render()
    {
        return view('livewire.guides.machine-guides')->layout('layouts.app', [
            'title' => "Claude's Comp Guides | MindCollector",
            'description' => 'Arena comp guides drafted by a model from MindCollector\'s game data and real '
                .'match windows. The mechanics are derived; the plan is a guess, and every step can be corrected.',
        ]);
    }
}
