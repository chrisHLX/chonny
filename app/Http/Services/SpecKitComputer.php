<?php

namespace App\Http\Services;

use App\Models\GameClass;
use App\Models\ModuleGameBuild;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\TalentNodeEntry;
use App\Support\SpellProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Computes a spec's full "kit" — every real talent-tree entry, every PvP talent, every baseline
 * ability, each resolved against one talent build (description, category, talent-adjusted
 * cooldown/charges, and what modifies/could-modify it) — as one plain array, the same shape
 * WowComps has rendered directly since 2026-08-16's "always show every talent" rework.
 *
 * Extracted 2026-09-01 from WowComps::computeSpellReferencesFor() (moved verbatim, not
 * rewritten — behavior is unchanged) as part of the "precompute, don't recompute live" redesign
 * (see wow:precompute-spell-kits's own docblock for the full reasoning). This class is now the
 * SINGLE engine for that computation, called from two places:
 *   - wow:precompute-spell-kits — runs this once per spec against its admin-default TalentBuild,
 *     writes the result to data/spell-kits/{class}/{spec}.json. This is the path that actually
 *     serves nearly every real page view (guests and any user with no personal build).
 *   - WowComps::spellReferencesFor() — still calls this LIVE, but only for the one case a
 *     precomputed file structurally cannot cover: a viewer with their own personal, customized
 *     TalentBuild for this spec. Falls back to the existing Redis cache for that case, unchanged.
 *
 * Deliberately does NOT touch ModuleSpellReferenceService/TalentSelectionService's own logic —
 * this class is pure orchestration (which spells to gather, which arena-log tags to attach, how
 * to shape the result), the actual per-spell resolution (categorize/modifiersFor/
 * resolveDescription/effectiveCooldown/effectiveCharges) is still 100% those services' job,
 * called exactly as WowComps always called them. "Keep the service output" — this class is a
 * caller of that output, not a replacement for it.
 */
class SpecKitComputer
{
    /**
     * Reads a precomputed spec kit written by wow:precompute-spell-kits, if one exists and
     * hasn't gone stale — shared by every consumer of this precompute (WowComps, SpellExplorer;
     * see PrecomputeSpellKits's own docblock for the full design). Returns null (never throws,
     * never partially-applies) on any miss: file doesn't exist yet, or its stored
     * spellCacheVersion/codeFingerprint don't match the current ones. Callers are expected to
     * fall back to their own existing live-compute-and-cache path on a null, exactly as if this
     * method didn't exist — this is a pure optimization layer, never a hard dependency.
     *
     * Only ever call this for the admin-default-build case (check $build->exists &&
     * $build->is_default first) — a precomputed file is always built from ONE spec's default
     * build and can't represent a viewer's own personal, customized talents.
     */
    public function tryReadPrecomputed(Specialization $spec, TalentSelectionService $talentService): ?array
    {
        $class = GameClass::find($spec->class_id);
        if (! $class) {
            return null;
        }

        $path = base_path("data/spell-kits/{$class->slug}/{$spec->slug}.json");
        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['entries'], $decoded['spellCacheVersion'], $decoded['codeFingerprint'])) {
            return null;
        }

        if ((string) $decoded['spellCacheVersion'] !== (string) $talentService->spellCacheVersion()
            || $decoded['codeFingerprint'] !== $talentService->deployedCodeFingerprint()) {
            return null;
        }

        return $this->fromJsonSafeArray($decoded);
    }

    /**
     * Resolves a small, specific list of external spell_ids down to their full kit entries for
     * one spec — tries the precomputed file first (tryReadPrecomputed()), falling back to a
     * direct live compute() against the spec's admin-default build otherwise, exactly the
     * fallback chain every other consumer of this class already follows. Shared by
     * `ClaudesGuides::resolveSpellEntries()` and `wow:simulate-duel` so neither re-implements this
     * "look up a handful of ids in the real per-spec kit" pattern independently — both features
     * narrow the same real computed kit down to a curated subset, never compute their own numbers.
     *
     * compute()'s own kit is deliberately scoped to *selectable* content — talent picks, PvP
     * talents, verified/explicit-cooldown baseline abilities — because that's what
     * WowComps/SpellExplorer need to show. It was never meant to cover a spec's unconditional,
     * always-available core rotation (builders/finishers with no real cooldown, e.g.
     * Envenom/Mutilate/Rupture for Assassination Rogue) — those were never a "which of these did
     * you pick" question, so they were never added to that kit at all. A guide/duel referencing
     * one of those still needs a real name/icon/category to render, so any id that doesn't
     * resolve through the selectable kit falls back to a direct, patch-scoped `Spell` lookup here
     * — categorized via `ModuleSpellReferenceService::categorize()` directly (no talent-build
     * context needed: baseline abilities with no talent-driven modifiers to resolve),
     * cooldown/charges read straight off the spell's own columns, and a plain, unresolved
     * description (skips the template-substitution `resolveDescription()` normally does, which
     * needs a real `ModuleGameBuild` context — not worth building a synthetic one for a handful
     * of filler abilities with nothing conditional in their own description text anyway).
     *
     * An id resolving through neither path is silently dropped, never shown/used broken.
     *
     * @param  array<int, int>  $spellIds  external spell_id values
     * @return array<int, array> a subset of compute()'s own entry shape
     */
    public function resolveEntriesForSpellIds(array $spellIds, Specialization $spec, ModuleSpellReferenceService $service, TalentSelectionService $talentService): array
    {
        $entries = $this->tryReadPrecomputed($spec, $talentService);

        if ($entries === null) {
            // Scoped to the CURRENT patch. talent_builds is patch-scoped, so a database holding
            // more than one patch row has one admin-default build per spec PER PATCH, and an
            // unfiltered first() returns whichever has the lower id - the OLDEST patch. Local dev
            // has a single patch, so this read correctly by accident there, while production (two
            // patch rows since 2026-08-18) built all 40 of its spec kits from a stale build.
            // TalentSelectionService already filters by patch everywhere; these callers did not.
            $defaultBuild = TalentBuild::where('spec_id', $spec->id)
                ->where('patch_id', Patch::where('is_current', true)->value('id'))
                ->where('is_default', true)
                ->first();
            $entries = $this->compute($spec, $defaultBuild, $service, $talentService);
        }

        $bySpellId = collect($entries)->keyBy(fn ($e) => $e['spell']->spell_id);

        $resolved = collect($spellIds)->map(function ($id) use ($bySpellId) {
            if ($bySpellId->has($id)) {
                return $bySpellId->get($id);
            }

            $patchId = \App\Models\Patch::where('is_current', true)->value('id');
            $spell = Spell::where('patch_id', $patchId)->where('spell_id', $id)->first();
            if (! $spell) {
                return null;
            }

            return $this->profileBuilder()->forKitEntry(
                spell: $spell,
                category: $this->profileBuilder()->category($spell),
                description: ['text' => strip_tags($spell->description ?? ''), 'uncertain' => false],
                formulaModifiers: collect(),
                modifiers: ['named' => collect(), 'baseline' => collect(), 'potential' => collect()],
                cooldown: ['seconds' => $spell->cooldown_seconds],
                charges: ['charges' => $spell->charges],
                isSelected: true, // unconditional baseline — always "selected", nothing to gate on
                source: 'baseline_core',
                isPriority: false,
                offensiveDefensive: null,
                // resolvedDrCategory deliberately left unset (= use the spell's own base column).
                // This branch only ever handles unconditional core-rotation filler that isn't in
                // the selectable kit at all, so there are no talent selections in scope here to
                // resolve a conditional dr_category against  14 and inventing one would be worse
                // than showing the base value.
            );
        })->filter()->values();

        return $resolved->all();
    }

    /**
     * @return array<int, SpellProfile> every entry is the one shared spell object — see
     *                                  App\Support\SpellProfile. It implements ArrayAccess with
     *                                  exactly the key set this method used to return as a plain
     *                                  array, so existing blade templates and the on-disk kit
     *                                  files consume it unchanged.
     */
    public function compute(
        Specialization $spec,
        ?TalentBuild $defaultBuild,
        ModuleSpellReferenceService $service,
        TalentSelectionService $talentService
    ): array {
        $selected = $defaultBuild ? $talentService->selectedSpellIds($defaultBuild) : collect();
        $ranks = $defaultBuild ? $talentService->selectedRanks($defaultBuild) : collect();

        // Always-shown display set — every real talent-tree entry and every PvP talent for the
        // spec, regardless of whether the resolved overlay build ($selected) picked it.
        $allTalentIds = $talentService->allTalentSpellIds($spec->id);
        $allPvpIds = $talentService->allPvpTalentSpellIds($spec->id);

        // Manually-verified baseline abilities only (Leg Sweep, Freezing Trap, ...) — NOT
        // TalentSelectionService::alwaysAvailableAbilityIds() (see that method's "DO NOT WIRE
        // IN" banner and CLAUDE.md's "Baseline ability display" section — that path derives
        // from the ambiguous spec_id=NULL bucket and leaked Mind Sear onto Discipline Priest).
        $verifiedBaselineIds = $talentService->verifiedBaselineAbilityIds($spec->id);

        // Explicit-spec_id baseline abilities with a real cooldown/CC mechanic — see
        // TalentSelectionService::explicitBaselineCooldownAbilityIds()'s docblock.
        $cooldownBaselineIds = $talentService->explicitBaselineCooldownAbilityIds($spec->class_id, $spec->id);
        $displayIds = $allTalentIds->merge($allPvpIds)->merge($verifiedBaselineIds)->merge($cooldownBaselineIds)->unique();

        // Real arena-match cast evidence for this spec — powers the "Cooldowns" tab, same shared
        // source SpellExplorer's "Priority Spells" filter reads.
        $class = GameClass::find($spec->class_id);
        $arenaLogService = app(ArenaLogService::class);
        $priorityExternalIds = $class ? $arenaLogService->spellUsageIds($class->slug, $spec->slug) : collect();

        // Offensive/Defensive Cooldowns tabs — real, arena-log-verified classification, promoted
        // from wow-arena-archive (see ArenaLogService::offensiveDefensiveClassification()).
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

        // Collapses same-name duplicate spell_id copies down to one entry.
        $spells = $talentService->preferSelectedPerName($spells, $selected);

        $service->preloadBaseCooldownCharges($spells);
        $service->preloadCategorize($spells);
        $priorityBySpellId = $arenaLogService->preloadPrioritySpells($spells, $priorityExternalIds);

        $modifiersBySpellId = [];
        $modifierSpells = collect();

        foreach ($spells as $spell) {
            $modifiers = $service->modifiersFor($spell, $build, $selected, $ranks);
            $modifiersBySpellId[$spell->id] = $modifiers;
            $modifierSpells->push(...$modifiers['named']->pluck('spell'));
            $modifierSpells->push(...$modifiers['baseline']->pluck('spell'));
            $modifierSpells->push(...$modifiers['potential']->pluck('spell'));
        }

        $modifierSpells = $modifierSpells->unique('id');
        $service->preloadBaseCooldownCharges($modifierSpells);
        $service->preloadCategorize($modifierSpells);

        return $spells
            ->map(function ($spell) use ($service, $build, $selected, $ranks, $verifiedBaselineIds, $cooldownBaselineIds, $allTalentIds, $allPvpIds, $priorityBySpellId, $modifiersBySpellId, $classification) {
                $description = $service->resolveDescription($spell, $build);
                $modifiers = $modifiersBySpellId[$spell->id];
                $offDef = $classification['bySpellId'][$spell->spell_id] ?? $classification['byName'][$spell->display_name] ?? null;

                return $this->profileBuilder()->forKitEntry(
                    spell: $spell,
                    category: $this->profileBuilder()->category($spell),
                    description: $description,
                    formulaModifiers: $description['uncertain'] ? $service->variablesModifiers($spell) : collect(),
                    modifiers: [
                        'named' => $this->enrichModifiers($modifiers['named'], $service, $build, $selected, $ranks),
                        'baseline' => $this->enrichModifiers($modifiers['baseline'], $service, $build, $selected, $ranks),
                        'potential' => $this->enrichModifiers($modifiers['potential'], $service, $build, $selected, $ranks),
                    ],
                    cooldown: $service->effectiveCooldown($spell, $build, $selected, $ranks),
                    charges: $service->effectiveCharges($spell, $build, $selected, $ranks),
                    isSelected: $selected->contains($spell->id) || $verifiedBaselineIds->contains($spell->id) || $cooldownBaselineIds->contains($spell->id),
                    source: $allTalentIds->contains($spell->id) ? 'talent' : ($allPvpIds->contains($spell->id) ? 'pvp_talent' : 'baseline'),
                    isPriority: $priorityBySpellId[$spell->id] ?? false,
                    offensiveDefensive: $offDef,
                    resolvedDrCategory: $this->profileBuilder()->resolveDrCategory($spell, $selected),
                );
            })
            ->all();
    }

    /**
     * @param  Collection<int, array>  $modifiers
     * @return Collection<int, array>
     */
    private function enrichModifiers(Collection $modifiers, ModuleSpellReferenceService $service, ModuleGameBuild $build, Collection $selected, Collection $ranks): Collection
    {
        return $this->profileBuilder()->enrichModifiers($modifiers, $build, $selected, $ranks);
    }

    /**
     * Resolved lazily rather than constructor-injected: this class is instantiated directly in a
     * few places (tests, console commands) that pass their own service instances positionally,
     * and adding a required constructor argument would break every one of them for no gain.
     */
    private function profileBuilder(): SpellProfileBuilder
    {
        return $this->profileBuilder ??= app(SpellProfileBuilder::class);
    }

    private ?SpellProfileBuilder $profileBuilder = null;

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
     * Converts compute()'s output into a plain, JSON-safe array — every real Spell model
     * replaced by its bare internal id, so the file itself is small and genuinely readable
     * (open it and see spell ids + the derived facts about them, not a serialized PHP object
     * graph). Used by wow:precompute-spell-kits before writing to disk. The companion
     * fromJsonSafeArray() reverses this exactly, rehydrating real Spell models from one bulk
     * query — nothing downstream (the Blade templates) needs to know this round trip happened.
     *
     * @param  array<int, SpellProfile>  $entries  compute()'s own return shape (SpellProfile's
     *                                             ArrayAccess bridge is what lets this read it by key)
     */
    public function toJsonSafeArray(array $entries): array
    {
        $modifierToJson = fn (array $mod) => [
            'spellId' => $mod['spell']->id,
            'relationship_type' => $mod['relationship_type'],
            'modifier_value' => $mod['modifier_value'],
            'modifier_unit' => $mod['modifier_unit'],
            'description' => $mod['description'],
            'category' => $mod['category'],
            'cooldown' => $mod['cooldown'],
        ];

        // Untyped: $entry is a SpellProfile, which is ArrayAccess — a type PHP's `array`
        // hint does not accept. Its modifiers stay plain arrays, so $modifierToJson above
        // keeps its own hint.
        return array_map(function ($entry) use ($modifierToJson) {
            return [
                'spellId' => $entry['spell']->id,
                'category' => $entry['category'],
                'description' => $entry['description'],
                'formulaModifierSpellIds' => $entry['formulaModifiers']->pluck('id')->values()->all(),
                'modifiers' => [
                    'named' => $entry['modifiers']['named']->map($modifierToJson)->values()->all(),
                    'baseline' => $entry['modifiers']['baseline']->map($modifierToJson)->values()->all(),
                    'potential' => $entry['modifiers']['potential']->map($modifierToJson)->values()->all(),
                ],
                'hasPotentialImprovement' => $entry['hasPotentialImprovement'],
                'cooldown' => $entry['cooldown'],
                'charges' => $entry['charges'],
                'isSelected' => $entry['isSelected'],
                'source' => $entry['source'],
                'isPriority' => $entry['isPriority'],
                'offensiveDefensive' => $entry['offensiveDefensive'],
                // The BUILD-RESOLVED dr_category (see SpellProfileBuilder::resolveDrCategory).
                // Serialized rather than recomputed on read: fromJsonSafeArray() has no talent
                // selections to resolve against, and this file is already per-spec-and-build by
                // construction, so the resolved value is exactly as cacheable as 'category' is.
                'drCategory' => $entry['drCategory'],
            ];
        }, $entries);
    }

    /**
     * Reverses toJsonSafeArray() — rehydrates real Spell models (one bulk query for every spell
     * id referenced anywhere in the payload, main entries and modifiers alike) and reassembles
     * the exact same shape compute() returns, so callers (WowComps, the Blade templates) need no
     * changes at all to consume a precomputed file instead of a live computation.
     *
     * @param  array{entries: array<int, array>}  $decoded  json_decode($file, true)
     * @return array<int, SpellProfile>
     */
    public function fromJsonSafeArray(array $decoded): array
    {
        $entries = $decoded['entries'] ?? [];

        $allIds = collect($entries)->flatMap(function (array $e) {
            $ids = [$e['spellId']];
            foreach ($e['formulaModifierSpellIds'] ?? [] as $id) {
                $ids[] = $id;
            }
            foreach (['named', 'baseline', 'potential'] as $bucket) {
                foreach ($e['modifiers'][$bucket] ?? [] as $mod) {
                    $ids[] = $mod['spellId'];
                }
            }

            return $ids;
        })->unique()->values();

        $spellsById = Spell::whereIn('id', $allIds)
            ->with(['effects', 'incomingRelationships.sourceSpell.effects'])
            ->get()
            ->keyBy('id');

        $modifierFromJson = fn (array $mod) => [
            'spell' => $spellsById->get($mod['spellId']),
            'relationship_type' => $mod['relationship_type'],
            'modifier_value' => $mod['modifier_value'],
            'modifier_unit' => $mod['modifier_unit'],
            'description' => $mod['description'],
            'category' => $mod['category'],
            'cooldown' => $mod['cooldown'],
        ];

        return array_values(array_filter(array_map(function (array $e) use ($spellsById, $modifierFromJson) {
            $spell = $spellsById->get($e['spellId']);

            if (! $spell) {
                // A spell referenced by a stale precomputed file no longer exists in the current
                // patch (e.g. removed by a re-import) — drop the entry rather than render on a
                // null model. Same "degrade, don't crash" posture as spellReferencesCacheIsValid().
                return null;
            }

            // Rehydrates to the SAME SpellProfile compute() returns, so a precomputed render and
            // a live one are indistinguishable to every consumer — that equivalence is the whole
            // point of the precompute and is asserted directly by SpellProfileTest.
            return $this->profileBuilder()->forKitEntry(
                spell: $spell,
                category: $e['category'],
                description: $e['description'],
                formulaModifiers: collect($e['formulaModifierSpellIds'] ?? [])->map(fn ($id) => $spellsById->get($id))->filter()->values(),
                modifiers: [
                    'named' => collect($e['modifiers']['named'] ?? [])->map($modifierFromJson)->filter(fn ($m) => $m['spell'] !== null)->values(),
                    'baseline' => collect($e['modifiers']['baseline'] ?? [])->map($modifierFromJson)->filter(fn ($m) => $m['spell'] !== null)->values(),
                    'potential' => collect($e['modifiers']['potential'] ?? [])->map($modifierFromJson)->filter(fn ($m) => $m['spell'] !== null)->values(),
                ],
                cooldown: $e['cooldown'],
                charges: $e['charges'],
                isSelected: $e['isSelected'],
                source: $e['source'],
                isPriority: $e['isPriority'],
                offensiveDefensive: $e['offensiveDefensive'],
                // Older kit files predate this key; falling back to the base column reproduces
                // exactly the pre-2026-09-06 behaviour rather than erroring on a stale file.
                resolvedDrCategory: $e['drCategory'] ?? $spell->dr_category,
            );
        }, $entries)));
    }
}
