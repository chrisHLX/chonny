<?php

namespace App\Livewire;

use App\Http\Services\ArenaLogService;
use App\Http\Services\CcFormulaService;
use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\TalentSelectionService;
use App\Models\GameClass;
use App\Models\ModuleGameBuild;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\TalentNodeEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Component;

/**
 * Phase-1 shape-check page: pick 3 specs (Healer / DPS / DPS slots — the class/spec picker
 * restricts each slot to real matching specs, see SPEC_ROLES below, added 2026-08-22) and see
 * each one's spell kit side by side for comparison, grouped
 * Offensive/Defensive/Utility/Crowd Control/Other same as Spell Explorer (categorize()'s
 * heuristic — every spell in the kit, filler included), plus separate Offensive Cooldowns /
 * Defensive Cooldowns tabs backed by wow-arena-archive's real, arena-log-verified cooldown
 * classification (see ArenaLogService::offensiveDefensiveClassification()) — a narrower,
 * higher-confidence answer than categorize() for the specific question "is this a real
 * offensive or defensive cooldown a competitive player actually presses," not every spell in
 * the kit. Deliberately no comps table, no spell_functions table, no seeding — this exists
 * purely to get the picker/layout shape right before any of that schema work happens.
 *
 * REWORKED 2026-08-16, same change and same reasoning as SpellExplorer (see that class's
 * docblock): every real talent-tree entry and every PvP talent for each slot's spec is ALWAYS
 * shown now (tagged 'Talent'/'PvP Talent' — see the 'source' key on each mapped entry below),
 * not just whatever one curated build happened to have selected. A resolved build
 * (TalentSelectionService::resolveActiveBuild()) is now purely an overlay — which entries
 * render highlighted vs. greyed "Not selected," and the source of the build-aware
 * cooldown/charge numbers via ModuleSpellReferenceService. The interactive talent-picker modal
 * that used to live on this page (activePickerSpecId/openPicker()/closePicker()) has been
 * removed, since nothing is hidden by build selection anymore. Admin\TalentBuildEditor remains
 * the only place that edits the admin-default overlay builds.
 */
class WowComps extends Component
{
    public array $slots = [
        ['label' => 'Healer', 'classId' => null, 'specId' => null],
        ['label' => 'DPS', 'classId' => null, 'specId' => null],
        ['label' => 'DPS', 'classId' => null, 'specId' => null],
    ];

    /**
     * Static, unambiguous Blizzard-defined role per spec — stable reference data, same
     * confidence tier as config('wow_classes.colors') (hardcoded, no verification-against-
     * real-data needed, unlike e.g. spec-attribution for individual abilities elsewhere in this
     * project). Keyed by class slug then spec slug. 'tank' exists as its own value (not folded
     * into 'dps') so getSpecRoleMapProperty() can tell the two apart if a future feature needs
     * to — the picker itself only ever checks "healer vs. not healer" (see
     * getClassSpecsProperty()'s docblock), per direct instruction: the Healer slot allows only
     * Healer specs, both DPS slots allow DPS AND Tank specs.
     */
    private const SPEC_ROLES = [
        'warrior' => ['arms' => 'dps', 'fury' => 'dps', 'protection' => 'tank'],
        'paladin' => ['holy' => 'healer', 'protection' => 'tank', 'retribution' => 'dps'],
        'hunter' => ['beast-mastery' => 'dps', 'marksmanship' => 'dps', 'survival' => 'dps'],
        'rogue' => ['assassination' => 'dps', 'outlaw' => 'dps', 'subtlety' => 'dps'],
        'priest' => ['discipline' => 'healer', 'holy' => 'healer', 'shadow' => 'dps'],
        'deathknight' => ['blood' => 'tank', 'frost' => 'dps', 'unholy' => 'dps'],
        'shaman' => ['elemental' => 'dps', 'enhancement' => 'dps', 'restoration' => 'healer'],
        'mage' => ['arcane' => 'dps', 'fire' => 'dps', 'frost' => 'dps'],
        'warlock' => ['affliction' => 'dps', 'demonology' => 'dps', 'destruction' => 'dps'],
        'monk' => ['brewmaster' => 'tank', 'mistweaver' => 'healer', 'windwalker' => 'dps'],
        'druid' => ['balance' => 'dps', 'feral' => 'dps', 'guardian' => 'tank', 'restoration' => 'healer'],
        'demonhunter' => ['havoc' => 'dps', 'vengeance' => 'tank', 'devourer' => 'dps'],
        'evoker' => ['devastation' => 'dps', 'preservation' => 'healer', 'augmentation' => 'dps'],
    ];

