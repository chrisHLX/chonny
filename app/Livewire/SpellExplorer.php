<?php

namespace App\Livewire;

use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\ModuleGameBuild;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\TalentNodeEntry;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Public, class/spec-only counterpart to a canonical module's "Spells" section (see
 * ModuleSpellReferenceService/ModuleGameBuild) — no module involved. Picker mirrors
 * Admin\TalentBuildEditor exactly (class then spec dropdown); the result below is driven by the
 * spec's admin-curated default TalentBuild (Admin\TalentBuildEditor writes it, this only reads
 * it) rather than a module's own linked build or a viewer's personal one — "the system default"
 * build for that spec, same fallback tier resolveActiveBuild() uses when nothing more specific
 * exists. Entirely read-only: never writes anything, never creates a build if one doesn't exist
 * yet (a spec with no admin default configured just shows unmodified base data, same as the
 * existing "empty shell" fallback everywhere else in TalentSelectionService).
 *
 * Spell list = the default build's talent selections + manually-verified baseline abilities
 * (TalentSelectionService::verifiedBaselineAbilityIds() — Leg Sweep, Freezing Trap, etc.) —
 * not literally every talent in every tree (which would be 100+ rows of mostly-unpicked
 * options), but what a real character built this way would actually have available. Uses the
 * same <x-spells.table> component as Modules\Show so both pages render identically.
 *
 * DO NOT merge in TalentSelectionService::alwaysAvailableAbilityIds() — see that method's
 * "DO NOT WIRE IN" docblock and CLAUDE.md's "Baseline ability display" section. That path
 * derives spec from the ambiguous `spec_id = NULL` bucket and leaked Mind Sear onto
 * Discipline Priest (2026-08-06). verifiedBaselineAbilityIds() is the safe replacement: it
 * only ever reads hand-curated, explicit-spec_id rows from
 * data/spelldata/baseline-spec-overrides.txt — small, grows one verified entry at a time,
 * never bulk-derived.
 */
class SpellExplorer extends Component
{
    public ?int $classId = null;

    public ?int $specId = null;

    public function mount(): void
    {
        $firstClass = GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')
            ->first();

        if (!$firstClass) {
            return;
        }

        $this->classId = $firstClass->id;
        $this->specId = Specialization::where('class_id', $firstClass->id)->orderBy('name')->first()?->id;
    }

    public function updatedClassId(): void
    {
        $this->specId = Specialization::where('class_id', $this->classId)->orderBy('name')->first()?->id;
    }

    public function getClassesProperty(): Collection
    {
        return GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')
            ->get();
    }

    public function getSpecializationsProperty(): Collection
    {
        if (!$this->classId) {
            return collect();
        }

        return Specialization::where('class_id', $this->classId)->orderBy('name')->get();
    }

    /**
     * @return array<int, array{spell: Spell, category: string, description: array, modifiers: array, cooldown: array, charges: array}>
     */
    public function getSpellReferencesProperty(): array
    {
        if (!$this->classId || !$this->specId) {
            return [];
        }

        $service = app(ModuleSpellReferenceService::class);
        $talentService = app(TalentSelectionService::class);

        $defaultBuild = TalentBuild::where('spec_id', $this->specId)->where('is_default', true)->first();
        $selected = $defaultBuild ? $talentService->selectedSpellIds($defaultBuild) : collect();
        $ranks = $defaultBuild ? $talentService->selectedRanks($defaultBuild) : collect();

        // The "road not taken" for any CHOICE-node talent the build has a pick for (e.g.
        // Ultimate Penitence vs. Power Word: Barrier) — display-only, never merged into
        // $selected, so an unpicked sibling's own modifiers never look like they're actually
        // applying (see TalentSelectionService::choiceSiblingSpellIds()'s docblock).
        $siblingIds = $talentService->choiceSiblingSpellIds($selected);

        // Manually-verified baseline abilities only — see this class's docblock. NOT
        // alwaysAvailableAbilityIds() (DO NOT WIRE IN, see its own docblock).
        $verifiedBaselineIds = $talentService->verifiedBaselineAbilityIds($this->specId);

        // Explicit-spec_id baseline abilities with a real cooldown/CC mechanic — see
        // TalentSelectionService::explicitBaselineCooldownAbilityIds()'s docblock. Safe
        // (explicit spec_id, no NULL-bucket guessing) but only ever a partial fix for
        // baseline-heavy specs like Demon Hunter/Evoker — see CLAUDE.md.
        $cooldownBaselineIds = $talentService->explicitBaselineCooldownAbilityIds($this->classId, $this->specId);
        $displayIds = $selected->merge($siblingIds)->merge($verifiedBaselineIds)->merge($cooldownBaselineIds)->unique();

        $build = new ModuleGameBuild([
            'class_id' => $this->classId,
            'specialization_id' => $this->specId,
            'hero_talent_tree_id' => $this->detectHeroTreeId($selected),
        ]);

        return Spell::whereIn('id', $displayIds)
            ->with(['effects', 'incomingRelationships.sourceSpell.effects'])
            ->orderBy('name')
            ->get()
            ->map(function ($spell) use ($service, $build, $selected, $ranks, $verifiedBaselineIds, $cooldownBaselineIds) {
                $description = $service->resolveDescription($spell, $build);

                return [
                    'spell' => $spell,
                    'category' => $service->categorize($spell),
                    'description' => $description,
                    'formulaModifiers' => $description['uncertain'] ? $service->variablesModifiers($spell) : collect(),
                    'modifiers' => $service->modifiersFor($spell, $build, $selected, $ranks),
                    'cooldown' => $service->effectiveCooldown($spell, $build, $selected, $ranks),
                    'charges' => $service->effectiveCharges($spell, $build, $selected, $ranks),
                    'isSelected' => $selected->contains($spell->id) || $verifiedBaselineIds->contains($spell->id) || $cooldownBaselineIds->contains($spell->id),
                ];
            })
            ->all();
    }

    /** Same detection TalentSelector::mount() already does for its own hero-tree dropdown default. */
    private function detectHeroTreeId(Collection $selectedSpellIds): ?int
    {
        if ($selectedSpellIds->isEmpty()) {
            return null;
        }

        return TalentNodeEntry::whereIn('spell_id', $selectedSpellIds)
            ->whereHas('talentNode.talentTree', fn ($q) => $q->where('type', 'hero'))
            ->with('talentNode')
            ->first()
            ?->talentNode
            ?->talent_tree_id;
    }

    public function render()
    {
        return view('livewire.spell-explorer', [
            'classes' => $this->classes,
            'specializations' => $this->specializations,
        ])->layout('layouts.app');
    }
}
