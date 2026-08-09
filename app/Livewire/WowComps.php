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
 * Phase-1 shape-check page: pick 3 specs (Healer / DPS / DPS slots — labels only, nothing
 * enforces role) and see each one's spell kit side by side for comparison, grouped
 * Offensive/Defensive/Utility/Crowd Control/Other same as Spell Explorer, plus a "Main
 * Cooldowns" summary per member. Deliberately no comps table, no spell_functions table, no
 * seeding — this exists purely to get the picker/layout shape right before any of that schema
 * work happens. Read-only, same data source as SpellExplorer (each spec's admin-curated
 * default TalentBuild via TalentSelectionService).
 */
class WowComps extends Component
{
    public array $slots = [
        ['label' => 'Healer', 'classId' => null, 'specId' => null],
        ['label' => 'DPS', 'classId' => null, 'specId' => null],
        ['label' => 'DPS', 'classId' => null, 'specId' => null],
    ];

    public function mount(): void
    {
        PageViewEvent::log('wow_comps');
    }

    public function updated(string $name): void
    {
        if (preg_match('/^slots\.(\d+)\.classId$/', $name, $m)) {
            $index = (int) $m[1];
            $this->slots[$index]['specId'] = Specialization::where('class_id', $this->slots[$index]['classId'])
                ->orderBy('name')
                ->first()?->id;

            $this->logSlotSelection($index);
            return;
        }

        if (preg_match('/^slots\.(\d+)\.specId$/', $name, $m)) {
            $this->logSlotSelection((int) $m[1]);
        }
    }

    private function logSlotSelection(int $index): void
    {
        PageViewEvent::log(
            'wow_comps',
            $this->slots[$index]['classId'],
            $this->slots[$index]['specId'],
            (string) $index
        );
    }

