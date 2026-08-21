<?php

namespace App\Livewire;

use App\Http\Services\ArenaLogService;
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
 * ModuleSpellReferenceService/ModuleGameBuild) — no module involved.
 *
 * REWORKED 2026-08-16: every real talent-tree entry and every PvP talent for the chosen spec
 * is ALWAYS shown now (tagged 'Talent'/'PvP Talent' — see the 'source' key on each mapped
 * entry below), not just whatever one curated build happened to have selected. The old
 * behavior — showing only a resolved build's picks plus CHOICE-node "siblings" — meant a
 * talent nobody had gotten around to selecting in the admin-default build was completely
 * invisible, with no way to distinguish "doesn't exist for this spec" from "exists but
 * uncurated." Concrete case that prompted this: Hex was missing from Enhancement Shaman's
 * display (verified_override existed for Restoration/Elemental, never finished for
 * Enhancement) — a curation gap, not a data gap.
 *
 * A resolved build (TalentSelectionService::resolveActiveBuild() — signed-in viewer's own
 * saved build for the spec, else the spec's admin-curated default, else an empty shell) is
 * now purely an OVERLAY: it decides which entries render highlighted vs. greyed "Not
 * selected," and feeds ModuleSpellReferenceService::effectiveCooldown()/effectiveCharges()/
 * modifiersFor() so a selected entry shows accurate build-aware numbers while an unselected
 * one shows base numbers. Since nothing is hidden anymore, the interactive talent-picker
 * modal that used to live on this page (showTalentPicker/openTalentPicker()/
 * closeTalentPicker()) has been removed — same reasoning that already removed it from
 * Modules\Show on 2026-08-01. A pre-existing personal build (created before this change, via
 * that now-removed picker) still overlays correctly; it just can no longer be edited from
 * this page. Admin\TalentBuildEditor remains the only place that edits the admin-default
 * overlay builds.
 *
 * DO NOT merge in TalentSelectionService::alwaysAvailableAbilityIds() — see that method's
 * "DO NOT WIRE IN" docblock and CLAUDE.md's "Baseline ability display" section. That path
 * derives spec from the ambiguous `spec_id = NULL` bucket and leaked Mind Sear onto
 * Discipline Priest (2026-08-06). verifiedBaselineAbilityIds()/explicitBaselineCooldownAbilityIds()
 * remain the safe path for baseline (never-a-talent) abilities — hand-curated, explicit-spec_id
 * rows only. allTalentSpellIds()/allPvpTalentSpellIds() (new 2026-08-16) are structurally safe
 * in the same way baseline never was: a talent_node_entries/pvp_talents row is tied to a real
 * talent_tree_id whose class/spec/hero-tree scoping is genuine imported structure, not a guess.
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
        $this->dispatch('spell-list-refreshed');
    }

    public function updatedSpecId(): void
    {
        PageViewEvent::log('spell_explorer', $this->classId, $this->specId);
        $this->dispatch('spell-list-refreshed');
    }

    /**
     * Sets class + spec in one action — the shared class/spec grid modal's click target, same
     * component and interaction WowComps::selectSpec() uses for its own slot picker (2026-08-17,
     * direct request to match that picker's UX). Logs directly rather than relying on
     * updated{Property}() to fire for a method-mutated property — same reasoning as
     * WowComps::selectSpec()'s own docblock. updatedClassId()/updatedSpecId() above are kept
     * as-is for the original wire:model-driven path (still covered by
     * tests/Feature/Admin/PageUsageTrackingTest.php's ->set('classId', ...) cases).
     */
    public function selectSpec(int $classId, int $specId): void
    {
        $this->classId = $classId;
        $this->specId = $specId;

        PageViewEvent::log('spell_explorer', $this->classId, $this->specId);
        $this->dispatch('spell-list-refreshed');
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
     * Every class with its specs eager-loaded, ordered — backs the combined class/spec grid
     * modal (search + click straight to a spec). Same shape and purpose as
     * WowComps::getClassSpecsProperty(); this page only ever has one "slot" to fill.
     */
    public function getClassSpecsProperty(): Collection
    {
        return $this->classes->load(['specializations' => fn ($q) => $q->orderBy('name')]);
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
        // 'isPriority' (2026-08-17, arena-log spell-usage tagging) is also baked into this same
        // cached payload — same known staleness class already documented elsewhere for this
        // cache (see CLAUDE.md's repeated "stale Redis cache" notes): a data/arena-logs/
        // spell-usage/ file changing does NOT bump spellCacheVersion() on its own, so a spec
        // already cached before a new match is processed keeps showing the old priority set
        // until the 6-hour TTL expires or spellCacheVersion() is bumped manually.
        $build = $talentService->resolveActiveBuild(auth()->user(), $this->specId);
        $defaultBuild = $build->exists ? $build : null;
        $buildStamp = $defaultBuild ? "{$defaultBuild->id}:{$defaultBuild->updated_at?->timestamp}" : 'none';
        $version = $talentService->spellCacheVersion();

        $this->ensureMemoryHeadroom();

        return Cache::remember(
            "wow_spell_references:spec:{$this->specId}:build:{$buildStamp}:v{$version}",
            now()->addHours(6),
            fn () => $this->computeSpellReferences($service, $talentService, $defaultBuild)
        );
    }

    /**
     * Raises (never lowers) PHP's memory_limit before computing/caching this spec's full spell
     * reference set — same fix, same rationale as WowComps::ensureMemoryHeadroom() (see that
     * method's docblock for the full incident). This page only ever computes one spec per
     * render (vs. WowComps' up to 3), so it's less exposed in practice, but shares the identical
     * ~250-entry-per-spec computation since the 2026-08-16 "always show every talent" rework —
     * applied proactively here too rather than waiting for a separate report.
     */
    private function ensureMemoryHeadroom(string $minimum = '512M'): void
    {
        $toBytes = function (string $value): int {
            $value = trim($value);
            if ($value === '' || $value === '-1') {
                return -1;
            }
            $unit = strtolower(substr($value, -1));
            $number = (int) $value;

            return match ($unit) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
                default => $number,
            };
        };

        $current = $toBytes((string) ini_get('memory_limit'));

        if ($current !== -1 && $current < $toBytes($minimum)) {
            ini_set('memory_limit', $minimum);
        }
    }

    /**
     * @return array<int, array{spell: Spell, category: string, description: array, modifiers: array, cooldown: array, charges: array, isSelected: bool, source: string, isPriority: bool}>
     */
    private function computeSpellReferences(ModuleSpellReferenceService $service, TalentSelectionService $talentService, ?TalentBuild $defaultBuild): array
    {
        $selected = $defaultBuild ? $talentService->selectedSpellIds($defaultBuild) : collect();
        $ranks = $defaultBuild ? $talentService->selectedRanks($defaultBuild) : collect();

        // Always-shown display set — see this class's docblock (reworked 2026-08-16). Every
        // real talent-tree entry and every PvP talent for the spec, regardless of whether the
        // resolved overlay build ($selected) picked it. choiceSiblingSpellIds() is no longer
        // needed here: allTalentSpellIds() already includes every option of every CHOICE node
        // unconditionally, not just the unpicked side of a node that HAS a pick.
        $allTalentIds = $talentService->allTalentSpellIds($this->specId);
        $allPvpIds = $talentService->allPvpTalentSpellIds($this->specId);

        // Manually-verified baseline abilities only — see this class's docblock. NOT
        // alwaysAvailableAbilityIds() (DO NOT WIRE IN, see its own docblock).
        $verifiedBaselineIds = $talentService->verifiedBaselineAbilityIds($this->specId);

        // Explicit-spec_id baseline abilities with a real cooldown/CC mechanic — see
        // TalentSelectionService::explicitBaselineCooldownAbilityIds()'s docblock. Safe
        // (explicit spec_id, no NULL-bucket guessing) but only ever a partial fix for
        // baseline-heavy specs like Demon Hunter/Evoker — see CLAUDE.md.
        $cooldownBaselineIds = $talentService->explicitBaselineCooldownAbilityIds($this->classId, $this->specId);
        $displayIds = $allTalentIds->merge($allPvpIds)->merge($verifiedBaselineIds)->merge($cooldownBaselineIds)->unique();

        $class = GameClass::find($this->classId);
        $spec = Specialization::find($this->specId);
        $arenaLogService = app(ArenaLogService::class);
        $priorityExternalIds = ($class && $spec)
            ? $arenaLogService->spellUsageIds($class->slug, $spec->slug)
            : collect();

        $build = new ModuleGameBuild([
            'class_id' => $this->classId,
            'specialization_id' => $this->specId,
            'hero_talent_tree_id' => $this->detectHeroTreeId($selected),
        ]);

        $spells = Spell::whereIn('id', $displayIds)
            ->with(['effects', 'incomingRelationships.sourceSpell.effects'])
            ->orderBy('name')
            ->get();

        // Collapses same-name duplicate spell_id copies down to one entry (e.g. Secret
        // Technique's real press + its shadow-clone spell_id, both structurally reachable via
        // the display-id union above) — see preferSelectedPerName()'s own docblock. Added
        // 2026-08-21 after a real report of duplicate cards on this page; this method already
        // existed for exactly this problem but was never wired into the 2026-08-16
        // "always show every talent" rework, which is what reintroduced the duplicates.
        $spells = $talentService->preferSelectedPerName($spells, $selected);

        // Bulk-resolves what would otherwise be one query per spell for both of these — see
        // each method's own docblock (ModuleSpellReferenceService::preloadBaseCooldownCharges()/
        // ArenaLogService::preloadPrioritySpells()) for the profiling that found this. Must run
        // before the map() below so its per-spell calls hit an already-primed memo/map instead
        // of querying individually.
        $service->preloadBaseCooldownCharges($spells);
        $service->preloadCategorize($spells);
        $priorityBySpellId = $arenaLogService->preloadPrioritySpells($spells, $priorityExternalIds);

        return $spells
            ->map(function ($spell) use ($service, $build, $selected, $ranks, $verifiedBaselineIds, $cooldownBaselineIds, $allTalentIds, $allPvpIds, $priorityBySpellId) {
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
                    'source' => $allTalentIds->contains($spell->id) ? 'talent' : ($allPvpIds->contains($spell->id) ? 'pvp_talent' : 'baseline'),
                    'isPriority' => $priorityBySpellId[$spell->id] ?? false,
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
        // FIXED 2026-08-12: 'usingPersonalBuild' used to rely on Livewire's automatic
        // computed-property injection instead of being passed explicitly here — the same
        // unreliable-auto-injection gotcha already documented for SpellDetailModal
        // (getXProperty() methods are NOT guaranteed to auto-expose as bare $x variables in the
        // view). It was silently undefined the whole time; the bug only became visible once a
        // separate Blade-compiler issue that had been swallowing that code path was fixed — see
        // spell-explorer.blade.php's docblock on $spellsTableDescription and CLAUDE.md.
        return view('livewire.spell-explorer', [
            'classes' => $this->classes,
            'specializations' => $this->specializations,
            'classSpecs' => $this->classSpecs,
            'usingPersonalBuild' => $this->usingPersonalBuild,
        ])->layout('layouts.app');
    }
}
