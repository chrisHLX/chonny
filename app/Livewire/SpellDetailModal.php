<?php

namespace App\Livewire;

use App\Http\Services\SpellProfileBuilder;
use App\Livewire\Concerns\TogglesSpellTalents;
use App\Models\Spell;
use App\Support\SpellProfile;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Site-wide, spell-ID-driven spell detail modal. Mount ONCE per host page:
 *
 *   <livewire:spell-detail-modal/>
 *
 * then from anywhere on that page, open it by dispatching a browser event with the spell's
 * internal id (Spell::$id, not the external spell_id) and optional class/spec context:
 *
 *   wire:click="$dispatch('show-spell-detail', { spellId: {{ $spell->id }}, classId: ..., specId: ... })"
 *
 * Genuinely lazy — only the ONE currently-open spell is ever resolved, not one hidden block per
 * spell on the page (which is what WowComps' original per-page version did before this was
 * extracted out of it in 2026-08-11).
 *
 * As of the 2026-09-06 consolidation this component owns no spell logic at all. It previously
 * assembled its own rich entry array — a second, independently-drifting definition of "a spell"
 * alongside SpecKitComputer's (this one had CC immunity and no isPriority/offensiveDefensive/
 * source; that one had the reverse), plus a byte-identical private copy of enrichModifiers().
 * Both now come from SpellProfileBuilder, so the modal, the /spell/{id} page and every kit render
 * show the same facts about the same spell by construction rather than by anyone remembering to
 * update three places.
 *
 * classId/specId stay optional and are passed through unchanged: with a spec, values are resolved
 * against that spec's active talent build; without one (e.g. /cc-review, which isn't scoped to a
 * spec) the profile carries base, unmodified values rather than guessing a build.
 */
class SpellDetailModal extends Component
{
    use TogglesSpellTalents;

    public ?int $spellId = null;

    public ?int $classId = null;

    public ?int $specId = null;

    #[On('show-spell-detail')]
    public function show(int $spellId, ?int $classId = null, ?int $specId = null): void
    {
        $this->spellId = $spellId;
        $this->classId = $classId;
        $this->specId = $specId;
        // Overrides are scoped to the spell being looked at — opening a different one (including
        // via the counter chips inside this very modal) must not carry the last spell's
        // experimentation across to a completely unrelated set of talents.
        $this->resetTalentOverrides();
    }

    public function close(): void
    {
        $this->spellId = null;
        $this->classId = null;
        $this->specId = null;
        $this->resetTalentOverrides();
    }

    public function getProfileProperty(): ?SpellProfile
    {
        if ($this->spellId === null) {
            return null;
        }

        $spell = Spell::find($this->spellId);

        return $spell === null
            ? null
            : app(SpellProfileBuilder::class)->forDetail($spell, $this->classId, $this->specId, $this->talentOverrides);
    }

    public function render()
    {
        return view('livewire.spell-detail-modal', [
            // Named 'entry' for continuity with the blade this replaced; a SpellProfile answers
            // every $entry['...'] key that template already used (see SpellProfile's ArrayAccess
            // bridge) while also exposing the typed accessors the richer sections need.
            'entry' => $this->profile,
            'specId' => $this->specId,
        ]);
    }
}