    /**
     * The single "Cooldowns" tab (isPriority + effective cooldown over 15s, an arbitrary UI
     * toggle) was replaced 2026-08-20 by two tabs, Offensive Cooldowns / Defensive Cooldowns,
     * driven by the real arena-log-verified classification promoted from wow-arena-archive (see
     * ArenaLogService::offensiveDefensiveClassification()) instead of a bare cooldown-length
     * heuristic — the $cooldownsLongOnly toggle this docblock used to describe is gone with it;
     * that classification already has its own, non-arbitrary minimum-cooldown floor (8s) baked
     * into how the archive builds it, so no separate UI toggle is needed here.
     */

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
     * SPEC_ROLES resolved against real spec ids, for the picker's role filter. Falls back to
     * 'dps' for any (class, spec) slug pair not in the map — should never actually happen once
     * SPEC_ROLES is complete, but a spec silently defaulting to a DPS-slot-visible role is a far
     * safer failure than one silently disappearing from every slot.
     *
     * @return array<int, string> spec id => 'healer'|'dps'|'tank'
     */
    public function getSpecRoleMapProperty(): array
    {
        $map = [];

        foreach ($this->classSpecs as $class) {
            foreach ($class->specializations as $spec) {
                $map[$spec->id] = self::SPEC_ROLES[$class->slug][$spec->slug] ?? 'dps';
            }
        }

        return $map;
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
        // recomputed everything from scratch.
        //
        // As of the personal talent picker (2026-08-10), the build resolved below is whichever
        // resolveActiveBuild() picks for THIS viewer — their own saved build if they have one for
        // this spec, else the spec's admin default, exactly as before. A guest or a user with no
        // personal build for this spec resolves to the admin default and shares the same cache
        // entry every such viewer gets. Once a viewer has a personal build, the cache key includes
        // that build's own id+updated_at — saveChoice()/syncPvpChoices()/pruneNodeChoices() all
        // touch() the build (see TalentSelectionService), so a pick invalidates only that one
        // viewer's own entry, never anyone else's. The admin-default case still additionally
        // varies on the global spellCacheVersion counter (bumped on an admin-default write or a
        // spelldata re-import) exactly as before this feature. 'isPriority' (2026-08-18) is baked
        // into this same cached payload — a new arena-log match processed for this spec does NOT
        // bump spellCacheVersion() on its own, same known staleness class as everywhere else this
        // cache is used.
        $build = $talentService->resolveActiveBuild(auth()->user(), $spec->id);
        $defaultBuild = $build->exists ? $build : null;
        $buildStamp = $defaultBuild ? "{$defaultBuild->id}:{$defaultBuild->updated_at?->timestamp}" : 'none';
        $version = $talentService->spellCacheVersion();

        $this->ensureMemoryHeadroom();

        return Cache::remember(
            "wow_spell_references:spec:{$spec->id}:build:{$buildStamp}:v{$version}",
            now()->addHours(6),
            fn () => $this->computeSpellReferencesFor($spec, $service, $talentService, $defaultBuild)
        );
    }

