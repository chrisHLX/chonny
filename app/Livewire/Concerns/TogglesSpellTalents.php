<?php

namespace App\Livewire\Concerns;

/**
 * The on/off talent switches on a spell detail view, shared by the site-wide modal
 * (SpellDetailModal) and the permanent page (SpellDetail) so both behave identically rather than
 * two hosts each growing their own copy — the exact drift the 2026-09-06 SpellProfile
 * consolidation existed to stop.
 *
 * State is intentionally EPHEMERAL and per-view. Nothing here writes to talent_builds: this is
 * "what would this spell do if I took that talent", not "change my build". A viewer's real saved
 * build (and the spec's admin default behind it) is untouched, and closing the modal or reloading
 * the page returns to it. That keeps the feature safe to use on a page anyone can open, including
 * a guest, with no persistence question to answer at all.
 *
 * $talentOverrides is SPARSE by design — it holds only the rows the viewer explicitly flipped, as
 * selection_spell_id => bool. An empty array therefore means exactly "the build as it is", so the
 * default render path is bit-for-bit what it was before this feature existed. Toggling a row back
 * to whatever the build already said REMOVES it from the array rather than recording a redundant
 * entry, which is what keeps hasTalentOverrides() (and the reset affordance it drives) honest.
 *
 * Keys are the id modifiersFor() actually gates on — see SpellProfile::talentToggles() and
 * ModuleSpellReferenceService::modifiersFor()'s 'selection_spell_id' note for why that is not
 * always the modifier's own spell id.
 */
trait TogglesSpellTalents
{
    /** @var array<int, bool> */
    public array $talentOverrides = [];

    /**
     * $isCurrentlyActive is passed in from the row being clicked rather than re-derived here: the
     * view already knows it (it is what the switch is rendering), and re-computing it would mean
     * rebuilding the whole profile just to decide what a click meant.
     */
    public function toggleTalent(int $selectionSpellId, bool $isCurrentlyActive): void
    {
        // An override only ever exists because it disagreed with the build, so clicking a row
        // that already has one necessarily puts it back where the build had it — drop the entry
        // rather than store a redundant one, which is what keeps hasTalentOverrides() (and the
        // reset affordance) meaning "you have actually changed something".
        if (array_key_exists($selectionSpellId, $this->talentOverrides)) {
            unset($this->talentOverrides[$selectionSpellId]);

            return;
        }

        $this->talentOverrides[$selectionSpellId] = ! $isCurrentlyActive;
    }

    public function resetTalentOverrides(): void
    {
        $this->talentOverrides = [];
    }
}