    public function getClassesProperty(): Collection
    {
        return GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Every class with its specs eager-loaded, ordered — backs the combined spec-picker
     * flyout (search + click straight to a spec, e.g. "Discipline", instead of a two-step
     * class-then-spec select). Shared across all 3 slots since it's the same static list.
     */
    public function getClassSpecsProperty(): Collection
    {
        return $this->classes->load(['specializations' => fn ($q) => $q->orderBy('name')]);
    }

    /**
     * Sets a slot's class + spec in one action (the flyout picker's click target) instead of
     * the class-first-then-spec two-step the plain <select> pair required. Logs directly
     * rather than relying on updated() to fire for a method-mutated property — updated() is
     * kept as-is below for the original wire:model-driven path (still covered by
     * tests/Feature/Admin/PageUsageTrackingTest.php's ->set('slots.0.classId', ...) case).
     */
    public function selectSpec(int $index, int $classId, int $specId): void
    {
        $this->slots[$index]['classId'] = $classId;
        $this->slots[$index]['specId'] = $specId;

        $this->logSlotSelection($index);
    }

    /**
     * @return array<int, array{label: string, class: ?GameClass, spec: ?Specialization, entries: array}>
     */
    public function getCompProperty(): array
    {
        $service = app(ModuleSpellReferenceService::class);
        $talentService = app(TalentSelectionService::class);
        $classes = $this->classes;

        return collect($this->slots)->map(function ($slot) use ($service, $talentService, $classes) {
            $class = $classes->firstWhere('id', $slot['classId']);
            $spec = $slot['specId'] ? Specialization::find($slot['specId']) : null;
            $entries = $spec ? $this->spellReferencesFor($spec, $service, $talentService) : [];

            return [
                'label' => $slot['label'],
                'class' => $class,
                'spec' => $spec,
                'entries' => $entries,
            ];
        })->all();
    }

    /**
     * @return array<int, array{spell: Spell, category: string, description: array, modifiers: array, cooldown: array, charges: array, isSelected: bool}>
     */
    private function spellReferencesFor(Specialization $spec, ModuleSpellReferenceService $service, TalentSelectionService $talentService): array
    {
        // Redis-cached, 2026-08-08 — this computation (description resolution, categorization,
        // modifiers, cooldown/charges for every spell in a spec's kit) was the confirmed source
        // of WowComps's 45.8s/~5,000-query cold render (see CLAUDE.md's "same-day performance
        // fix" note) — the earlier fix only removed *redundant* recomputation within one request
        // via ModuleSpellReferenceService's per-instance memoization; every fresh page load still
        // recomputed everything from scratch. The result depends only on the spec's admin
        // DEFAULT talent build (never a viewer's own) and the imported spell data, so it's safe
        // to share across requests/users — invalidated via TalentSelectionService's version
        // counter (bumped on any default-build write or spelldata re-import), not a TTL guess.
        $version = $talentService->spellCacheVersion();

        return Cache::remember(
            "wow_spell_references:spec:{$spec->id}:v{$version}",
            now()->addHours(6),
            fn () => $this->computeSpellReferencesFor($spec, $service, $talentService)
        );
    }

    /**
     * @return array<int, array{spell: Spell, category: string, description: array, modifiers: array, cooldown: array, charges: array, isSelected: bool}>
     */
    private function computeSpellReferencesFor(Specialization $spec, ModuleSpellReferenceService $service, TalentSelectionService $talentService): array
    {
        $defaultBuild = TalentBuild::where('spec_id', $spec->id)->where('is_default', true)->first();
        $selected = $defaultBuild ? $talentService->selectedSpellIds($defaultBuild) : collect();
        $ranks = $defaultBuild ? $talentService->selectedRanks($defaultBuild) : collect();

        // The "road not taken" for any CHOICE-node talent the build has a pick for (e.g.
        // Ultimate Penitence vs. Power Word: Barrier) — display-only, never merged into
        // $selected, so an unpicked sibling's own modifiers never look like they're actually
        // applying (see TalentSelectionService::choiceSiblingSpellIds()'s docblock).
        $siblingIds = $talentService->choiceSiblingSpellIds($selected);

        // Manually-verified baseline abilities only (Leg Sweep, Freezing Trap, ...) — NOT
        // TalentSelectionService::alwaysAvailableAbilityIds() (see that method's "DO NOT WIRE
        // IN" banner and CLAUDE.md's "Baseline ability display" section — that path derives
        // from the ambiguous spec_id=NULL bucket and leaked Mind Sear onto Discipline Priest).
        // verifiedBaselineAbilityIds() only ever reads explicit-spec_id, hand-curated rows —
        // safe by construction, just small (grows one verified entry at a time).
        $verifiedBaselineIds = $talentService->verifiedBaselineAbilityIds($spec->id);

        // Explicit-spec_id baseline abilities with a real cooldown/CC mechanic — see
        // TalentSelectionService::explicitBaselineCooldownAbilityIds()'s docblock. Safe
        // (explicit spec_id, no NULL-bucket guessing) but only ever a partial fix for
        // baseline-heavy specs like Demon Hunter/Evoker — see CLAUDE.md.
        $cooldownBaselineIds = $talentService->explicitBaselineCooldownAbilityIds($spec->class_id, $spec->id);
        $displayIds = $selected->merge($siblingIds)->merge($verifiedBaselineIds)->merge($cooldownBaselineIds)->unique();

        $build = new ModuleGameBuild([
            'class_id' => $spec->class_id,
            'specialization_id' => $spec->id,
            'hero_talent_tree_id' => $this->detectHeroTreeId($selected),
        ]);

        return Spell::whereIn('id', $displayIds)
            ->with(['effects', 'incomingRelationships.sourceSpell.effects'])
            ->orderBy('name')
            ->get()
            ->map(function ($spell) use ($service, $build, $selected, $ranks, $verifiedBaselineIds, $cooldownBaselineIds) {
                $description = $service->resolveDescription($spell, $build);
                $modifiers = $service->modifiersFor($spell, $build, $selected, $ranks);

                return [
                    'spell' => $spell,
                    'category' => $service->categorize($spell),
                    'description' => $description,
                    'formulaModifiers' => $description['uncertain'] ? $service->variablesModifiers($spell) : collect(),
                    'modifiers' => [
                        'named' => $this->enrichModifiers($modifiers['named'], $service, $build, $selected, $ranks),
                        'baseline' => $this->enrichModifiers($modifiers['baseline'], $service, $build, $selected, $ranks),
                    ],
                    'cooldown' => $service->effectiveCooldown($spell, $build, $selected, $ranks),
                    'charges' => $service->effectiveCharges($spell, $build, $selected, $ranks),
                    // Verified/explicit-spec baseline abilities are never talent-gated, so
                    // they read as "selected" (normal opacity) regardless of the talent build.
                    'isSelected' => $selected->contains($spell->id) || $verifiedBaselineIds->contains($spell->id) || $cooldownBaselineIds->contains($spell->id),
                ];
            })
            ->all();
    }

    /**
     * Adds each modifier's own description/category/cooldown to its entry — added 2026-08-09 so
     * the modal's "Modifies / Enhances" list can expand a modifier inline (an accordion) instead
     * of only showing its bare name. modifiersFor() itself only returns
     * {spell, relationship_type, modifier_value, modifier_unit} — resolving each modifier's own
     * detail is a separate step, done here rather than inside modifiersFor() since that method is
     * also called from Modules\Show/SpellExplorer, neither of which needs this extra resolution
     * work (no modifier-accordion UI on either page). Reuses the same $build/$selected/$ranks
     * context as the parent spell, since a modifier's own effective cooldown depends on the same
     * talent build.
     *
     * @param  \Illuminate\Support\Collection<int, array>  $modifiers
     * @return \Illuminate\Support\Collection<int, array>
     */
    private function enrichModifiers(Collection $modifiers, ModuleSpellReferenceService $service, ModuleGameBuild $build, Collection $selected, Collection $ranks): Collection
    {
        return $modifiers->map(function (array $mod) use ($service, $build, $selected, $ranks) {
            $mod['description'] = $service->resolveDescription($mod['spell'], $build);
            $mod['category'] = $service->categorize($mod['spell']);
            $mod['cooldown'] = $service->effectiveCooldown($mod['spell'], $build, $selected, $ranks);

            return $mod;
        });
    }

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
        return view('livewire.wow-comps', [
            'classSpecs' => $this->classSpecs,
            'comp' => $this->comp,
        ])->layout('layouts.app');
    }
}
