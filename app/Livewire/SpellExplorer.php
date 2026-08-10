<?php

namespace App\Livewire;

use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\ModuleGameBuild;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\TalentNodeEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Public, class/spec-only counterpart to a canonical module's "Spells" section (see
 * ModuleSpellReferenceService/ModuleGameBuild) — no module involved. Picker mirrors
 * Admin\TalentBuildEditor exactly (class then spec dropdown); the result below is driven by
 * TalentSelectionService::resolveActiveBuild() — as of the personal talent picker (2026-08-10),
 * a signed-in viewer's own saved build for the chosen spec if they have one (editable in-place
 * via the "Edit My Talents" modal, see showTalentPicker/openPicker()/closePicker() and
 * talent-tree-grid.blade.php), else the spec's admin-curated default (Admin\TalentBuildEditor
 * writes that one), else the unmodified base data fallback resolveActiveBuild() already had.
 * This component itself never creates a build — opening the modal doesn't write anything until
 * a talent is actually clicked inside it (TalentSelector's own persistIfAuthenticated()).
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

    /** Whether the talent-picker modal is open — always for the currently-selected $specId. */
    public bool $showTalentPicker = false;

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

        // Bare page view only — NOT attributed to the default (alphabetically-first) class/spec
        // shown on landing, since every visitor lands there regardless of interest. Attributing
        // it would repeat the exact "page load counted as a real choice" bug just fixed in
        // Admin\DiagnosticStats (see CLAUDE.md/this session) — the default class would look
        // artificially popular. Only an explicit pick (below) counts as a real class/spec view.
        PageViewEvent::log('spell_explorer');
    }

    public function updatedClassId(): void
    {
        $this->specId = Specialization::where('class_id', $this->classId)->orderBy('name')->first()?->id;

        PageViewEvent::log('spell_explorer', $this->classId, $this->specId);
    }

    public function updatedSpecId(): void
    {
        PageViewEvent::log('spell_explorer', $this->classId, $this->specId);
        $this->showTalentPicker = false;
    }

    public function openTalentPicker(): void
    {
        $this->showTalentPicker = true;
    }

    public function closeTalentPicker(): void
    {
        // Closing is itself a Livewire action, so getSpellReferencesProperty() below recomputes
        // fresh on the very next render — no separate refresh/event plumbing needed for the
        // underlying Spells table to pick up whatever was just saved in the modal.
        $this->showTalentPicker = false;
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

    /** Drives the page's descriptive text — whether the currently-shown kit is this viewer's own saved picks or the spec's admin default. Cheap exists() check, separate from the full resolveActiveBuild() call inside getSpellReferencesProperty() below (that one needs the full build row; this only needs to know which case applies). */
    public function getUsingPersonalBuildProperty(): bool
    {
        if (!$this->specId || !auth()->check()) {
            return false;
        }

        return TalentBuild::where('user_id', auth()->id())->where('spec_id', $this->specId)->exists();
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

        // Redis-cached, 2026-08-08 — same rationale as WowComps::spellReferencesFor() (see that
        // method's docblock for the full explanation): as of the personal talent picker
        // (2026-08-10), the build resolved below is this viewer's own saved build for $specId if
        // they have one, else the spec's admin default, exactly as before. The cache key varies
        // on the resolved build's own id+updated_at so a personal-build save only invalidates
        // that one viewer's own cache entry; a guest/no-personal-build viewer still shares the
        // one admin-default entry with everyone else, invalidated via spellCacheVersion as before.
        $build = $talentService->resolveActiveBuild(auth()->user(), $this->specId);
        $defaultBuild = $build->exists ? $build : null;
        $buildStamp = $defaultBuild ? "{$defaultBuild->id}:{$defaultBuild->updated_at?->timestamp}" : 'none';
        $version = $talentService->spellCacheVersion();

        return Cache::remember(
            "wow_spell_references:spec:{$this->specId}:build:{$buildStamp}:v{$version}",
            now()->addHours(6),
            fn () => $this->computeSpellReferences($service, $talentService, $defaultBuild)
        );
    }

    /**
     * @return array<int, array{spell: Spell, category: string, description: array, modifiers: array, cooldown: array, charges: array, isSelected: bool}>
     */
    private function computeSpellReferences(ModuleSpellReferenceService $service, TalentSelectionService $talentService, ?TalentBuild $defaultBuild): array
    {
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
