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
 * Spell list = that class/spec's baseline kit (source='baseline', spec_id null-or-matching) +
 * whatever talents the default build actually selects — not literally every talent in every
 * tree (which would be 100+ rows of mostly-unpicked options), but what a real character built
 * this way would actually have available. Uses the same <x-spells.table> component as
 * Modules\Show so both pages render identically.
 */
class SpellExplorer extends Component
{
    public ?int $classId = null;

    public ?int $specId = null;

    public function updatedClassId(): void
    {
        $this->specId = null;
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

        $build = new ModuleGameBuild([
            'class_id' => $this->classId,
            'specialization_id' => $this->specId,
            'hero_talent_tree_id' => $this->detectHeroTreeId($selected),
        ]);

        // Deliberately just the default build's own selections, not "the class's whole baseline
        // kit" — tried merging in source='baseline' availability too, but that data mixes real
        // class abilities with generic system/item spells with no reliable column to tell them
        // apart at the view layer (591 rows for Discipline, including things like "Aberrant
        // Spellforge" and "9.0 Hearthstone Test" — clearly not Priest abilities). This is also a
        // more literal match for what was asked: "pulls the talents from the system default."
        return Spell::whereIn('id', $selected)
            ->with(['effects', 'incomingRelationships.sourceSpell'])
            ->orderBy('name')
            ->get()
            ->map(fn ($spell) => [
                'spell' => $spell,
                'category' => $service->categorize($spell),
                'description' => $service->resolveDescription($spell, $build),
                'modifiers' => $service->modifiersFor($spell, $build, $selected),
                'cooldown' => $service->effectiveCooldown($spell, $build, $selected),
                'charges' => $service->effectiveCharges($spell, $build, $selected),
            ])
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
