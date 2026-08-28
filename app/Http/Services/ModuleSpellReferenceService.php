<?php

namespace App\Http\Services;

use App\Models\GameClass;
use App\Models\ModuleGameBuild;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellEffect;
use App\Models\SpellRelationship;
use App\Models\TalentTree;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a canonical context module's "Spells" reference section — see the
 * ModuleGameBuild/module_spell_references migrations and CLAUDE.md's Canonical Context
 * Module Template section. Two distinct jobs, deliberately kept separate:
 *
 * - resolveSpellByName() — seed-time only, used once when a module's curated spell list is
 *   authored, to turn a name from the module's prose into a concrete spell_id.
 * - modifiersFor() / buildKitSpellIds() — render-time, called on every page load so the
 *   details (cooldown, description, modifiers) always reflect whatever is currently imported,
 *   never a frozen snapshot.
 *
 * Cross-class extension (2026-07-27): a module's curated spell list isn't always drawn
 * entirely from its own declared class — e.g. a Discipline Priest matchup-timing module
 * legitimately documents a Hunter's Freezing Trap or a Paladin's Hammer of Justice. Every
 * "in-build" check below (modifier scoping, description-conditional resolution) now resolves
 * its own class/spec/hero-tree *context* per spell via resolveKitContext(), rather than
 * assuming the module's own ModuleGameBuild always applies. For a spell that IS in the
 * module's own class, this is a no-op — identical behavior to before this change. For a
 * spell from a different class, it falls back to that spell's own class with no assumed
 * spec/hero-tree (we have no way of knowing an opponent's exact build), so modifier lookups
 * for it stay scoped to its own class's baseline/spec-agnostic kit rather than being checked
 * against the wrong class entirely (or silently returning nothing).
 */
class ModuleSpellReferenceService
{
    /**
     * Per-instance memoization for the handful of lookups that depend only on a
     * (class_id, spec_id, hero_tree_id) build context, or on a (spell, build class) pair —
     * never on the specific spell being rendered otherwise. Added 2026-08-06 after measuring
     * a real 3-spec page (WowComps) taking 45s / ~5,000 queries: buildKitSpellIdsFor(),
     * buildTreeIdsFor(), genericBaselineAuraCheckerFor(), and resolveKitContext() were each
     * being recomputed from scratch for every single spell in a spec's ~100-spell kit, even
     * though their result is identical across every one of those spells (or, for
     * resolveKitContext(), identical across its two call sites — modifiersFor() and
     * resolveDescription() — for the same spell+build). Keyed by plain string keys built from
     * the actual inputs, so a genuinely different context (a different spec, a different
     * spell) still gets its own correctly-computed entry — this only removes *redundant*
     * recomputation of an identical input, never changes what any of these methods return.
     * Cleared automatically per request/property-computation since the service is resolved
     * fresh each time (not bound as a singleton) — no stale-across-requests risk.
     */
    private array $kitSpellIdsMemo = [];

    private array $treeIdsMemo = [];

    private array $baselineCheckerMemo = [];

    private array $kitContextMemo = [];

    /** @var array<string, ?SpellEffect> keyed by "{spell_id}:{index}" — see findEffectByIndex(). */
    private array $effectByIndexMemo = [];

    /** @var array<string, ?Spell> keyed by "{spell_id}:{patch_id}" — see findSpellBySpellId(). */
    private array $spellBySpellIdMemo = [];

    /** @var array<string, bool> keyed by "{spell->id}:{classId}:{specId}:{treeIds}" — see isConfidentlyInBuild(). */
    private array $confidentlyInBuildMemo = [];

    /** @var array<int, array{seconds: ?float, charges: ?int, duration: ?float}> keyed by spell->id — see resolveBaseCooldownCharges(). */
    private array $baseCooldownChargesMemo = [];

    /** @var array<int, string> keyed by spell->id — see categorize(). */
    private array $categorizeMemo = [];

    /**
     * Resolves a spell name to a concrete Spell for this build, disambiguating the same way
     * validated by hand against real data (Warrior Arms spot-check, 2026-07-25; the
     * not_in_spellbook step below added 2026-08-01 after a Priest Spellbook cross-check found
     * Penance/Ultimate Penitence each resolve to several same-name spell_id records): first drop
     * internal SimC sub-spells when a real one exists (preferVisible()), then prefer a copy
     * that's an actual talent pick in one of this build's own trees (zero ambiguity), then a
     * copy whose availability row matches this spec specifically, then one that actually
     * carries cooldown/charge data, else just the first. Not expected to be perfect — a wrong
     * resolution is a one-line fix in the seeder's attach() call, not a system failure.
     *
     * Falls back to resolveSpellByNameAnyClass() when nothing matches the build's own class —
     * covers a mentioned spell that belongs to a different class entirely (an opponent's
     * ability documented for matchup timing).
     */
    public function resolveSpellByName(string $name, ModuleGameBuild $build): ?Spell
    {
        $candidates = Spell::where('name', $name)
            ->whereHas('classAvailability', fn ($q) => $q->where('class_id', $build->class_id))
            ->with('classAvailability')
            ->get();

        if ($candidates->isEmpty()) {
            return $this->resolveSpellByNameAnyClass($name);
        }

        $candidates = $this->preferVisible($candidates);

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $treeIds = $this->buildTreeIds($build);

        $talentMatch = $candidates->first(
            fn (Spell $c) => $c->talentNodeEntries()
                ->whereHas('talentNode', fn ($q) => $q->whereIn('talent_tree_id', $treeIds))
                ->exists()
        );
        if ($talentMatch) {
            return $talentMatch;
        }

        $specMatch = $candidates->first(
            fn (Spell $c) => $c->classAvailability->contains('spec_id', $build->specialization_id)
        );
        if ($specMatch) {
            return $specMatch;
        }

        $withCooldown = $candidates->first(
            fn (Spell $c) => $c->cooldown_seconds !== null || $c->charges !== null
        );

        return $withCooldown ?? $candidates->first();
    }

    /**
     * Narrows a same-name candidate set to only the "real", player-facing spells when at least
     * one exists — found 2026-08-01 by cross-checking the in-game Spellbook UI against this data:
     * Penance and Ultimate Penitence are each actually several spell_id records sharing one
     * display name (the real talent plus internal damage-bolt/heal-bolt/visual-effect helper
     * spells with no independent meaning), and without this step a curated module spell
     * reference could resolve to one of the hidden duplicates instead of the real ability. Never
     * drops every candidate down to zero — if everything sharing this name is flagged
     * not_in_spellbook (shouldn't happen for a name a module author would ever curate, but not
     * assumed), the original set is returned unchanged rather than resolving to nothing.
     *
     * @param  Collection<int, Spell>  $candidates
     * @return Collection<int, Spell>
     */
    private function preferVisible(Collection $candidates): Collection
    {
        $visible = $candidates->reject(fn (Spell $c) => $c->not_in_spellbook);

        return $visible->isNotEmpty() ? $visible : $candidates;
    }

    /**
     * Fallback when a mentioned spell doesn't belong to the module's own class at all — an
     * opponent's ability documented for matchup timing (e.g. Hammer of Justice on a
     * Discipline Priest module). Matches purely by name across every class's spell data.
     * Same "not expected to be perfect" posture as the own-class resolver above: on a
     * same-name collision across classes (rare — Blizzard mostly avoids exact name reuse)
     * this returns the first match and logs a warning rather than guessing which one was
     * meant; fixing a wrong pick is a one-line change to the seeder's curated name list, not
     * a system failure.
     */
    private function resolveSpellByNameAnyClass(string $name): ?Spell
    {
        $candidates = Spell::where('name', $name)
            ->whereHas('classAvailability')
            ->with('classAvailability')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $candidates = $this->preferVisible($candidates);

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Added 2026-08-02, mirroring resolveSpellByName()'s own-class disambiguation chain:
        // prefer a candidate with real cooldown/charges data over one without. Found via a real
        // module reference resolving Hunter's "Intimidation" to spell_id 24394 (an internal
        // pet-stun effect referenced only inside the real ability's own description text, no
        // cooldown) instead of 19577 (the real talent — has a Talent Entry, Cooldown: 60
        // seconds) — and "Freezing Trap" to 3355 (the stun aura applied once trapped, no
        // cooldown) instead of 187650 (the real throw ability, Cooldown: 30 seconds). Neither
        // wrong candidate is flagged not_in_spellbook, so preferVisible() above can't catch this
        // class of duplicate — this fallback previously had no equivalent to the own-class
        // path's $withCooldown tier at all.
        $withCooldown = $candidates->first(
            fn (Spell $c) => $c->cooldown_seconds !== null || $c->charges !== null
        );
        if ($withCooldown) {
            return $withCooldown;
        }

        Log::warning('ModuleSpellReferenceService: ambiguous cross-class spell name match, using first', [
            'name' => $name,
            'candidate_ids' => $candidates->pluck('id')->all(),
        ]);

        return $candidates->first();
    }

    /**
     * Which class/spec/hero-tree kit a given mentioned spell should be checked against for
     * modifiers/description-conditionals. Defaults to the module's own build — identical
     * behavior to before the 2026-07-27 cross-class extension for every spell that's
     * actually in the module's own kit. For a spell belonging to a DIFFERENT class (an
     * opponent's ability), falls back to that spell's own class with no assumed spec or
     * hero-tree, since we have no way of knowing an opponent's exact build.
     *
     * @return array{class_id: ?int, spec_id: ?int, hero_tree_id: ?int}
     */
    private function resolveKitContext(Spell $spell, ModuleGameBuild $build): array
    {
        $key = $spell->id.':'.$build->class_id;

        if (array_key_exists($key, $this->kitContextMemo)) {
            return $this->kitContextMemo[$key];
        }

        $inOwnClass = $spell->classAvailability()->where('class_id', $build->class_id)->exists();

        $context = $inOwnClass
            ? [
                'class_id' => $build->class_id,
                'spec_id' => $build->specialization_id,
                'hero_tree_id' => $build->hero_talent_tree_id,
            ]
            : [
                'class_id' => $spell->classAvailability()->value('class_id'),
                'spec_id' => null,
                'hero_tree_id' => null,
            ];

        return $this->kitContextMemo[$key] = $context;
    }

    /**
     * The build's full candidate universe — same scoping as GameDataBrowser's Top Cooldowns/
     * Baseline Abilities properties (baseline/talent/pvp_talent spell_class_availability, spec_id
     * null or matching, plus this build's hero tree's talent_node_entries). Used both to scope
     * which incoming relationships count as "in this build" and as the search space for the
     * textual mention scan in modifiersFor().
     *
     * 'pvp_talent' was added to the source list 2026-07-30 alongside the new modifies_cooldown
     * relationship type (see ImportSpellData::importPvpTalentRelationships()) — without it, a
     * PvP talent's own spell (e.g. Ultimate Radiance) could never appear as a candidate source
     * here at all, silently dropping every PvP-talent-derived modifier regardless of selection.
     *
     * @return Collection<int, int> spell ids
     */
    public function buildKitSpellIds(ModuleGameBuild $build): Collection
    {
        return $this->buildKitSpellIdsFor($build->class_id, $build->specialization_id, $build->hero_talent_tree_id);
    }

    /** @return Collection<int, int> spell ids */
    private function buildKitSpellIdsFor(?int $classId, ?int $specId, ?int $heroTreeId): Collection
    {
        if ($classId === null) {
            return collect();
        }

        $key = $classId.':'.$specId.':'.$heroTreeId;
        if (array_key_exists($key, $this->kitSpellIdsMemo)) {
            return $this->kitSpellIdsMemo[$key];
        }

        $availabilityIds = Spell::whereHas('classAvailability', function ($q) use ($classId, $specId) {
            $q->where('class_id', $classId)
                ->whereIn('source', ['baseline', 'talent', 'pvp_talent'])
                ->where(fn ($q2) => $q2->whereNull('spec_id')->orWhere('spec_id', $specId));
        })->pluck('id');

        $heroTreeIds = collect();
        if ($heroTreeId) {
            $heroTreeIds = Spell::whereHas(
                'talentNodeEntries.talentNode',
                fn ($q) => $q->where('talent_tree_id', $heroTreeId)
            )->pluck('id');
        }

        return $this->kitSpellIdsMemo[$key] = $availabilityIds->merge($heroTreeIds)->unique()->values();
    }

    /**
     * What modifies/enhances a mentioned spell, split into two groups per the user's explicit
     * request (2026-07-25): 'named' — real talent/spell modifiers worth surfacing per-row, and
     * 'baseline' — the generic always-on class-wide passive auras (e.g. "Priest", "Discipline
     * Priest") that show up on nearly everything and would otherwise repeat under every single
     * spell. Both a structural pass (spell_relationships, catches things like Weal and Woe on
     * Power Word: Shield) and a textual pass (description-text scan, catches proc-relationships
     * with no spell_id link at all, e.g. Borrowed Time -> Power Word: Shield) are needed —
     * confirmed against real data that neither alone covers both known cases.
     *
     * The 'named' bucket deliberately uses a *stricter* in-build check than buildKitSpellIds()'s
     * general membership test (isConfidentlyInBuild(), below) — confirmed against real data
     * (2026-07-25) that the loose "class-wide, spec_id null" fallback lets Shadow-only mechanics
     * (Shadowy Insight, Twilight Equilibrium, most Voidform copies) leak into a Discipline
     * module's modifier list, because those spells only exist in baseline.txt with a bare
     * "Class: Priest" tag and no spec qualifier at all — the same data limitation already known
     * from the Vampiric Embrace/Premonition cases, not a new bug. buildKitSpellIds() itself stays
     * unchanged (GameDataBrowser's admin-exploration use case correctly wants the loose,
     * err-on-the-side-of-showing behavior) — only this player-facing curation path tightens it.
     * Anything that fails the strict check, and isn't the generic baseline-aura bucket either,
     * is silently dropped rather than shown as an unexplained "named" modifier — an honest
     * omission, not a guess. A small denylist filters out tier-set-bonus noise (e.g. "Priest -
     * Midnight PrePatch - 11.2 Class Set 2pc") before either bucket, since those aren't talents
     * at all and would show with zero useful explanation regardless of spec-scoping.
     *
     * Since 2026-07-27, the kit/tree/baseline-aura context used for all of this is resolved per
     * spell via resolveKitContext() rather than always assuming the module's own build — see the
     * class docblock. A spell from an opponent class therefore gets modifiers scoped to ITS OWN
     * class's baseline/spec-agnostic kit, not incorrectly checked against the module's build.
     *
     * Since 2026-07-30, the 'named' bucket also requires the candidate to be in
     * $selectedSpellIds — a talent that's a valid kit member but not currently selected no
     * longer shows as if it were actively applying (see TalentSelectionService, which resolves
     * what "currently selected" means for a user/guest). Pass an empty collection to get the
     * old, selection-blind "everything possible" behavior. The 'baseline' bucket (always-on
     * class passives) is deliberately NOT gated — those aren't optional picks.
     *
     * Since 2026-08-06, $selectedRanks additionally lets a rank-scaled modifier (see
     * SpellRelationship's docblock and resolveRankAwareMagnitude()) resolve to the magnitude for
     * the rank the current build actually selected, rather than showing no number at all for a
     * talent ImportSpellData deliberately left un-computed at import time.
     *
     * @return array{named: Collection, baseline: Collection}
     */
    public function modifiersFor(Spell $spell, ModuleGameBuild $build, ?Collection $selectedSpellIds = null, ?Collection $selectedRanks = null): array
    {
        $selectedSpellIds ??= collect();
        $selectedRanks ??= collect();
        $context = $this->resolveKitContext($spell, $build);
        $kitIds = $this->buildKitSpellIdsFor($context['class_id'], $context['spec_id'], $context['hero_tree_id']);
        $isBaseline = $this->genericBaselineAuraCheckerFor($context['class_id'], $context['spec_id']);
        $treeIds = $this->buildTreeIdsFor($context['class_id'], $context['spec_id'], $context['hero_tree_id']);

        $named = collect();
        $baseline = collect();
        $seenIds = collect([$spell->id]);

        $classify = function (Spell $candidate, string $relationshipType, ?SpellRelationship $rel = null) use (
            &$named, &$baseline, $isBaseline, $context, $treeIds, $selectedSpellIds, $selectedRanks
        ) {
            if ($this->isKnownJunk($candidate)) {
                return;
            }

            [$modifierValue, $modifierUnit] = $rel
                ? $this->resolveRankAwareMagnitude($rel, $candidate, $selectedRanks)
                : [null, null];

            $entry = [
                'spell' => $candidate,
                'relationship_type' => $relationshipType,
                'modifier_value' => $modifierValue,
                'modifier_unit' => $modifierUnit,
            ];

            if ($isBaseline($candidate)) {
                $baseline->push($entry);

                return;
            }

            if (!$selectedSpellIds->contains($candidate->id)) {
                // Not currently selected — still a real, possible modifier, just not applying
                // right now. Dropped from 'named' rather than shown as if it were active; see
                // effectiveCooldown() for the same selection gate applied to the computed number.
                return;
            }

            if ($this->isConfidentlyInBuild($candidate, $context['class_id'], $context['spec_id'], $treeIds)) {
                $named->push($entry);
            }
            // else: ambiguous class-wide tag, not an actual talent in this build's trees, not
            // explicitly Discipline-tagged — dropped rather than shown as unexplained noise.
        };

        foreach ($spell->incomingRelationships as $rel) {
            $source = $rel->sourceSpell;

            if (!$source || !$kitIds->contains($source->id)) {
                continue;
            }

            // $seenIds is still populated here (even though it's no longer used to gate this
            // loop) so the later text-scan pass below doesn't re-detect a source spell that's
            // already been found structurally. Fixed 2026-08-02: this used to also gate the loop
            // above (`$seenIds->contains($source->id)` as a skip condition), which meant a
            // source spell's FIRST relationship row to $spell won and every other row from that
            // same source (a different relationship_type — e.g. a generic 'modifies' row from
            // the Affecting-Spells pass alongside a magnitude-bearing 'modifies_cooldown' row
            // from the Category pass) was silently dropped. Confirmed on Discipline Priest ->
            // Mind Blast: two real rows exist (id 30352 'modifies', id 46366 'modifies_cooldown'
            // +19s) and only the first was ever classified. A source spell having multiple
            // distinct relationship types to the same target is a normal, expected pattern, not
            // a duplicate to collapse.
            $seenIds->push($source->id);
            $classify($source, $rel->relationship_type, $rel);
        }

        $textCandidateIds = $kitIds->diff($seenIds);
        if ($textCandidateIds->isNotEmpty()) {
            $textMatches = Spell::whereIn('id', $textCandidateIds)
                ->where('description', 'like', '%'.$spell->name.'%')
                ->get();

            foreach ($textMatches as $match) {
                $seenIds->push($match->id);
                $classify($match, 'mentions');
            }
        }

        return [
            'named' => $this->dedupeGenericModifies($named),
            'baseline' => $this->dedupeGenericModifies($baseline),
        ];
    }

    /**
     * Drops a redundant bare 'modifies' entry when the SAME source spell also has a more
     * specific relationship (e.g. 'modifies_cooldown') to the same target — found 2026-08-09
     * investigating a real report ("Improved Traps doesn't give a number, but Freezing Trap's
     * cooldown IS correctly reduced" — same shape independently reported for Territorial
     * Instincts -> Intimidation). Root cause: a source spell commonly has TWO separate
     * spell_relationships rows to the same target — one 'modifies' (no magnitude, from the
     * Affecting-Spells text pass) and one 'modifies_cooldown' (real magnitude, from the
     * Category-effect pass). The 2026-08-02 "Bug 1" fix deliberately stopped deduping by
     * source alone so a source with two genuinely DIFFERENT effect types (e.g. damage% AND
     * cooldown) still shows both — correct. But it also means this literal-duplicate-signal
     * case (same source, same target, one row just less specific than the other) renders as
     * two list rows for the same ability, one confusingly numberless, instead of being
     * recognized as the same fact stated twice at different precision. Fixed here rather than
     * in classify()/the relationship loop, since the decision needs to see a source's full set
     * of relationship types to this target before deciding whether 'modifies' is redundant —
     * that set isn't known until the loop finishes.
     *
     * A source with ONLY a bare 'modifies' relationship (no more specific type exists at all)
     * keeps that entry — it's still the only signal we have that the source affects the
     * target, just without a known number.
     */
    private function dedupeGenericModifies(Collection $entries): Collection
    {
        return $entries
            ->groupBy(fn (array $entry) => $entry['spell']->id)
            ->flatMap(function (Collection $group) {
                $hasSpecific = $group->contains(fn (array $entry) => $entry['relationship_type'] !== 'modifies');

                return $hasSpecific
                    ? $group->reject(fn (array $entry) => $entry['relationship_type'] === 'modifies')
                    : $group;
            })
            ->values();
    }

    /**
     * Resolves a relationship's magnitude, filling in the rank-scaled case ImportSpellData
     * deliberately left un-computed at import time (see modifiesRelationshipMapping()/
     * categoryRelationshipMapping()'s docblocks, and SpellDataFileParser's rank_scaling capture,
     * 2026-08-06). Two cases:
     *
     * - $rel already has a magnitude (the common case — a fixed, not rank-dependent modifier) —
     *   returned as-is, no extra query.
     * - $rel has no magnitude but does have an effect_index — look up that specific effect on
     *   $source. If it's rank-scaled (SpellEffect.rank_op set), resolve the number for the rank
     *   $selectedRanks says this build actually has: 'set' means rank_values[rank-1] IS the
     *   value (replaces base_value); 'mul' means base_value × rank_values[rank-1]. The rank
     *   itself comes from $selectedRanks (built by TalentSelectionService::selectedRanks(), only
     *   ever populated for PvE picks — by the time this runs, $source is already confirmed
     *   selected via $selectedSpellIds' gate in modifiersFor(), so a missing rank here means the
     *   data is present but the specific rank wasn't captured for some other reason, not that
     *   nothing was selected — falls back to the highest rank in rank_values ("assume full
     *   investment") rather than showing no number for a talent that IS selected, flagged via the
     *   fallback's own comment below rather than silently guessed).
     *
     * The unit conversion (÷1000 for seconds, none for charges) is driven by $rel->relationship_type
     * — already correctly classified at import time regardless of rank — not re-derived from the
     * effect's type string a second time.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function resolveRankAwareMagnitude(SpellRelationship $rel, Spell $source, Collection $selectedRanks): array
    {
        if ($rel->modifier_value !== null) {
            return [(float) $rel->modifier_value, $rel->modifier_unit];
        }

        if ($rel->effect_index === null) {
            return [null, null];
        }

        $effect = $this->findEffectByIndex($source, $rel->effect_index);

        if (!$effect || $effect->rank_op === null || empty($effect->rank_values)) {
            return [null, null];
        }

        $rank = $selectedRanks->get($source->id);
        $rankValues = $effect->rank_values;
        // Missing rank despite a confirmed selection (see docblock) — assume the highest
        // available rank rather than showing nothing for a talent that IS selected.
        $rankIndex = $rank !== null ? max(0, $rank - 1) : count($rankValues) - 1;
        $rankIndex = min($rankIndex, count($rankValues) - 1);

        $rawValue = $effect->rank_op === 'mul'
            ? ($effect->base_value ?? 0) * $rankValues[$rankIndex]
            : $rankValues[$rankIndex];

        return match ($rel->relationship_type) {
            'modifies_cooldown' => [$rawValue / 1000, 'seconds'],
            'modifies_charges' => [$rawValue, 'charges'],
            default => [null, null],
        };
    }

    /**
     * Resolves $spell's own base cooldown_seconds/charges/duration_seconds, falling back
     * independently (each field on its own — a spell can be missing one and not the other) —
     * first to a same-named sibling in the same patch, then (added 2026-08-17) to whatever
     * spell_id the description's own `$<id>d`/`$<id>s<n>` tokens explicitly reference. Same
     * sibling-recovery pattern as categorize()'s effect merge and findEffectByIndex() — added
     * 2026-08-10 after a real case: Rogue's Smoke Bomb reaches display via a PvP-talent-selected
     * spell_id (212182/359053) that carries no cooldown data at all, while a separate same-named
     * baseline record (76577) has the real "180" — same "one ability, split across multiple
     * spell_id records, only one carries the real number" shape already seen for Angelic
     * Bulwark/Anti-Magic Zone/Void Bolt's icon, just affecting the base cooldown/charges fields
     * this time instead of description/category/icon.
     *
     * The description-reference tier was added after a real, confirmed gap the name-matching
     * tier structurally can't catch: Axe Toss's displayed copy (id 10853, suffixed "(desc=
     * Command Demon Ability)") has null cooldown/duration, and its real data lives on a
     * DIFFERENTLY-named sibling (id 10815, "Axe Toss (desc=Special Ability)") — same-name
     * matching finds nothing, because the names genuinely differ. But Axe Toss's own
     * description text says "...for $89766d" — Blizzard's own explicit pointer to spell_id
     * 89766, which resolves to exactly that record. This is a *more* reliable signal than
     * name-matching (an authored reference, not a heuristic), so it's tried as an additional
     * fallback tier after the same-name pass — reusing resolveValueToken()'s own `$<id>d`/
     * `$<id>s<n>` regex shape and findSpellBySpellId(), not a new parsing mechanism.
     *
     * Never guesses a value — only borrows a real, non-null field from a sibling or an
     * explicitly-referenced spell; a spell with neither carrying either field stays exactly as
     * unresolved as before.
     *
     * @return array{seconds: ?float, charges: ?int, duration: ?float}
     */
    private function resolveBaseCooldownCharges(Spell $spell): array
    {
        if (array_key_exists($spell->id, $this->baseCooldownChargesMemo)) {
            return $this->baseCooldownChargesMemo[$spell->id];
        }

        $siblings = Spell::where('name', $spell->name)
            ->where('patch_id', $spell->patch_id)
            ->where('id', '!=', $spell->id)
            ->get();

        return $this->baseCooldownChargesMemo[$spell->id] = $this->resolveBaseCooldownChargesFromSiblings($spell, $siblings);
    }

    /**
     * Bulk-primes resolveBaseCooldownCharges()'s memo for every spell in $spells in ONE query
     * per patch, instead of one query per spell as each is resolved individually later. Added
     * 2026-08-19 after direct profiling of a cold WowComps render (a spec with 175 display
     * entries): this exact per-spell sibling query was the single largest contributor, ~563 of
     * ~1800 total queries, to a 3.2s cold-cache render. The per-spell memoization above was
     * already correct — it just can't help when most of the ~175+ spells genuinely are each
     * being resolved for the first time in a request; the actual fix is fewer round trips, not
     * more caching. Reuses the exact same resolution logic via
     * resolveBaseCooldownChargesFromSiblings() — this only changes WHERE the sibling data comes
     * from (one bulk query grouped by name, vs. one query per spell), never the resolution
     * rules. Safe to call with any subset of spells (e.g. only the main display entries, not
     * their modifier spells) — anything not covered here simply falls back to
     * resolveBaseCooldownCharges()'s own per-spell query when first needed, same as before this
     * existed; already-memoized spells are skipped without a query.
     */
    public function preloadBaseCooldownCharges(Collection $spells): void
    {
        $pending = $spells->reject(fn (Spell $s) => array_key_exists($s->id, $this->baseCooldownChargesMemo));

        if ($pending->isEmpty()) {
            return;
        }

        foreach ($pending->groupBy('patch_id') as $patchId => $group) {
            $names = $group->pluck('name')->unique()->values();

            $byName = Spell::where('patch_id', $patchId)
                ->whereIn('name', $names)
                ->get()
                ->groupBy('name');

            foreach ($group as $spell) {
                if (array_key_exists($spell->id, $this->baseCooldownChargesMemo)) {
                    continue;
                }

                $siblings = ($byName[$spell->name] ?? collect())
                    ->reject(fn (Spell $s) => $s->id === $spell->id)
                    ->values();

                $this->baseCooldownChargesMemo[$spell->id] = $this->resolveBaseCooldownChargesFromSiblings($spell, $siblings);
            }
        }
    }

    /**
     * The actual seconds/charges/duration resolution rules, shared by both the per-spell path
     * (resolveBaseCooldownCharges()) and the bulk-preload path (preloadBaseCooldownCharges())
     * above — kept as one method so the two call sites can never drift into different behavior.
     *
     * @param  Collection<int, Spell>  $siblings  same-named, same-patch spells other than $spell itself
     * @return array{seconds: ?float, charges: ?int, duration: ?float}
     */
    private function resolveBaseCooldownChargesFromSiblings(Spell $spell, Collection $siblings): array
    {
        $seconds = $spell->cooldown_seconds !== null ? (float) $spell->cooldown_seconds : null;
        $charges = $spell->charges;
        $duration = $spell->duration_seconds !== null ? (float) $spell->duration_seconds : null;

        $applyCandidate = function (?Spell $candidate) use (&$seconds, &$charges, &$duration): void {
            if (!$candidate) {
                return;
            }
            if ($seconds === null && $candidate->cooldown_seconds !== null) {
                $seconds = (float) $candidate->cooldown_seconds;
            }
            if ($charges === null && $candidate->charges !== null) {
                $charges = $candidate->charges;
            }
            if ($duration === null && $candidate->duration_seconds !== null) {
                $duration = (float) $candidate->duration_seconds;
            }
        };

        if ($seconds === null || $charges === null || $duration === null) {
            foreach ($siblings as $sibling) {
                $applyCandidate($sibling);
                if ($seconds !== null && $charges !== null && $duration !== null) {
                    break;
                }
            }
        }

        if ($seconds === null || $charges === null || $duration === null) {
            foreach ($this->findDescriptionReferencedSpells($spell) as $referenced) {
                $applyCandidate($referenced);
                if ($seconds !== null && $charges !== null && $duration !== null) {
                    break;
                }
            }
        }

        return ['seconds' => $seconds, 'charges' => $charges, 'duration' => $duration];
    }

    /**
     * Every distinct spell explicitly referenced by $spell's own description via a `$<id>d` or
     * `$<id>s<n>` token — Blizzard's own pointer to another spell_id's duration or effect value,
     * the same token shape resolveValueToken() already parses for text substitution. Reused here
     * (by resolveBaseCooldownCharges()) as a scalar-field fallback source: when a description
     * explicitly names another spell_id, that's stronger evidence of "this is the real data
     * record" than a same-name-string heuristic. Order-preserving, deduplicated by spell_id.
     *
     * @return array<int, Spell>
     */
    private function findDescriptionReferencedSpells(Spell $spell): array
    {
        if (!$spell->description) {
            return [];
        }

        preg_match_all('/\$(\d+)(?:s\d+|d)\b/', $spell->description, $matches);

        $referenced = [];
        foreach (array_unique($matches[1]) as $externalSpellId) {
            $other = $this->findSpellBySpellId((int) $externalSpellId, $spell->patch_id);
            if ($other && $other->id !== $spell->id) {
                $referenced[] = $other;
            }
        }

        return $referenced;
    }

    /**
     * Computes $spell's effective cooldown given which talents are actually selected —
     * $spell->cooldown_seconds (the base value, sibling-recovered via
     * resolveBaseCooldownCharges() when the spell's own copy has none) with every selected,
     * magnitude-bearing 'modifies_cooldown' modifier applied: flat seconds first, then percent
     * (the layering order validated by hand against a real in-game report in game-data.md's
     * Mind Blast worked example). Modifiers without a computable magnitude
     * (modifier_value/modifier_unit null — see SpellRelationship's docblock) still show up in
     * modifiersFor()'s 'named' list descriptively, they just don't change this number — never
     * guessed.
     *
     * @return array{seconds: ?float, base_seconds: ?float, applied: Collection}
     */
    public function effectiveCooldown(Spell $spell, ModuleGameBuild $build, Collection $selectedSpellIds, ?Collection $selectedRanks = null): array
    {
        $base = $this->resolveBaseCooldownCharges($spell)['seconds'];
        $result = $this->effectiveScalarValue($spell, $build, $selectedSpellIds, $base, 'modifies_cooldown', 'seconds', $selectedRanks);

        return ['seconds' => $result['value'], 'base_seconds' => $result['base'], 'applied' => $result['applied']];
    }

    /**
     * Blizzard's own single-value "Mechanic" classification (spells.mechanic, captured 2026-08-06
     * — see SpellDataFileParser) mapped to categorize()'s five buckets. Checked before the
     * effect-type regex fallback below since it's Blizzard's own authoritative tag, not an
     * inference from effect-type strings — found while investigating a real miscategorization:
     * Mind Control (a Priest Charm effect) showed as Offensive because its core mechanic effect
     * type is literally "Possess" (not recognized by the old regex), while its incidental "Modify
     * Damage Done%" side effect happened to match the Offensive pattern instead. Mind Control's
     * own record carries `Mechanic: Charm`, which this map resolves correctly with no regex
     * guessing at all.
     *
     * Built from a full survey of every distinct Mechanic value in the dataset (2026-08-06) —
     * not guessed. A few are genuine judgment calls, flagged here rather than hidden: 'Snare' and
     * 'Knockback' are movement-control tools used both offensively and defensively — filed under
     * Utility rather than Crowd Control (a snare/knockback isn't "hard" CC the way a stun/root/
     * fear is, and lumping every slow into the CC bucket would dilute it). 'Heal' is filed under
     * Defensive since every spell carrying this tag in practice is a self-preservation cooldown,
     * not a dedicated healer spell (this dataset has no distinct "Healing" bucket).
     *
     * @var array<string, string>
     */
    private const MECHANIC_CATEGORY_MAP = [
        'Stun' => 'Crowd Control',
        'Root' => 'Crowd Control',
        'Silence' => 'Crowd Control',
        'Sleep' => 'Crowd Control',
        'Freeze' => 'Crowd Control',
        'Charm' => 'Crowd Control',
        'Incapacitate' => 'Crowd Control',
        'Disorient' => 'Crowd Control',
        'Sap' => 'Crowd Control',
        'Polymorph' => 'Crowd Control',
        'Horrify' => 'Crowd Control',
        'Banish' => 'Crowd Control',
        'Shackle' => 'Crowd Control',
        'Flee' => 'Crowd Control',
        'Turn' => 'Crowd Control',
        'Invulnerable' => 'Defensive',
        'Invulnerable 2' => 'Defensive',
        'Shield' => 'Defensive',
        'Heal' => 'Defensive',
        'Bleed' => 'Offensive',
        'Enrage' => 'Offensive',
        'Taunt' => 'Utility',
        'Interrupt' => 'Utility',
        'Distract' => 'Utility',
        'Knockback' => 'Utility',
        'Snare' => 'Utility',
    ];

    /**
     * Best-effort display grouping (Crowd Control / Defensive / Utility / Offensive / Other) for
     * the Spells table — added 2026-08-02, purely a view-layer heuristic over each spell's
     * already-captured `spell_effects.type` strings ($spell->effects must be eager-loaded by the
     * caller). No new data, no parser changes, nothing written anywhere.
     *
     * Deliberately NOT authoritative — spot-checked against real spells before shipping (same
     * "verify before trusting" posture as everywhere else in this codebase) and several
     * multi-purpose spells genuinely don't fit one bucket cleanly: Avatar carries both a damage%
     * buff and a damage-taken% reduction; Fade mixes a threat-drop (Utility) with a damage-taken%
     * dip. Checked in priority order below — the first matching bucket wins — CC first since a
     * Stun/Fear/Root effect is the least ambiguous signal available, Other last as the catch-all
     * for anything that doesn't match any keyword (a passive/proc-only spell, mostly).
     *
     * Two layers, added 2026-08-06: `spells.mechanic` (MECHANIC_CATEGORY_MAP above) is checked
     * first when present — Blizzard's own tag, more reliable than inferring from effect-type
     * strings. When absent or unmapped, falls through to the original effect-type regex — and,
     * if that alone comes back 'Other', to the SAME same-named-sibling recovery
     * findEffectByIndex() already uses elsewhere in this file. Found via a real case: Anti-Magic
     * Zone is split across two spell_id records (the talent-tree entry, whose only effect is
     * "Create Area Trigger" — no categorizable signal at all — and a separate baseline record
     * that carries the real "Absorb Damage" effect, with its own description pointing back at
     * the talent entry via `$@spelldesc`). Neither record has a Mechanic tag, so only the sibling
     * merge closes this one — without it, the talent-tree entry (the one actually selected by a
     * build, and therefore the one actually rendered) permanently reads as 'Other' regardless of
     * what the ability actually does.
     *
     * Sibling merge hardened 2026-08-08 after a real misfire: Breath of Sindragosa's own record
     * has no categorizable effect at all (just Trigger Spell/Periodic Trigger Spell — it's a
     * wrapper that ticks a separate damage spell), so it fell through to sibling recovery across
     * its 9 same-named internal records. That pool correctly contains the real
     * "School Damage: frost" effect — but it ALSO contains a "Direct Heal, Base Value: 1,
     * Target: Self" effect on a different sibling (spell_id 155168, referenced by real talents
     * like "A Feast of Souls" — real data, though its actual connection to Breath of Sindragosa
     * itself is unconfirmed; the real in-game tooltip has no self-heal at all, so 155168 sharing
     * this display name may just be a coincidental internal-record collision, the same pattern
     * already seen elsewhere in this file for Divine Star/Penance). Whichever it is, a
     * `Base Value: 1` effect isn't meaningful gameplay signal either way, and because Defensive
     * is checked before Offensive in the match arms below, it silently overrode the real damage
     * signal, showing the DK's biggest offensive channel as "Defensive". Fixed by
     * categorizeFromEffects() below now receiving full SpellEffect objects (not bare type
     * strings) so it can filter out exactly this shape (a Direct/Periodic Heal whose own
     * base_value and scaled_value are both <= 1, AND has no sp_coefficient) before building the
     * match string — scoped narrowly to healing effects specifically, since that's the only
     * confirmed case of this failure mode; not a general "ignore small numbers" rule. The
     * sp_coefficient check is required, not optional — a first version of this fix rejected on
     * base_value/scaled_value alone and broke Swiftmend/Wild Growth/Riptide, whose real heal
     * effects also show base_value=0/scaled_value=0 (the normal signature of a real SP-scaled
     * effect, see resolveDescription()'s SP Coefficient work — the actual magnitude lives in
     * sp_coefficient, not these two flat fields, for exactly these kinds of spells). Confirmed
     * directly: Swiftmend/Wild Growth/Riptide all carry a real, nonzero sp_coefficient (10.37,
     * 0.36, 4.80); Breath of Sindragosa's bookkeeping effect has sp_coefficient = NULL. Requiring
     * "no sp_coefficient either" is what correctly separates the two cases.
     *
     * @return string One of: 'Crowd Control', 'Defensive', 'Utility', 'Offensive', 'Other'
     */
    public function categorize(Spell $spell): string
    {
        if (array_key_exists($spell->id, $this->categorizeMemo)) {
            return $this->categorizeMemo[$spell->id];
        }

        $ownCategory = $this->categorizeFromOwnEffects($spell);

        if ($ownCategory !== null) {
            return $this->categorizeMemo[$spell->id] = $ownCategory;
        }

        $siblingEffects = Spell::where('name', $spell->name)
            ->where('patch_id', $spell->patch_id)
            ->where('id', '!=', $spell->id)
            ->with('effects')
            ->get()
            ->flatMap(fn (Spell $sibling) => $sibling->effects);

        return $this->categorizeMemo[$spell->id] = ($siblingEffects->isEmpty() ? 'Other' : $this->categorizeFromEffects($siblingEffects));
    }

    /**
     * The query-free half of categorize() — mechanic map + the spell's own (already eager-
     * loaded) effects, no DB access. Returns null when both come back 'Other', meaning the
     * caller needs the sibling-effects query to possibly upgrade it. Split out so
     * preloadCategorize() below can find which spells actually need that query WITHOUT calling
     * categorize() itself for each one first (which would just run the very queries this exists
     * to batch away).
     */
    private function categorizeFromOwnEffects(Spell $spell): ?string
    {
        // Curated, verified columns outrank any effect-string inference (added 2026-08-28 after
        // a full RMP-spell audit found categorize() never consulted them). dr_category is
        // hand-curated per spell (cc-synergies-overrides.txt) — a spell that has one IS crowd
        // control no matter what incidental damage/other effects it also carries. Real misfires
        // this fixes: Dragon's Breath (School Damage + Disorient) read as Offensive; Dismantle
        // (Disarm — its own effects are just a Dummy) read as Other.
        //
        // 'Slow' is deliberately excluded: it's the softest DR category (a snare, not hard CC),
        // and several damage nukes carry a slow/root-on-impact rider whose point is still the
        // damage — Glacial Spike is Offensive, not Crowd Control. Same judgment
        // MECHANIC_CATEGORY_MAP already applies to 'Snare'/'Knockback' mechanics. A pure slow
        // with no damage signal falls through to the effect check below.
        if ($spell->dr_category !== null && $spell->dr_category !== 'Slow') {
            return 'Crowd Control';
        }

        if ($spell->is_interrupt) {
            return 'Utility';
        }

        if ($spell->mechanic !== null && isset(self::MECHANIC_CATEGORY_MAP[$spell->mechanic])) {
            return self::MECHANIC_CATEGORY_MAP[$spell->mechanic];
        }

        $category = $this->categorizeFromEffects($spell->effects);

        return $category !== 'Other' ? $category : null;
    }

    /**
     * Bulk-primes categorize()'s memo for every spell in $spells whose OWN effects don't already
     * resolve to a real category (i.e. would otherwise fall through to the per-spell sibling
     * query in categorize()) — one query per patch instead of one per such spell. Added
     * 2026-08-19 alongside preloadBaseCooldownCharges()/ArenaLogService::preloadPrioritySpells(),
     * after profiling found this was the largest single remaining cost once those two were fixed
     * (~386 of ~1477 total queries in a cold WowComps render). categorize() had no memoization
     * at all before this — added here too, so a modifier spell shared across several main
     * entries is only ever categorized once per request either way.
     */
    public function preloadCategorize(Collection $spells): void
    {
        $pending = $spells->filter(function (Spell $spell) {
            if (array_key_exists($spell->id, $this->categorizeMemo)) {
                return false;
            }

            $ownCategory = $this->categorizeFromOwnEffects($spell);

            if ($ownCategory !== null) {
                $this->categorizeMemo[$spell->id] = $ownCategory;

                return false;
            }

            return true;
        });

        if ($pending->isEmpty()) {
            return;
        }

        foreach ($pending->groupBy('patch_id') as $patchId => $group) {
            $names = $group->pluck('name')->unique()->values();

            $byName = Spell::where('patch_id', $patchId)
                ->whereIn('name', $names)
                ->with('effects')
                ->get()
                ->groupBy('name');

            foreach ($group as $spell) {
                $siblingEffects = ($byName[$spell->name] ?? collect())
                    ->reject(fn (Spell $s) => $s->id === $spell->id)
                    ->flatMap(fn (Spell $sibling) => $sibling->effects);

                $this->categorizeMemo[$spell->id] = $siblingEffects->isEmpty() ? 'Other' : $this->categorizeFromEffects($siblingEffects);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SpellEffect>  $effects
     *
     * Defensive/Utility patterns extended 2026-08-06 after surveying every currently-selected
     * *active* ability (has a cooldown or charges — i.e. actually shows under "Active Abilities",
     * not "Buffs & Passives") still falling into 'Other': 86 of 2,485. Two real, clean clusters
     * found, both added below — 'Interrupt Cast' (7 confirmed real interrupts: Mind Freeze,
     * Quell, Counter Shot, Muzzle, Spear Hand Strike, Rebuke, Wind Shear, none previously
     * recognized) and healing/armor effect types (Direct Heal/Periodic Heal/Heal Max Health%/
     * Modify Armor — Swiftmend, Wild Growth, Lay on Hands, Riptide, Power Word: Radiance, etc.),
     * filed under Defensive for the same reason MECHANIC_CATEGORY_MAP's 'Heal' entry already is
     * (this dataset has no distinct "Healing" bucket, and every one of these in practice is a
     * self/ally-preservation cooldown). The remaining ~79 "Other" active abilities after this
     * pass are overwhelmingly summons (Summon Guardian/Pet — genuinely ambiguous, could be
     * offensive, defensive, or utility depending on the specific pet) and generic stat/haste/
     * cooldown-modifier cooldowns (Trueshot, Power Infusion, Nature's Swiftness) whose own effect
     * types don't say enough to safely guess a bucket — left as 'Other' rather than force a
     * confident-looking label onto a genuinely ambiguous case.
     *
     * Offensive pattern extended 2026-08-08: Trueshot (crit chance/damage cooldown) was exactly
     * this kind of "Other" case — its effects are all Modify Critical Strike%/Add Percent
     * Modifier: Spell Critical Bonus Multiplier, none of which matched the old pattern
     * (School Damage%/Damage Done%/Energize). A pure crit-chance-and-damage cooldown is
     * unambiguously offensive (a DPS increase, nothing else), so "Critical Strike%"/"Critical
     * Bonus" were added rather than left as an unresolved "Other" alongside the genuinely
     * ambiguous summon/generic-stat cases.
     */
    private function categorizeFromEffects(Collection $effects): string
    {
        $meaningfulTypes = $effects
            ->reject(function (SpellEffect $effect) {
                $isHealType = str_contains((string) $effect->type, 'Direct Heal') || str_contains((string) $effect->type, 'Periodic Heal');

                return $isHealType
                    && (float) ($effect->base_value ?? 0) <= 1
                    && (float) ($effect->scaled_value ?? 0) <= 1
                    && $effect->sp_coefficient === null;
            })
            ->pluck('type');

        $joined = $meaningfulTypes->implode(' | ');

        // Reworked 2026-08-28 from an ordered first-match `match` to explicit signal detection.
        // The old order tested CC-string, then Defensive, then Utility, all BEFORE Offensive, so
        // any hybrid spell whose point is damage but which also carried a small heal / threat-drop
        // / resource gain / stun-on-impact rider got mislabelled: Penance & Ultimate Penitence ->
        // Defensive (incidental heal effect); Shadow Dance -> Utility (a "Spell Direct Amount +%"
        // damage amp lost to an incidental Threat Reduction); Vanish -> Offensive (an incidental
        // combo-point Energize); Metamorphosis / Heroic Leap -> Crowd Control (a stun-on-landing
        // sibling effect). Now: real damage output or an unambiguous damage amp wins over every
        // co-occurring signal — so an uncurated CC-effect string only ever classifies as CC when
        // the spell isn't primarily a damage press (a real hard-CC spell almost always has a
        // hand-curated dr_category, which categorizeFromOwnEffects() already resolved above this).
        // A plain teleport wins over its own incidental mechanic-immunity (Blink/Shimmer/Heroic
        // Leap). `Energize` and `Threat Reduction`/`Aggro` are dropped entirely — a resource gain
        // or a threat drop is never itself the point of a cooldown.
        $dealsDamage = (bool) preg_match('/School Damage|Periodic Damage|Weapon % Damage|Damage Done%/i', $joined);
        $ampsDamage = (bool) preg_match('/Auto Attack Speed%|Modify All Haste%|Critical Strike%|Critical Bonus|Empower/i', $joined);
        $hasCcString = (bool) preg_match('/Stun|Fear|Root|Silence|Incapacitate|Disorient|Charm|Polymorph|Freeze|Sleep|Horror|Confuse|Possess|Banish/i', $joined);
        $isTeleport = (bool) preg_match('/\bLeap\b|Teleport|Jump Charge/i', $joined);
        $defends = (bool) preg_match('/Damage Taken%|Absorb|Immunity|Sanctuary|Block%|Parry%|Dodge%|Damage Reduction|Direct Heal|Periodic Heal|Heal Max Health%|Modify Armor/i', $joined);
        $utility = (bool) preg_match('/Dispel|Increase Speed%|Interrupt Cast|Redirect Threat/i', $joined);

        return match (true) {
            $dealsDamage || $ampsDamage => 'Offensive',
            $hasCcString => 'Crowd Control',
            $isTeleport => 'Utility',
            $defends => 'Defensive',
            $utility => 'Utility',
            default => 'Other',
        };
    }

    /**
     * The charge-count counterpart to effectiveCooldown(), added 2026-08-01 alongside
     * ImportSpellData's modifies_charges split (see game-data.md and SpellRelationship's
     * docblock) — $spell->charges (the base value) with every selected, magnitude-bearing
     * 'modifies_charges' modifier applied (e.g. Protector of the Frail granting Pain Suppression
     * +1 charge). Same "flag, don't guess" posture: a modifier with no computable magnitude still
     * shows up in modifiersFor()'s 'named' list descriptively without changing this number.
     *
     * @return array{charges: ?int, base_charges: ?int, applied: Collection}
     */
    public function effectiveCharges(Spell $spell, ModuleGameBuild $build, Collection $selectedSpellIds, ?Collection $selectedRanks = null): array
    {
        $resolvedCharges = $this->resolveBaseCooldownCharges($spell)['charges'];
        $base = $resolvedCharges !== null ? (float) $resolvedCharges : null;
        $result = $this->effectiveScalarValue($spell, $build, $selectedSpellIds, $base, 'modifies_charges', 'charges', $selectedRanks);

        return [
            'charges' => $result['value'] !== null ? (int) round($result['value']) : null,
            'base_charges' => $resolvedCharges,
            'applied' => $result['applied'],
        ];
    }

    /**
     * Shared implementation behind effectiveCooldown()/effectiveCharges() — both are the same
     * shape (start from a spell's base value for one scalar field, apply every selected,
     * magnitude-bearing modifier of one relationship_type: flat unit first, then percent) and
     * previously existed only as effectiveCooldown(), copy-pasted rather than generalized. Only
     * two relationship_types currently ever carry a magnitude (see SpellRelationship's docblock),
     * so the percent branch is presently inert for 'modifies_charges' — kept for whenever a
     * percent-based charge-rate conversion (e.g. 'modifies_charge_rate') is eventually verified
     * and threaded through, so that case doesn't need a third copy-pasted method.
     *
     * @return array{value: ?float, base: ?float, applied: Collection}
     */
    private function effectiveScalarValue(
        Spell $spell,
        ModuleGameBuild $build,
        Collection $selectedSpellIds,
        ?float $base,
        string $relationshipType,
        string $flatUnit,
        ?Collection $selectedRanks = null
    ): array {
        if ($base === null || $selectedSpellIds->isEmpty()) {
            return ['value' => $base, 'base' => $base, 'applied' => collect()];
        }

        $modifiers = $this->modifiersFor($spell, $build, $selectedSpellIds, $selectedRanks);

        // Fixed 2026-08-02: this used to read only ['named'], silently excluding
        // ['baseline'] — the generic always-on class/spec identity passives (e.g. "Discipline
        // Priest"). That's backwards: 'baseline' entries apply unconditionally (no talent
        // selection needed — you have them by virtue of being that spec), which makes them the
        // *safest* category to include, not the one to drop. Confirmed on Mind Blast: its
        // Discipline-only cooldown override (+19s, "Modify Recharge Time (Category)" on the
        // always-on Discipline Priest passive) never reached this sum, so effectiveCooldown()
        // returned the spell's raw 9s base — the number every viewer of the Discipline Priest
        // Oracle module was actually shown — even though 28s (9 base + 19 baseline) is correct.
        // The relationship_type/modifier_unit filter below is unchanged and applies identically
        // to both buckets, so this doesn't relax which modifiers can contribute — only where
        // they're allowed to come from.
        $named = $modifiers['named']->merge($modifiers['baseline'])
            ->filter(fn (array $entry) => $entry['relationship_type'] === $relationshipType
                && $entry['modifier_value'] !== null && $entry['modifier_unit'] !== null);

        $value = $base;

        foreach ($named->where('modifier_unit', $flatUnit) as $entry) {
            $value += (float) $entry['modifier_value'];
        }

        foreach ($named->where('modifier_unit', 'percent') as $entry) {
            $value *= 1 + ((float) $entry['modifier_value'] / 100);
        }

        return [
            'value' => max($value, 0.0),
            'base' => $base,
            'applied' => $named->values(),
        ];
    }

    /**
     * Stricter than buildKitSpellIds()'s general membership test — see modifiersFor()'s
     * docblock for why. True only when the spell is an actual talent pick in one of the given
     * trees, or its availability is explicitly tagged to this class/spec (not the ambiguous
     * class-wide-with-no-spec-qualifier fallback). Takes class/spec ids directly (rather than a
     * ModuleGameBuild) since 2026-07-27 — the caller resolves the right context per spell via
     * resolveKitContext(), which may differ from the module's own build for an opponent-class
     * spell.
     *
     * @param  array<int, int>  $treeIds
     */
    private function isConfidentlyInBuild(Spell $spell, ?int $classId, ?int $specId, array $treeIds): bool
    {
        if ($classId === null) {
            return false;
        }

        $key = $spell->id.':'.$classId.':'.$specId.':'.implode(',', $treeIds);
        if (array_key_exists($key, $this->confidentlyInBuildMemo)) {
            return $this->confidentlyInBuildMemo[$key];
        }

        $isTalentPick = $spell->talentNodeEntries()
            ->whereHas('talentNode', fn ($q) => $q->whereIn('talent_tree_id', $treeIds))
            ->exists();

        if ($isTalentPick) {
            return $this->confidentlyInBuildMemo[$key] = true;
        }

        return $this->confidentlyInBuildMemo[$key] = $spell->classAvailability()
            ->where('class_id', $classId)
            ->where('spec_id', $specId)
            ->exists();
    }

    /**
     * Tier-set bonuses and similar internal/build-labeled entries (e.g. "Priest - Midnight
     * PrePatch - 11.2 Class Set 2pc") — not talents, would show with zero useful explanation
     * regardless of spec-scoping. A small, explicit denylist rather than a cleverer parse,
     * matching this dataset's known covenant/artifact "(desc=X)" pattern precedent: some noise
     * has to be recognized by name, not derived.
     */
    private function isKnownJunk(Spell $spell): bool
    {
        return (bool) preg_match('/Class Set|PrePatch/i', $spell->name);
    }

    /**
     * Resolves SimC's tooltip placeholder syntax into real numbers: $s1/$s2/... (this spell's
     * own effect values), $d (its own duration), $<id>s1/$<id>d (cross-spell references, e.g.
     * Angelic Bulwark's "$114214d"), ${...} arithmetic expressions, and $?a<id>/$?s<id>
     * conditional branches (resolved by checking whether that spell/aura belongs to this
     * spell's own kit context — see resolveKitContext(), sound for a page that's about one
     * specific, fixed build, or one specific opponent-class ability).
     *
     * Deliberately conservative about the rest: $?c<n> condition codes aren't confidently
     * interpretable without deeper SimC-format knowledge than we have, and some descriptions in
     * this dataset are genuinely truncated at the source (confirmed by hand, 2026-07-25 — Mind
     * Blast's raw record ends mid-conditional with no closing brackets, verified against the
     * literal source file, not a parsing bug on our end). Both cases get honest player-facing
     * copy ("varies by condition — check in-game") instead of a guessed number or raw token, and
     * get logged so they're discoverable rather than silently wrong — see modifiersFor()'s
     * docblock for the same "flag, don't guess" posture applied to modifier scoping.
     *
     * @return array{text: string, uncertain: bool}
     */
    /**
     * resolveDescription() runs fresh on every page load for every spell rendered (by design —
     * see its own docblock), so a page like WowComps (up to ~300 spells across 3 specs in one
     * request) can hit the same permanent, already-known data gap (a $?c code, an unresolved
     * token) hundreds of times per view. Logging each occurrence at WARNING severity flooded
     * the Log Viewer — a single page view could evict 800 lines of real log history, burying
     * genuine errors. These gaps don't change until the underlying SimC dump does, so once a
     * given (spell, gap) pair has been recorded it's genuinely not new information — downgraded
     * to debug (invisible to the Log Viewer's default filter) and deduped for 30 days per key,
     * added 2026-08-08.
     */
    private function logGapOnce(string $key, string $message, array $context): void
    {
        $cacheKey = 'mspell-ref-gap:' . $key;
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addDays(30));
        Log::debug($message, $context);
    }

    public function resolveDescription(Spell $spell, ModuleGameBuild $build): array
    {
        $text = $spell->description ?? '';

        if ($text === '') {
            return ['text' => '', 'uncertain' => false];
        }

        $uncertain = false;
        $context = $this->resolveKitContext($spell, $build);
        $kitIds = $this->buildKitSpellIdsFor($context['class_id'], $context['spec_id'], $context['hero_tree_id']);

        // Pass 0: truncate a dangling, unterminated conditional at the very end of the string
        // (confirmed a real source-data artifact, not something every description has) rather
        // than leave broken "$?s137033[" syntax visible.
        $truncated = preg_replace('/\$\?[acs]\d+\[[^\]]*$/', '', $text);
        if ($truncated !== $text) {
            $uncertain = true;
            $this->logGapOnce("truncated:{$spell->spell_id}", 'ModuleSpellReferenceService: truncated an unterminated trailing conditional', [
                'spell_id' => $spell->spell_id,
            ]);
            $text = rtrim($truncated);
        }

        // Pass 0.5: strip WoW's in-game chat/tooltip color-code markup ("|cAARRGGBB...|r") —
        // pure client-side text-color formatting from the real tooltip, never meaningful data.
        // Found 2026-08-10 leaking raw into displayed prose (e.g. Breath of Sindragosa's
        // "|cFFFFFFFFGrants a charge of Empower Rune Weapon...|r"). Keeps the wrapped text,
        // drops only the color markers. A second pass mops up any unpaired |c.../|r left behind
        // by a malformed/truncated source string, same defensive posture as Pass 0's truncation
        // above — never leaves a raw pipe-code visible even in a source-data edge case.
        //
        // Case-insensitive ("i" modifier) — found 2026-08-13 that 97 spells (Master
        // Shapeshifter, Tree of Life, etc.) use uppercase "|C...|R" instead of "|c...|r", which
        // the original lowercase-only regex silently passed straight through as raw text.
        // Both cases render identically in-game (WoW's own client treats the marker
        // case-insensitively), so there's no data to lose by stripping either.
        $text = preg_replace('/\|c[0-9A-Fa-f]{8}(.*?)\|r/si', '$1', $text);
        $text = preg_replace('/\|c[0-9A-Fa-f]{8}|\|r/i', '', $text);

        // Pass 1: conditional branches. $?a<id>/$?s<id> resolved via this spell's own kit
        // context membership; $?c<n> codes are flagged rather than guessed.
        $text = preg_replace_callback(
            '/\$\?([acs])(\d+)\[([^\[\]]*)\]\[([^\[\]]*)\]/',
            function ($m) use (&$uncertain, $kitIds, $spell) {
                [, $letter, $id, $branchA, $branchB] = $m;

                if ($letter === 'c') {
                    $uncertain = true;
                    $this->logGapOnce("cond:{$spell->spell_id}:{$id}", 'ModuleSpellReferenceService: unresolvable $?c condition code', [
                        'spell_id' => $spell->spell_id, 'code' => $id,
                    ]);

                    return '(varies by condition — check in-game)';
                }

                $other = $this->findSpellBySpellId((int) $id, null);

                return ($other && $kitIds->contains($other->id)) ? $branchA : $branchB;
            },
            $text
        );

        // Pass 1a: compound chained conditionals — "$?(a<id>&a<id>)[branch]?(!a<id>&a<id>)
        // [branch]...[fallback]" — a fundamentally different shape from Pass 1's simple
        // $?a<id>[A][B] (note the boolean expression right after $?, and an arbitrary number of
        // chained ?(cond)[branch] segments before the final bare [fallback]). Found 2026-08-10 on
        // Trueshot, which uses this to pick between "Applies Sentinel's Mark"/"Applies Spotter's
        // Mark" based on which hero talent is known.
        //
        // The wrapping parens around each condition are OPTIONAL — found 2026-08-13 on Painful
        // Invocation ("$?a137031&?s14914[Holy Fire]?a137031&!s14914[...][...]") and 70 other
        // spells: the exact same chained-compound shape, just without "(...)". The "?" prefix
        // on a term (as opposed to "!") is a real, distinct source-data marker too — confirmed
        // from Painful Invocation's own branch pairing ("?s14914" vs "!s14914" as logical
        // complements deciding between two mutually-exclusive branches) that it means the same
        // positive check as no prefix at all, not something else — evaluateConditionExpression()
        // strips it identically to how it already strips "!". Both forms (parenthesized and
        // bare) are matched by one regex and one resolver — see resolveChainedConditional()'s
        // updated docblock. Some cases found in this same batch (e.g. a bare "c<n>" condition
        // code mid-chain, on "Words of the Wise") aren't a recognized term shape at all —
        // evaluateConditionExpression() already returns null for those, which correctly falls
        // through to the same "(varies by condition)" placeholder Pass 1 uses for $?c<n>, rather
        // than guessing.
        //
        // PCRE can't capture a variable number of repeated groups, so the outer regex only
        // finds the token's boundaries; resolveChainedConditional() parses the segments in a
        // PHP loop. Naturally disjoint from Pass 1 above — that one requires exactly two bracket
        // groups immediately adjacent after the id ([A][B] with nothing between them), which a
        // genuinely-chained or multi-term condition never satisfies (there's always a "?" or a
        // "&"/"|" term in the way) — so match order between the two passes doesn't matter.
        $text = preg_replace_callback(
            '/\$\?\(?[^)\[\]]*\)?\[[^\[\]]*\](?:\?\(?[^)\[\]]*\)?\[[^\[\]]*\])*\[[^\[\]]*\]/',
            function ($m) use (&$uncertain, $kitIds, $spell) {
                return $this->resolveChainedConditional($m[0], $kitIds, $uncertain, $spell);
            },
            $text
        );

        // Pass 1b: $@spellname<id> / $@spellicon<id> — cross-spell name/icon references (e.g.
        // Trueshot's "$@spellicon19434 $@spellname19434" labeling which ability a bonus effect
        // applies to). An icon can't render inline in plain text, so that token — along with
        // one adjacent whitespace character — is stripped entirely; without also consuming the
        // trailing space the raw text always writes between the icon and name tokens, removing
        // just the token left a stray leading space in front of the resolved name (confirmed on
        // Trueshot's real output: "faster.  Aimed Shot Cooldown..." — a double space, not one).
        // The name token resolves to that spell's own display name.
        $text = preg_replace('/\$@spellicon\d+\s?/', '', $text);
        $text = preg_replace_callback(
            '/\$@spellname(\d+)/',
            function ($m) use (&$uncertain, $spell) {
                $other = $this->findSpellBySpellId((int) $m[1], $spell->patch_id);

                if ($other) {
                    return $other->display_name;
                }

                $uncertain = true;
                $this->logGapOnce("spellname:{$spell->spell_id}:{$m[1]}", 'ModuleSpellReferenceService: unresolved $@spellname reference', [
                    'spell_id' => $spell->spell_id, 'referenced_spell_id' => $m[1],
                ]);

                return '(unknown spell)';
            },
            $text
        );

        // Pass 1.5: bare "$<varname>" references to a named Variables-block formula (e.g.
        // Penance's "$<penancedamage>") — added 2026-08-02. Actually evaluating the referenced
        // variable's own formula is out of scope (it can chain through multiple conditional
        // talent multipliers — see variablesModifiers()'s docblock for why that's deliberately
        // not attempted), but leaving the raw "$<penancedamage>" token visible in otherwise-clean
        // prose reads as more broken than the plain "(varies)" placeholder every other
        // unresolvable case already falls back to — neither Pass 2 nor Pass 3's token regex
        // matches the angle-bracket form at all, so without this pass it silently passed through
        // unchanged. variablesModifiers() (called separately by the caller) surfaces which real
        // talents affect the value instead.
        $text = preg_replace_callback(
            '/\$<[a-zA-Z0-9]+>/',
            function () use (&$uncertain) {
                $uncertain = true;

                return '(varies)';
            },
            $text
        );

        // Pass 2: ${...} arithmetic — substitute embedded value tokens, then safely evaluate.
        $text = preg_replace_callback(
            '/\$\{([^{}]*)\}/',
            function ($m) use (&$uncertain, $spell) {
                $inner = preg_replace_callback(
                    '/\$(\d*[a-zA-Z]+\d*)/',
                    function ($mm) use (&$uncertain, $spell) {
                        $value = $this->resolveValueToken($mm[1], $spell);

                        if ($value === null) {
                            $uncertain = true;

                            return '0';
                        }

                        return (string) $value;
                    },
                    $m[1]
                );

                $result = $this->safeEval($inner);
                if ($result === null) {
                    $uncertain = true;

                    return '(varies)';
                }

                return $this->formatNumber($result);
            },
            $text
        );

        // Pass 3: remaining bare tokens ($s1, $d, $<id>s1, $<id>d) outside any braces.
        $text = preg_replace_callback(
            '/\$(\d*[a-zA-Z]+\d*)/',
            function ($m) use (&$uncertain, $spell) {
                $value = $this->resolveValueToken($m[1], $spell);

                if ($value !== null) {
                    return $this->formatNumber($value);
                }

                // Coefficient fallback (2026-08-02) — only for a bare bs-token directly in
                // description text, not inside Pass 2's arithmetic (see coefficientDisplay()'s
                // docblock for why). Covers both "$s1" (this spell's own effect) and "$<id>s1"
                // (a cross-spell reference, e.g. a description inherited from a different
                // spell_id whose own effects are what the token was really written against).
                $coefficientText = null;
                if (preg_match('/^s(\d+)$/', $m[1], $sm)) {
                    $coefficientText = $this->coefficientDisplay($spell, (int) $sm[1]);
                } elseif (preg_match('/^(\d+)s(\d+)$/', $m[1], $sm)) {
                    $other = $this->findSpellBySpellId((int) $sm[1], $spell->patch_id);
                    if ($other) {
                        $coefficientText = $this->coefficientDisplay($other, (int) $sm[2]);
                    }
                }

                $uncertain = true;

                if ($coefficientText !== null) {
                    return $coefficientText;
                }

                $this->logGapOnce("token:{$spell->spell_id}:{$m[0]}", 'ModuleSpellReferenceService: unresolved description token', [
                    'spell_id' => $spell->spell_id, 'token' => $m[0],
                ]);

                return '(varies)';
            },
            $text
        );

        return ['text' => $text, 'uncertain' => $uncertain];
    }

    /**
     * Parses and resolves one "$?(cond)[branch]?(cond)[branch)...[fallback]" chained
     * compound-conditional token (see Pass 1a in resolveDescription() above) — walks the
     * segments left to right, returning the first branch whose condition is true, or the
     * trailing bare [fallback] if none are. A condition that can't be confidently evaluated
     * (an unrecognized term shape) immediately returns the same "(varies by condition)"
     * placeholder Pass 1 uses for $?c<n> codes, rather than guessing which branch applies —
     * same "flag, don't guess" posture as everywhere else in this resolver.
     *
     * The wrapping "(...)" around each condition is optional (see Pass 1a's docblock) — each
     * parse regex below uses an alternation to capture either the parenthesized or the bare
     * form into whichever group actually matched.
     */
    private function resolveChainedConditional(string $token, Collection $kitIds, bool &$uncertain, Spell $spell): string
    {
        if (!preg_match('/^\$\?(?:\(([^)]*)\)|([^\[\]()]*))\[([^\[\]]*)\](.*)$/s', $token, $m)) {
            $uncertain = true;

            return '(varies by condition — check in-game)';
        }

        [, $condParen, $condBare, $branch, $rest] = $m;
        $cond = $condParen !== '' ? $condParen : $condBare;

        while (true) {
            $result = $this->evaluateConditionExpression($cond, $kitIds);

            if ($result === null) {
                $uncertain = true;
                $this->logGapOnce("compoundcond:{$spell->spell_id}:{$cond}", 'ModuleSpellReferenceService: unparseable compound condition term', [
                    'spell_id' => $spell->spell_id, 'condition' => $cond,
                ]);

                return '(varies by condition — check in-game)';
            }

            if ($result === true) {
                return $branch;
            }

            if (!preg_match('/^\?(?:\(([^)]*)\)|([^\[\]()]*))\[([^\[\]]*)\](.*)$/s', $rest, $m)) {
                break;
            }

            [, $condParen, $condBare, $branch, $rest] = $m;
            $cond = $condParen !== '' ? $condParen : $condBare;
        }

        if (preg_match('/^\[([^\[\]]*)\]$/s', $rest, $m)) {
            return $m[1];
        }

        $uncertain = true;

        return '(varies by condition — check in-game)';
    }

    /**
     * Evaluates a boolean expression combining "a<id>"/"s<id>" kit-membership checks (optionally
     * negated with a leading "!") with a single & (AND) or | (OR) operator — the only two shapes
     * confirmed in real data so far, e.g. "a1253599&a470943" or "!a1253599&a470943". Returns
     * null (not false) when any term doesn't match the recognized shape, so the caller can
     * distinguish "confidently false" from "don't actually know" — the latter must never
     * silently fall through to the next branch as if it were false.
     *
     * A leading "?" (as opposed to "!") is stripped the same way and treated as a plain,
     * unnegated check — found 2026-08-13 on Painful Invocation ("a137031&?s14914" paired against
     * a second branch's "a137031&!s14914" as its exact logical complement), confirming "?" means
     * the same positive check as no prefix, not a distinct third operation. Not guessed — the
     * branch pairing is the evidence.
     */
    private function evaluateConditionExpression(string $cond, Collection $kitIds): ?bool
    {
        $operator = str_contains($cond, '|') ? '|' : '&';
        $terms = array_map('trim', explode($operator, $cond));

        $results = [];
        foreach ($terms as $term) {
            $negate = str_starts_with($term, '!');
            if ($negate || str_starts_with($term, '?')) {
                $term = substr($term, 1);
            }

            if (!preg_match('/^[as](\d+)$/', $term, $m)) {
                return null;
            }

            $other = $this->findSpellBySpellId((int) $m[1], null);
            $known = $other !== null && $kitIds->contains($other->id);
            $results[] = $negate ? !$known : $known;
        }

        return $operator === '&' ? !in_array(false, $results, true) : in_array(true, $results, true);
    }

    /**
     * Real talent/spell names referenced by this spell's own Variables block (the raw
     * "$var=$?a<id>[...][...]" formula text captured separately from description — see
     * SpellDataFileParser) — added 2026-08-02 as the practical fallback for a formula
     * resolveDescription() can't reduce to one number (Penance's damage/healing multiplies a
     * coefficient by several conditional talent factors — deliberately not resolved into
     * arithmetic, see coefficientDisplay()'s docblock). Rather than show nothing, this surfaces
     * WHICH real talents affect the calculation without asserting the exact math — some are
     * conditional percentage multipliers, some are conditional additions (Penance's Castigation
     * and Harsh Discipline add extra bolts, they don't multiply the coefficient) — a plain name
     * list avoids overclaiming a relationship this method doesn't verify.
     *
     * Matches $?a<id> (aura/talent known) and $?s<id> (spell known) conditionals — not $?c<n>
     * (unresolvable condition codes, same posture as resolveDescription()) and not raw stat
     * tokens ($AP, $INT, $@versadmg, ...), which don't reference a spell at all. Looked up by
     * spell_id directly (a real unique key per patch, unlike name-based lookups elsewhere in
     * this file) so there's no duplicate-name ambiguity to resolve here. An id not present in
     * this patch's imported data (confirmed real — e.g. Mage's "arctic1-4" chain references
     * spell_ids that don't exist anywhere in the current dataset) is silently skipped, never
     * guessed.
     *
     * @return Collection<int, Spell>
     */
    public function variablesModifiers(Spell $spell): Collection
    {
        if (!$spell->variables) {
            return collect();
        }

        preg_match_all('/\$\?[as](\d+)\[/', $spell->variables, $matches);
        $ids = array_unique(array_map('intval', $matches[1] ?? []));

        if (empty($ids)) {
            return collect();
        }

        return Spell::whereIn('spell_id', $ids)
            ->where('patch_id', $spell->patch_id)
            ->orderBy('name')
            ->get()
            ->unique('name')
            ->values();
    }

    /**
     * "s1"/"s2"/... -> this spell's own effect N (scaled_value, falling back to base_value);
     * "d" -> its own duration; "<id>s1"/"<id>d" -> the same, but on another spell entirely (e.g.
     * Angelic Bulwark's "$114214d"). Any other suffix (t/w/m/A/u/...) returns null — genuinely
     * uncertain rather than guessed, since we don't have confirmed semantics for those.
     *
     * Both "sN" branches route through findEffectByIndex() (2026-08-02) rather than a plain
     * "$spell->effects->firstWhere(...)" lookup — see that method's docblock for why: a
     * description's own spell_id record frequently doesn't carry the effect data its own text
     * references (Angelic Bulwark, Roar of Sacrifice — confirmed real, recoverable examples).
     */
    private function resolveValueToken(string $token, Spell $spell): ?float
    {
        if (preg_match('/^s(\d+)$/', $token, $m)) {
            $effect = $this->findEffectByIndex($spell, (int) $m[1]);

            return $effect ? $this->effectValue($effect) : null;
        }

        if ($token === 'd') {
            return $spell->duration_seconds !== null ? (float) $spell->duration_seconds : null;
        }

        if (preg_match('/^(\d+)(s(\d+)|d)$/', $token, $m)) {
            $other = $this->findSpellBySpellId((int) $m[1], $spell->patch_id);

            if (!$other) {
                return null;
            }

            if ($m[2] === 'd') {
                return $other->duration_seconds !== null ? (float) $other->duration_seconds : null;
            }

            $effect = $this->findEffectByIndex($other, (int) $m[3]);

            return $effect ? $this->effectValue($effect) : null;
        }

        return null;
    }

    /**
     * Finds effect #$index for $spell, falling back to a same-named sibling spell_id (same
     * patch) when $spell's own effect at that index is missing or carries no real value.
     *
     * Added 2026-08-02 after two confirmed real cases where a description's own $sN tokens
     * don't resolve against the spell carrying that description at all: Angelic Bulwark
     * (spell_id 114214, the real visible spellbook entry) has only 1 effect of its own, but its
     * description — inherited via a $@spelldesc pointer from spell_id 108945, a hidden internal
     * data-carrier record — references $s1/$s2/$s3, which only exist on 108945. Roar of
     * Sacrifice (spell_id 67481) is a *different* shape of the same underlying problem: no
     * pointer involved at all, its own description is literal text, but SimC's dump itself
     * splits the ability's effects across multiple same-named, non-hidden spell_id records, only
     * one of which (53480, the real Talent Entry) has the complete effect list.
     *
     * Quantified before building this (2026-08-02): 1,015 non-hidden spells dataset-wide
     * reference a $sN index missing from their own effects; 694 (68%) have a same-named sibling
     * that actually carries it, 321 (32%) don't exist anywhere in the imported data — those stay
     * unresolved exactly as before, per the "flag, don't guess" rule the rest of this resolver
     * already follows. This method only ever returns an effect with a real, non-zero value (via
     * effectValue()) — never a sibling's equally-empty effect, and never guesses which sibling is
     * "more correct" beyond "the first one that actually has real data" (same posture as
     * resolveSpellByNameAnyClass()'s own "not expected to be perfect, a wrong pick is a one-line
     * fix" precedent).
     */
    private function findEffectByIndex(Spell $spell, int $index): ?SpellEffect
    {
        $key = $spell->id.':'.$index;
        if (array_key_exists($key, $this->effectByIndexMemo)) {
            return $this->effectByIndexMemo[$key];
        }

        $own = $spell->effects->firstWhere('effect_index', $index);
        if ($own && $this->effectValue($own) !== null) {
            return $this->effectByIndexMemo[$key] = $own;
        }

        $siblings = Spell::where('name', $spell->name)
            ->where('patch_id', $spell->patch_id)
            ->where('id', '!=', $spell->id)
            ->with('effects')
            ->get();

        foreach ($siblings as $sibling) {
            $candidate = $sibling->effects->firstWhere('effect_index', $index);
            if ($candidate && $this->effectValue($candidate) !== null) {
                return $this->effectByIndexMemo[$key] = $candidate;
            }
        }

        // null, or a real (zero-valued-or-coefficient-only) effect — let effectValue()/callers decide.
        return $this->effectByIndexMemo[$key] = $own;
    }

    /**
     * Resolves a cross-spell reference (Blizzard's numeric spell_id, e.g. from "$<id>s1"/"$<id>d"
     * tokens or a $?a<id>/$?s<id> conditional) to a Spell, memoized per (spell_id, patch_id) —
     * added 2026-08-06 alongside findEffectByIndex()'s memoization, same motivation: the same
     * cross-spell reference (very often a spec's own identity-passive spell_id, pointed at by
     * dozens of that spec's other spells' description text) was being re-queried from scratch for
     * every single spell that mentioned it. $patchId of null preserves Pass 1's pre-existing
     * behavior of not filtering by patch at all (a narrower behavior change than adding a filter
     * that wasn't there before was not part of this performance pass).
     */
    private function findSpellBySpellId(int $spellId, ?int $patchId): ?Spell
    {
        $key = $spellId.':'.($patchId ?? 'null');
        if (array_key_exists($key, $this->spellBySpellIdMemo)) {
            return $this->spellBySpellIdMemo[$key];
        }

        $query = Spell::where('spell_id', $spellId);
        if ($patchId !== null) {
            $query->where('patch_id', $patchId);
        }

        return $this->spellBySpellIdMemo[$key] = $query->first();
    }

    /**
     * A bare 0 in both base_value and scaled_value usually means the real number lives in a
     * field we don't capture as a plain point value (an SP/PvP-coefficient-scaled effect, like
     * Mind Blast's — Base Value 0, Scaled Value 0, SP Coefficient 0.78336, and that coefficient
     * is what the game client actually multiplies against the caster's Spell Power to get the
     * tooltip number) — not that the effect is genuinely worth zero. Treated as unresolved
     * rather than a confidently wrong "0", per the "flag, don't guess" rule this whole resolver
     * follows. sp_coefficient is captured (2026-08-02) and used separately by
     * coefficientDisplay() for the bare-token case — this method stays deliberately narrow (a
     * real point value, or nothing) since it's also used by findEffectByIndex() to decide
     * whether an effect is "real data worth preferring."
     */
    private function effectValue(SpellEffect $effect): ?float
    {
        $value = $effect->scaled_value ?? $effect->base_value;

        return ((float) $value !== 0.0) ? (float) $value : null;
    }

    /**
     * The coefficient-based fallback for a bare "sN" token (Pass 3 of resolveDescription() only
     * — deliberately not wired into Pass 2's `${...}` arithmetic evaluator, where blending an
     * estimated percentage into compound math with talent-conditional multipliers risks the
     * exact "confidently wrong" failure this resolver otherwise avoids; Penance-shaped formulas
     * stay unresolved and instead surface their real modifying talent names via
     * variablesModifiers() below). Routes through the same findEffectByIndex() sibling fallback
     * as effectValue(), so Mind Blast's own coefficient (found directly) and any future
     * pointer/sibling-split case both work the same way.
     *
     * @return ?string e.g. "≈78.3% of Spell Power" — null when no coefficient exists either.
     */
    private function coefficientDisplay(Spell $spell, int $index): ?string
    {
        $effect = $this->findEffectByIndex($spell, $index);

        if (!$effect || $effect->sp_coefficient === null) {
            return null;
        }

        return '≈'.$this->formatNumber($effect->sp_coefficient * 100).'% of Spell Power';
    }

    /** Trims to a whole number when exact, else one decimal place — matches how these tooltip
     *  values actually read in-game (percentages/seconds are rarely shown with more precision). */
    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    /**
     * Evaluates a numeric expression (already validated to contain only digits, dots, the four
     * arithmetic operators, parens, and whitespace — no letters, no `$` — before this is ever
     * called) via a small recursive-descent parser. Deliberately not eval() — this only ever
     * needs to handle the small set of simple arithmetic shapes SimC's tooltip syntax actually
     * produces (e.g. "-20000/-1000", "(5+3)/1000"), not a general expression language.
     */
    private function safeEval(string $expr): ?float
    {
        $expr = trim($expr);

        if ($expr === '' || !preg_match('/^[\d.\s+\-*\/()]+$/', $expr)) {
            return null;
        }

        $tokens = preg_split('/\s*([+\-*\/()])\s*/', $expr, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_map('trim', $tokens ?: []));
        $pos = 0;

        // Deliberately a regular closure with use (&$pos), not an arrow function (fn () => ...)
        // — arrow functions in PHP always capture used variables BY VALUE at the moment the
        // closure is created, with no way to opt into by-reference capture. An earlier version
        // used `fn () => $tokens[$pos] ?? null;` here, which silently froze $pos at 0 forever:
        // every peek() call kept returning $tokens[0] regardless of how many times next() (which
        // DOES capture by reference) had actually advanced the real $pos. The practical effect
        // was that every multi-term expression's while-loop condition below (`in_array(peek(),
        // ['*','/'])` etc.) was checked against the FIRST token forever, so it only ever
        // "looped" when the first token itself happened to be an operator — never true for an
        // expression starting with a number — silently truncating evaluation to just the first
        // factor. Confirmed via safeEval('800/1000') returning 800.0 instead of 0.8 (found
        // investigating a real report: Breath of Sindragosa's "${$s3/1000}.1 sec" rendering as
        // "800.1 sec"). This bug affected every ${...} expression with more than one term
        // dataset-wide, not just this one spell.
        $peek = function () use (&$tokens, &$pos) {
            return $tokens[$pos] ?? null;
        };
        $next = function () use (&$tokens, &$pos) {
            return $tokens[$pos++] ?? null;
        };

        // Hard circuit breaker: these SimC expressions are only ever a couple of terms long
        // (confirmed against every real case in this dataset), so a call count this low can
        // never legitimately be reached — this guarantees termination regardless of whatever
        // exact input shape triggers runaway recursion, rather than relying on having traced
        // every edge case in a hand-rolled parser processing text we don't fully control.
        $calls = 0;
        $tooManyCalls = function () use (&$calls): bool {
            return ++$calls > 200;
        };

        // Recursive-descent closures need to reference each other before all are assigned;
        // bind $parseExpr/$parseFactor by reference and assign $parseFactor last, since it's
        // the one that recurses back into $parseExpr for parenthesized sub-expressions.
        $parseExpr = null;

        $parseFactor = function () use (&$parseExpr, &$parseFactor, $peek, $next, $tooManyCalls): ?float {
            if ($tooManyCalls()) {
                return null;
            }

            $tok = $peek();

            if ($tok === '-') {
                $next();
                $val = $parseFactor();

                return $val === null ? null : -$val;
            }

            if ($tok === '(') {
                $next();
                $val = $parseExpr();
                if ($peek() === ')') {
                    $next();
                }

                return $val;
            }

            if ($tok === null || !is_numeric($tok)) {
                return null;
            }
            $next();

            return (float) $tok;
        };

        $parseTerm = function () use (&$parseFactor, $peek, $next): ?float {
            $value = $parseFactor();
            while ($value !== null && in_array($peek(), ['*', '/'], true)) {
                $op = $next();
                $rhs = $parseFactor();
                if ($rhs === null) {
                    return null;
                }
                $value = $op === '*' ? $value * $rhs : ($rhs != 0 ? $value / $rhs : null);
            }

            return $value;
        };

        $parseExpr = function () use (&$parseTerm, $peek, $next): ?float {
            $value = $parseTerm();
            while ($value !== null && in_array($peek(), ['+', '-'], true)) {
                $op = $next();
                $rhs = $parseTerm();
                if ($rhs === null) {
                    return null;
                }
                $value = $op === '+' ? $value + $rhs : $value - $rhs;
            }

            return $value;
        };

        try {
            return $parseExpr();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, int> talent_tree ids relevant to this build (class, spec, hero). */
    private function buildTreeIds(ModuleGameBuild $build): array
    {
        return $this->buildTreeIdsFor($build->class_id, $build->specialization_id, $build->hero_talent_tree_id);
    }

    /**
     * @return array<int, int> talent_tree ids relevant to the given class/spec/hero-tree.
     *
     * The spec/hero-tree OR-branches are only added when a real id is given — talent_trees.
     * spec_id is nullable and shared by hero trees (tracked separately via the
     * talent_tree_specializations pivot, not this column), so an unconditional
     * `orWhere('spec_id', null)` would silently match every hero tree in the whole class when
     * specId is null (the opponent-context case) rather than correctly matching nothing extra.
     */
    private function buildTreeIdsFor(?int $classId, ?int $specId, ?int $heroTreeId): array
    {
        if ($classId === null) {
            return [];
        }

        $key = $classId.':'.$specId.':'.$heroTreeId;
        if (array_key_exists($key, $this->treeIdsMemo)) {
            return $this->treeIdsMemo[$key];
        }

        return $this->treeIdsMemo[$key] = TalentTree::where(function ($q) use ($classId, $specId, $heroTreeId) {
            $q->where(fn ($q2) => $q2->where('class_id', $classId)->where('type', 'class'));

            if ($specId !== null) {
                $q->orWhere('spec_id', $specId);
            }

            if ($heroTreeId !== null) {
                $q->orWhere('id', $heroTreeId);
            }
        })->pluck('id')->all();
    }

    /**
     * A spell counts as a generic baseline aura when its own name literally *is* the class
     * name or "{Spec} {Class}" (e.g. "Priest", "Discipline Priest") — the recognizable, always-
     * on passive-aura naming convention confirmed repeatedly across this dataset. Name-based
     * rather than attribute-based (e.g. "Hidden") because plenty of real talents are also
     * Hidden/Do Not Display — the name pattern is the one reliable signal specific to this case.
     */
    private function genericBaselineAuraCheckerFor(?int $classId, ?int $specId): \Closure
    {
        $key = $classId.':'.$specId;
        if (array_key_exists($key, $this->baselineCheckerMemo)) {
            return $this->baselineCheckerMemo[$key];
        }

        $className = $classId ? GameClass::find($classId)?->name : null;
        $specName = $specId ? Specialization::find($specId)?->name : null;

        return $this->baselineCheckerMemo[$key] = function (Spell $s) use ($className, $specName) {
            if ($className !== null && $s->name === $className) {
                return true;
            }

            return $className !== null && $specName !== null && $s->name === "{$specName} {$className}";
        };
    }
}
