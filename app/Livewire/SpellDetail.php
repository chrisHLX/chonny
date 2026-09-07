<?php

namespace App\Livewire;

use App\Http\Services\SpellProfileBuilder;
use App\Livewire\Concerns\TogglesSpellTalents;
use App\Models\PageViewEvent;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Support\SpellProfile;
use Livewire\Component;

/**
 * A permanent, linkable page for one spell — the same SpellProfile the modal renders, at its own
 * URL.
 *
 * The modal answers "what is this thing I just clicked". This answers the other half of the
 * project's stated goal (VISION.md: "the game itself is knowable"): a spell should have a home
 * you can link someone to, come back to, and have a search engine index. Both render
 * <x-spells.detail> with the same profile, so there is exactly one definition of what a spell
 * page shows.
 *
 * Optional ?spec= resolves the build-dependent half against that spec's active talent build, so
 * a link out of /wow-comps or /spells carries its context with it. Without it, base values are
 * shown and labelled as such — never a guess at which build the reader meant.
 */
class SpellDetail extends Component
{
    use TogglesSpellTalents;

    public int $spellId;

    public ?int $specId = null;

    public ?int $classId = null;

    public function mount(int $spellId, ?int $spec = null): void
    {
        $this->spellId = $spellId;

        $spell = Spell::find($spellId);
        abort_if($spell === null, 404);

        // A spec is only honoured when the spell is genuinely available to it — otherwise the
        // page would silently resolve talent-modified numbers against a build that can't cast
        // this spell at all, which reads as real data but isn't.
        if ($spec !== null) {
            $availability = SpellClassAvailability::where('spell_id', $spellId)
                ->where(fn ($q) => $q->where('spec_id', $spec)->orWhereNull('spec_id'))
                ->first();

            if ($availability !== null) {
                $this->specId = $spec;
                $this->classId = $availability->class_id;
            }
        }

        PageViewEvent::log('spell_detail', classId: $this->classId, specId: $this->specId);
    }

    public function getProfileProperty(): ?SpellProfile
    {
        $spell = Spell::find($this->spellId);

        return $spell === null
            ? null
            : app(SpellProfileBuilder::class)->forDetail($spell, $this->classId, $this->specId, $this->talentOverrides);
    }

    public function render()
    {
        $profile = $this->profile;
        abort_if($profile === null, 404);

        $name = $profile->displayName();
        $descriptionParts = array_filter([
            $profile->category,
            $profile->drCategory(),
            $profile->school() ? $profile->school().' school' : null,
        ]);

        return view('livewire.spell-detail', ['profile' => $profile])
            ->layout('layouts.app', [
                'title' => $name.' | MindCollector',
                'description' => $name.' — '.implode(', ', $descriptionParts)
                    .'. Cooldown, duration, what modifies it, what counters it, and which specs have it.',
            ]);
    }
}