    /**
     * Raises (never lowers) PHP's memory_limit before computing/caching a spec's full spell
     * reference set — found necessary 2026-08-17 via a real user report (fatal "Allowed memory
     * size... exhausted" inside RedisStore::serialize(), i.e. while writing the computed result
     * to the Redis cache, not while computing it). Root cause: the 2026-08-16 "always show every
     * talent" rework (see this class's docblock) grew a talent-heavy spec's entry count from
     * ~50-80 (selected + siblings) to as many as ~250 (every real talent-tree entry + every PvP
     * talent), each entry carrying a full eager-loaded Spell model (+ effects +
     * incomingRelationships.sourceSpell.effects). Measured in isolation: ~66MB peak per spec.
     * getCompProperty() computes up to 3 slots per render, so a fresh page load with several
     * simultaneous cache misses can plausibly stack past PHP's 128MB default — confirmed live.
     * Scoped to this one heavy computation rather than a global php.ini/.user.ini change, so no
     * other page's memory ceiling is affected; same "bump memory_limit for this specific
     * memory-heavy operation" precedent already used for the `import:spelldata` CLI command (see
     * CLAUDE.md's "modifies_charges/charges display fixed" note — `-d memory_limit=512M`).
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
     * @return array<int, array{spell: Spell, category: string, description: array, modifiers: array, cooldown: array, charges: array, isSelected: bool, source: string}>
     */
    private function computeSpellReferencesFor(Specialization $spec, ModuleSpellReferenceService $service, TalentSelectionService $talentService, ?TalentBuild $defaultBuild): array
    {
        $selected = $defaultBuild ? $talentService->selectedSpellIds($defaultBuild) : collect();
        $ranks = $defaultBuild ? $talentService->selectedRanks($defaultBuild) : collect();

        // Always-shown display set — see this class's docblock (reworked 2026-08-16). Every
        // real talent-tree entry and every PvP talent for the spec, regardless of whether the
        // resolved overlay build ($selected) picked it. choiceSiblingSpellIds() is no longer
        // needed here: allTalentSpellIds() already includes every option of every CHOICE node
        // unconditionally, not just the unpicked side of a node that HAS a pick.
        $allTalentIds = $talentService->allTalentSpellIds($spec->id);
        $allPvpIds = $talentService->allPvpTalentSpellIds($spec->id);

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
        $displayIds = $allTalentIds->merge($allPvpIds)->merge($verifiedBaselineIds)->merge($cooldownBaselineIds)->unique();

        // Real arena-match cast evidence for this spec — powers the "Cooldowns" tab (2026-08-18),
        // same shared source SpellExplorer's "Priority Spells" filter reads. Tagged onto every
        // entry regardless of tab, same "compute once, filter at render time" pattern the rest of
        // this method already uses for category/group.
        $class = GameClass::find($spec->class_id);
        $arenaLogService = app(ArenaLogService::class);
        $priorityExternalIds = $class ? $arenaLogService->spellUsageIds($class->slug, $spec->slug) : collect();

        // Offensive/Defensive Cooldowns tabs (2026-08-20) — real, arena-log-verified
        // classification, promoted from wow-arena-archive (see ArenaLogService::
        // offensiveDefensiveClassification()'s own docblock). Deliberately intersected with
        // isPriority below, not used alone: the classification is a GLOBAL "is this spell ever
        // an offensive/defensive cooldown" answer, spec-blind by design — isPriority is what
        // narrows it to "and this exact spec actually cast it," the same two-signal intersection
        // spec-cooldowns.php itself uses in the archive.
        $classification = $arenaLogService->offensiveDefensiveClassification();

        $build = new ModuleGameBuild([
            'class_id' => $spec->class_id,
            'specialization_id' => $spec->id,
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
        // each method's own docblock for the profiling that found this (a cold render of one
        // spec's ~175 entries cost ~1800 queries/3.2s before this, ~700 of which were these two
        // exact per-spell sibling lookups). Must run before modifiersFor()/enrichModifiers()
        // below so their per-spell calls hit an already-primed memo instead of querying
        // individually.
        $service->preloadBaseCooldownCharges($spells);
        $service->preloadCategorize($spells);
        $priorityBySpellId = $arenaLogService->preloadPrioritySpells($spells, $priorityExternalIds);

        // modifiersFor() computed once per entry here (not again inside the final map() below)
        // specifically so every modifier spell it surfaces can be collected and preloaded in
        // bulk too — enrichModifiers() calls effectiveCooldown() on each modifier's own spell,
        // a DIFFERENT spell than the main entry, so preloading only $spells above left this
        // second tier of lookups still going one-by-one (confirmed via profiling: this was the
        // majority of the ~389 sibling queries remaining after the first preload pass).
        $modifiersBySpellId = [];
        $modifierSpells = collect();

        foreach ($spells as $spell) {
            $modifiers = $service->modifiersFor($spell, $build, $selected, $ranks);
            $modifiersBySpellId[$spell->id] = $modifiers;
            $modifierSpells->push(...$modifiers['named']->pluck('spell'));
            $modifierSpells->push(...$modifiers['baseline']->pluck('spell'));
        }

        $modifierSpells = $modifierSpells->unique('id');
        $service->preloadBaseCooldownCharges($modifierSpells);
        $service->preloadCategorize($modifierSpells);

        return $spells
            ->map(function ($spell) use ($service, $build, $selected, $ranks, $verifiedBaselineIds, $cooldownBaselineIds, $allTalentIds, $allPvpIds, $priorityBySpellId, $modifiersBySpellId, $classification) {
                $description = $service->resolveDescription($spell, $build);
                $modifiers = $modifiersBySpellId[$spell->id];
                $offDef = $classification['bySpellId'][$spell->spell_id] ?? $classification['byName'][$spell->display_name] ?? null;

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
                    'source' => $allTalentIds->contains($spell->id) ? 'talent' : ($allPvpIds->contains($spell->id) ? 'pvp_talent' : 'baseline'),
                    'isPriority' => $priorityBySpellId[$spell->id] ?? false,
                    'offensiveDefensive' => $offDef,
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

    /**
     * The two Synergies tab boxes, in render order (2026-08-16, third same-day revision — Utility
     * dropped CcChainBuilder sequencing too, matching the plain-grouping design DRs got in the
     * prior revision; the two boxes also swapped order, DRs first). Each maps a display label to
     * the real dr_category values it contains — "Diminishing Returns Groups" (Stun/Silence/
     * Incapacitate/Disorient, the categories that actually diminish each other) and "Utility"
     * (Knockback/Disarm/Slow/Root, which don't). Neither box is sequenced or scored anymore —
     * see getSynergiesProperty()'s docblock for why.
     */
    private const GROUP_CATEGORIES = [
        'Diminishing Returns Groups' => ['Stun', 'Silence', 'Incapacitate', 'Disorient'],
        'Utility' => ['Knockback', 'Disarm', 'Slow', 'Root'],
    ];

    /**
     * Community-standard "representative icon" per DR category, for the Crowd Control tab's
     * legend — e.g. Fear's icon universally reads as "Disorient" and Polymorph's icon as
     * "Incapacitate" across WoW arena addons (BigDebuffs, GladiusEx, etc.), independent of
     * which specific spell actually caused it. Requested 2026-08-22 via a Reddit comment
     * ("add the official icons for the different DRs, like the fear icon for Disorient, the
     * sheep icon for Incapacitate") — direct instruction was to put this as a one-time legend
     * in the tab's top info box, NOT a second icon on every spell card, so a card's own icon
     * always shows the exact spell that was actually cast.
     *
     * getDrCategoryLegendProperty() looks each spell_id up directly for the current patch —
     * it does NOT require that spell's own dr_category to match the legend's key. This is
     * deliberate: the legend is a pure visual reference to what Blizzard's own client actually
     * displays, decoupled from this project's own dr_category curation (e.g. Concussive Shot
     * below is curated dr_category=Slow in this project's own data, correctly — that's not a
     * contradiction, it's just a different, unrelated fact from "which icon the game shows for
     * the Stun DR category").
     *
     * **`Stun` corrected 2026-08-24** — the original 2026-08-22 picks were this project's own
     * best-guess "most recognizable curated spell per category," not Blizzard's actual in-game
     * convention, and Stun's guess (Cheap Shot) was wrong. Confirmed via an official Blizzard
     * forums thread (us.forums.blizzard.com, screenshotted directly by the user) AND the user's
     * own direct in-game verification: the real client-side Stun DR icon is Concussive Shot's,
     * not any actual stun ability's — Blizzard just picked one fixed icon per DR category,
     * seemingly arbitrarily, same as Incapacitate showing Detect Magic and Disorient showing
     * "Spellthread" per that same thread. Only Stun was corrected here — the user explicitly
     * confirmed that one themselves; Incapacitate/Disorient's forum-cited replacements
     * (Detect Magic, Spellthread) don't even exist as spell records in this project's current
     * patch data and were only cited secondhand from the forum post (which itself hedged with
     * "who knows the rest") — left as this project's prior best-guess rather than importing an
     * unverified name. Root's original pick (Entangling Roots) already happens to match the
     * confirmed forum answer, so no change was needed there. Disarm/Slow remain this project's
     * own best-guess pick (see the original note below) — no confirmed Blizzard answer exists
     * for either yet.
     *
     * **`Disorient` corrected 2026-08-24** — Blizzard's real in-game Disorient DR icon is
     * `spell_holy_dizzy`, confirmed by the user tapping through Wowhead's own icon-detail page
     * for that asset (live-verified on Blizzard's icon CDN before use, same discipline as every
     * other icon in this codebase). Unlike every other entry here, it has no real backing
     * spell/item record in this project's data at all — Wowhead's icon page shows it's reused
     * by a handful of unrelated items (e.g. "Runic Spellthread," a Classic tailoring
     * consumable — which is also why the original forum thread's link text read "Spellthread"
     * even though the actually-referenced thing was just this icon, not that item) and several
     * unrelated spells, none thematically tied to Disorient — pure incidental icon-art reuse on
     * Blizzard's side. A value here is either an `int` (a real spell_id, resolved to a `Spell`
     * model the normal way) or a `string` (a raw, self-hosted icon filename under
     * storage/app/public/spell-icons/, rendered directly with no `Spell` model at all) — see
     * getDrCategoryLegendProperty().
     */
    private const DR_CATEGORY_ICON_SPELL_IDS = [
        'Stun' => 5116,                          // Concussive Shot — Blizzard's actual in-game Stun DR icon
        'Incapacitate' => 118,                   // Polymorph
        'Disorient' => 'spell_holy_dizzy.jpg',   // raw icon filename — Blizzard's real Disorient DR icon, no backing spell exists
        'Root' => 339,                           // Entangling Roots — confirmed matches Blizzard's real icon
        'Silence' => 15487,                      // Silence
        'Knockback' => 132469,                   // Typhoon
        'Disarm' => 236077,                      // Disarm
        'Slow' => 45524,                         // Chains of Ice
    ];

    /**
     * The Synergies tab's data. `groups` is a plain grouping keyed by the display labels in
     * GROUP_CATEGORIES above — no CcChainBuilder involvement anymore for either box, no
     * sequencing, no DR%/immune computation (direct instruction, 2026-08-16: the player builds
     * their own in-game chain from what's shown; this tab only surfaces what CC exists and which
     * DR category it belongs to). Each box's spells are ordered by GROUP_CATEGORIES's own fixed
     * category order first (so same-category spells visually cluster even without a sub-heading
     * — the category itself now renders as a badge on each individual spell card, "like we had
     * originally," rather than a group heading), then alphabetically by name within a category.
     *
     * Any `dr_category` value outside both known lists (none currently exist, but the taxonomy is
     * a plain curated string, not a DB enum — see CLAUDE.md's `dr_category` design-decision note)
     * still gets its own trailing group, keyed by that category name directly and appended
     * alphabetically after the two known boxes, so a future/unclassified category can never
     * silently vanish from the tab.
     *
     * `chain_target`/`is_peel`/`is_interrupt` are untouched by any of this — still curated
     * columns, still read by CcReview/ImportSpellData — they just don't drive this tab's
     * grouping. CcChainBuilder itself is untouched too (still used/tested independently) — this
     * tab just no longer calls it.
     *
     * Also pools two functional-role flags that are independent of dr_category entirely (see
     * the is_peel/is_interrupt migration's docblock for why they're separate fields, not folded
     * into dr_category) — `peels` (Roots + Ursol's Vortex, spells used to create separation/
     * protect a teammate) and `interrupts` (Kick/Counterspell/etc., a mechanic with no DR
     * relationship at all). Plain grouped lists, unaffected by any of this.
     *
     * `cooldown_by_id` carries each spell's already-computed effective cooldown (talent-modified,
     * same value the Active Abilities tab shows) so every Synergies section can display CD
     * alongside the curated PvP CC duration without recomputing anything — `$member['entries']`
     * already has this from getCompProperty()'s normal per-spec computation. `charges_by_id`
     * (2026-08-20, direct request) is the same idea for effective charge count — shown on the
     * card itself now, not only inside the click-through modal, same "the card shows the
     * talent-modified number, the modal explains which modifier caused it" split CD already has.
     *
     * @return array{groups: array<string, Collection<int, Spell>>, peels: Collection, interrupts: Collection, owner_map: array<int, int>, cooldown_by_id: array<int, ?float>, charges_by_id: array<int, ?int>}
     */
    public function getSynergiesProperty(): array
    {
        $ownerMap = [];
        $cooldownById = [];
        $chargesById = [];

        $ccEntries = collect();
        $peels = collect();
        $interrupts = collect();
        // Excluded-CC block, added 2026-08-25 (direct request) — dr_category-tagged spells the
        // spec CAN have but the actual build's talent/PvP-talent selections don't currently
        // pick (an unchosen CHOICE-node sibling, an unpicked PvE talent, an unslotted PvP
        // talent, or — since 2026-08-25 — a spell TalentSelectionService::PVP_TALENT_REPLACES
        // suppressed, like Asphyxiate once Strangulate is selected). Lets a viewer see at a
        // glance which CC a comp is passing up, not just what it has.
        $excludedCc = collect();
        foreach ($this->comp as $mi => $member) {
            if (!$member['spec']) {
                continue;
            }
            $label = "{$member['spec']->name} {$member['class']->name}";
            foreach ($member['entries'] as $entry) {
                $spell = $entry['spell'];

                if (!($entry['isSelected'] ?? true)) {
                    if ($spell->dr_category !== null) {
                        $excludedCc->push(['spell' => $spell, 'label' => $label, 'mi' => $mi]);
                    }
                    continue;
                }

                if ($spell->dr_category !== null) {
                    $ccEntries->push($spell);
                    $ownerMap[$spell->id] = $mi;
                }
                if ($spell->is_peel) {
                    $peels->push($spell);
                    $ownerMap[$spell->id] = $mi;
                }
                if ($spell->is_interrupt) {
                    $interrupts->push($spell);
                    $ownerMap[$spell->id] = $mi;
                }
                if (!array_key_exists($spell->id, $cooldownById)) {
                    $cooldownById[$spell->id] = $entry['cooldown']['seconds'];
                    $chargesById[$spell->id] = $entry['charges']['charges'];
                }
            }
        }
        $ccEntries = $ccEntries->unique('id')->values();
        $peels = $peels->unique('id')->values();
        $interrupts = $interrupts->unique('id')->values();
        $excludedCc = $excludedCc->unique(fn ($e) => $e['spell']->id)
            ->sortBy(fn ($e) => $e['spell']->dr_category.$e['spell']->name)
            ->values();

        $groups = [];
        $covered = [];
        foreach (self::GROUP_CATEGORIES as $label => $categories) {
            $covered = array_merge($covered, $categories);
            $ordered = collect();
            foreach ($categories as $cat) {
                $ordered = $ordered->merge($ccEntries->filter(fn (Spell $s) => $s->dr_category === $cat)->sortBy('name')->values());
            }
            $groups[$label] = $ordered->values();
        }

        foreach ($ccEntries->pluck('dr_category')->unique()->diff($covered)->sort()->values() as $cat) {
            $groups[$cat] = $ccEntries->filter(fn (Spell $s) => $s->dr_category === $cat)->sortBy('name')->values();
        }

        return [
            'groups' => $groups,
            'peels' => $peels,
            'interrupts' => $interrupts,
            'owner_map' => $ownerMap,
            'cooldown_by_id' => $cooldownById,
            'charges_by_id' => $chargesById,
            'excluded' => $excludedCc,
        ];
    }

    /**
     * Suggested CC chain for the current 3 slots — added 2026-08-23, wiring wow:cc-formula's
     * CLI tool onto the live page for the first time (see CcFormulaService's docblock: the
     * algorithm itself lives there now, shared verbatim with the CLI command, so nothing here
     * duplicates or risks drifting from the tool's already-verified behavior). Returns null
     * until all 3 slots have a real spec picked — the algorithm needs exactly 3 specs, there's
     * no partial/2-spec mode.
     *
     * PERFORMANCE, fixed 2026-08-24 (direct instruction — this page already had a documented
     * heavy-render history before this feature existed at all; adding more uncached, duplicate
     * work on top of that was the wrong direction). Two changes from the first wire-up:
     * 1. Calls buildChainFromComp() instead of buildChain() — reuses $this->comp's entries
     *    (already computed, already Redis-cached per spec by getCompProperty()) instead of
     *    CcFormulaService independently re-deriving each spec's full kit via
     *    TalentSelectionService + a fresh Spell query. Same result, zero extra spell queries.
     * 2. The RESULT itself is now cached too, same Cache::remember(6h) pattern
     *    spellReferencesFor() already uses, keyed by all 3 specs' resolved-build stamps + the
     *    global spell cache version — a resolveActiveBuild() call per spec here is cheap (one
     *    small model lookup each) compared to what it used to cost before fix #1, and means a
     *    render that doesn't change any spec selection hits cache instead of recomputing.
     */
    public function getSuggestedChainProperty(): ?array
    {
        $selectedMembers = collect($this->comp)->filter(fn ($member) => $member['class'] && $member['spec']);

        if ($selectedMembers->count() < 3) {
            return null;
        }

        $talentService = app(TalentSelectionService::class);
        $version = $talentService->spellCacheVersion();

        $buildStamps = $selectedMembers->map(function ($member) use ($talentService) {
            $build = $talentService->resolveActiveBuild(auth()->user(), $member['spec']->id);

            return $build->exists ? "{$member['spec']->id}:{$build->id}:{$build->updated_at?->timestamp}" : "{$member['spec']->id}:none";
        })->implode('|');

        return Cache::remember(
            "wow_cc_formula:{$buildStamps}:v{$version}",
            now()->addHours(6),
            fn () => app(CcFormulaService::class)->buildChainFromComp($selectedMembers->all())
        );
    }

    /**
     * See DR_CATEGORY_ICON_SPELL_IDS's docblock. Static (doesn't depend on $this->comp at all —
     * these 8 representative spells are the same regardless of which specs are picked), so this
     * is cheap enough to compute on every render with no caching.
     *
     * @return array<int, array{category: string, spell: ?Spell, iconUrl: ?string}>
     */
    public function getDrCategoryLegendProperty(): array
    {
        $patchId = Patch::where('is_current', true)->value('id');

        return collect(self::DR_CATEGORY_ICON_SPELL_IDS)
            ->map(fn ($iconRef, $category) => is_string($iconRef)
                ? ['category' => $category, 'spell' => null, 'iconUrl' => '/storage/spell-icons/'.$iconRef]
                : ['category' => $category, 'spell' => Spell::where('patch_id', $patchId)->where('spell_id', $iconRef)->first(), 'iconUrl' => null])
            ->values()
            ->all();
    }

    /**
     * Top DPS Rotation tab data — replaced 2026-08-21 (direct instruction, after a full
     * investigative session on Sub Rogue). The tab used to show the spec's single most COMMON
     * real cast combo (topCombo — an exact, fully-unique n-gram required to recur across many
     * windows), which produced near-meaningless confidence numbers like "seen in 1 of 330
     * windows" and read as a false claim of typicality. It's replaced entirely with the single
     * highest-damage REAL window's own real sequence, shown as what it is — a real example that
     * really happened, not a statistical claim. See offensive-rotations.php's topDpsWindow() in
     * wow-arena-archive for the full rationale and the session's own conclusion: don't
     * statistically average/match across many real examples when trying to show peak execution —
     * pick the real highest-output example directly, since it's guaranteed mechanically legal.
     *
     * Reads the promoted per-spec summary via ArenaLogService::rotationForSpec() (see that
     * method's docblock, and offensive-rotations.php in wow-arena-archive for the derivation).
     * The window is anchored on the spec's own real offensive cooldowns, with the target
     * identified from where damage actually went — see that script for the two real bugs found
     * while building this pipeline (multi-locale spell names fragmenting patterns, and clone/proc
     * casts counted as player presses — both already handled before this window is recorded).
     *
     * @return array<int, array|null>
     */
    public function getOffensiveRotationsProperty(): array
    {
        $service = app(ArenaLogService::class);
        $talentService = app(TalentSelectionService::class);

        return collect($this->comp)->map(function ($member) use ($service, $talentService) {
            if (!$member['spec']) {
                return null;
            }

            $rotation = $service->rotationForSpec($member['class']->slug, $member['spec']->slug);

            if ($rotation === null || !isset($rotation['topDpsWindow'])) {
                return null;
            }

            // Spell resolution (spell_id -> real Spell, preferring the talent-linked copy) is
            // shared with TopDamageRotations via ArenaLogService::resolveWindowSteps() — see
            // that method's docblock.
            $rotation['topDpsWindow']['steps'] = $service->resolveWindowSteps(
                $rotation['topDpsWindow']['steps'],
                $member['spec']->id,
                $talentService
            );

            return $rotation;
        })->all();
    }


    /**
     * Rating Tiers tab data — reads data/arena-logs/rating-tiers/{classSlug}/{specSlug}.json
     * directly (built by RatingTierAnalysisService::analyzeSpec() / wow:analyze-rating-tiers),
     * same "no DB, no caching, read straight off disk" posture as getOffensiveRotationsProperty()
     * above. The file is a full JSON blob (not a per-line log), so this is a plain decode-and-
     * pass-through — no aggregation happens at render time, unlike the kill-sequence tab, since
     * wow:analyze-rating-tiers already computed every number (including the hero-tree
     * breakdown) ahead of time.
     *
     * @return array<int, array{bands: array}|null>
     */
    public function getRatingTiersProperty(): array
    {
        return collect($this->comp)->map(function ($member) {
            if (!$member['spec'] || !$member['class']) {
                return null;
            }

            return $this->ratingTierDataFor($member['class'], $member['spec']);
        })->all();
    }

    /**
     * @return array{bands: array}
     */
    private function ratingTierDataFor(GameClass $class, Specialization $spec): array
    {
        $path = base_path("data/arena-logs/rating-tiers/{$class->slug}/{$spec->slug}.json");

        if (!File::exists($path)) {
            return ['bands' => []];
        }

        $decoded = json_decode(File::get($path), true);

        return ['bands' => $decoded['bands'] ?? []];
    }

    public function render()
    {
        return view('livewire.wow-comps', [
            'classSpecs' => $this->classSpecs,
            'specRoleMap' => $this->specRoleMap,
            'comp' => $this->comp,
            'synergies' => $this->synergies,
            'suggestedChain' => $this->suggestedChain,
            'drCategoryLegend' => $this->drCategoryLegend,
            'offensiveRotations' => $this->offensiveRotations,
            'ratingTiers' => $this->ratingTiers,
        ])->layout('layouts.app');
    }
}
