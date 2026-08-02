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
        $inOwnClass = $spell->classAvailability()->where('class_id', $build->class_id)->exists();

        if ($inOwnClass) {
            return [
                'class_id' => $build->class_id,
                'spec_id' => $build->specialization_id,
                'hero_tree_id' => $build->hero_talent_tree_id,
            ];
        }

        return [
            'class_id' => $spell->classAvailability()->value('class_id'),
            'spec_id' => null,
            'hero_tree_id' => null,
        ];
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

        return $availabilityIds->merge($heroTreeIds)->unique()->values();
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
     * @return array{named: Collection, baseline: Collection}
     */
    public function modifiersFor(Spell $spell, ModuleGameBuild $build, ?Collection $selectedSpellIds = null): array
    {
        $selectedSpellIds ??= collect();
        $context = $this->resolveKitContext($spell, $build);
        $kitIds = $this->buildKitSpellIdsFor($context['class_id'], $context['spec_id'], $context['hero_tree_id']);
        $isBaseline = $this->genericBaselineAuraCheckerFor($context['class_id'], $context['spec_id']);
        $treeIds = $this->buildTreeIdsFor($context['class_id'], $context['spec_id'], $context['hero_tree_id']);

        $named = collect();
        $baseline = collect();
        $seenIds = collect([$spell->id]);

        $classify = function (Spell $candidate, string $relationshipType, ?SpellRelationship $rel = null) use (
            &$named, &$baseline, $isBaseline, $context, $treeIds, $selectedSpellIds
        ) {
            if ($this->isKnownJunk($candidate)) {
                return;
            }

            $entry = [
                'spell' => $candidate,
                'relationship_type' => $relationshipType,
                'modifier_value' => $rel?->modifier_value,
                'modifier_unit' => $rel?->modifier_unit,
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
            'named' => $named->values(),
            'baseline' => $baseline->values(),
        ];
    }

    /**
     * Computes $spell's effective cooldown given which talents are actually selected —
     * $spell->cooldown_seconds (the base value) with every selected, magnitude-bearing
     * 'modifies_cooldown' modifier applied: flat seconds first, then percent (the layering order
     * validated by hand against a real in-game report in game-data.md's Mind Blast worked
     * example). Modifiers without a computable magnitude (modifier_value/modifier_unit null —
     * see SpellRelationship's docblock) still show up in modifiersFor()'s 'named' list
     * descriptively, they just don't change this number — never guessed.
     *
     * @return array{seconds: ?float, base_seconds: ?float, applied: Collection}
     */
    public function effectiveCooldown(Spell $spell, ModuleGameBuild $build, Collection $selectedSpellIds): array
    {
        $base = $spell->cooldown_seconds !== null ? (float) $spell->cooldown_seconds : null;
        $result = $this->effectiveScalarValue($spell, $build, $selectedSpellIds, $base, 'modifies_cooldown', 'seconds');

        return ['seconds' => $result['value'], 'base_seconds' => $result['base'], 'applied' => $result['applied']];
    }

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
     * @return string One of: 'Crowd Control', 'Defensive', 'Utility', 'Offensive', 'Other'
     */
    public function categorize(Spell $spell): string
    {
        $types = $spell->effects->pluck('type')->implode(' | ');

        return match (true) {
            (bool) preg_match('/Stun|Fear|Root|Silence|Incapacitate|Disorient|Charm|Polymorph|Freeze|Sleep|Horror/i', $types) => 'Crowd Control',
            (bool) preg_match('/Damage Taken%|Absorb|Immunity|Block%|Parry%|Dodge%|Damage Reduction/i', $types) => 'Defensive',
            (bool) preg_match('/Dispel|Increase Speed%|Threat Reduction|Aggro/i', $types) => 'Utility',
            (bool) preg_match('/School Damage|Damage Done%|Energize/i', $types) => 'Offensive',
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
    public function effectiveCharges(Spell $spell, ModuleGameBuild $build, Collection $selectedSpellIds): array
    {
        $base = $spell->charges !== null ? (float) $spell->charges : null;
        $result = $this->effectiveScalarValue($spell, $build, $selectedSpellIds, $base, 'modifies_charges', 'charges');

        return [
            'charges' => $result['value'] !== null ? (int) round($result['value']) : null,
            'base_charges' => $spell->charges,
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
        string $flatUnit
    ): array {
        if ($base === null || $selectedSpellIds->isEmpty()) {
            return ['value' => $base, 'base' => $base, 'applied' => collect()];
        }

        $modifiers = $this->modifiersFor($spell, $build, $selectedSpellIds);

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

        $isTalentPick = $spell->talentNodeEntries()
            ->whereHas('talentNode', fn ($q) => $q->whereIn('talent_tree_id', $treeIds))
            ->exists();

        if ($isTalentPick) {
            return true;
        }

        return $spell->classAvailability()
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
            Log::warning('ModuleSpellReferenceService: truncated an unterminated trailing conditional', [
                'spell_id' => $spell->spell_id,
            ]);
            $text = rtrim($truncated);
        }

        // Pass 1: conditional branches. $?a<id>/$?s<id> resolved via this spell's own kit
        // context membership; $?c<n> codes are flagged rather than guessed.
        $text = preg_replace_callback(
            '/\$\?([acs])(\d+)\[([^\[\]]*)\]\[([^\[\]]*)\]/',
            function ($m) use (&$uncertain, $kitIds, $spell) {
                [, $letter, $id, $branchA, $branchB] = $m;

                if ($letter === 'c') {
                    $uncertain = true;
                    Log::warning('ModuleSpellReferenceService: unresolvable $?c condition code', [
                        'spell_id' => $spell->spell_id, 'code' => $id,
                    ]);

                    return '(varies by condition — check in-game)';
                }

                $other = Spell::where('spell_id', (int) $id)->first();

                return ($other && $kitIds->contains($other->id)) ? $branchA : $branchB;
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

                if ($value === null) {
                    $uncertain = true;
                    Log::warning('ModuleSpellReferenceService: unresolved description token', [
                        'spell_id' => $spell->spell_id, 'token' => $m[0],
                    ]);

                    return '(varies)';
                }

                return $this->formatNumber($value);
            },
            $text
        );

        return ['text' => $text, 'uncertain' => $uncertain];
    }

    /**
     * "s1"/"s2"/... -> this spell's own effect N (scaled_value, falling back to base_value);
     * "d" -> its own duration; "<id>s1"/"<id>d" -> the same, but on another spell entirely (e.g.
     * Angelic Bulwark's "$114214d"). Any other suffix (t/w/m/A/u/...) returns null — genuinely
     * uncertain rather than guessed, since we don't have confirmed semantics for those.
     */
    private function resolveValueToken(string $token, Spell $spell): ?float
    {
        if (preg_match('/^s(\d+)$/', $token, $m)) {
            $effect = $spell->effects->firstWhere('effect_index', (int) $m[1]);

            return $effect ? $this->effectValue($effect) : null;
        }

        if ($token === 'd') {
            return $spell->duration_seconds !== null ? (float) $spell->duration_seconds : null;
        }

        if (preg_match('/^(\d+)(s(\d+)|d)$/', $token, $m)) {
            $other = Spell::where('spell_id', (int) $m[1])->where('patch_id', $spell->patch_id)->first();

            if (!$other) {
                return null;
            }

            if ($m[2] === 'd') {
                return $other->duration_seconds !== null ? (float) $other->duration_seconds : null;
            }

            $effect = $other->effects->firstWhere('effect_index', (int) $m[3]);

            return $effect ? $this->effectValue($effect) : null;
        }

        return null;
    }

    /**
     * A bare 0 in both base_value and scaled_value usually means the real number lives in a
     * field we don't capture (e.g. "SP Coefficient" on spell-power-scaled damage effects, like
     * Mind Blast's — confirmed: Base Value 0, Scaled Value 0, SP Coefficient 0.78336, and that
     * coefficient is what the game client actually multiplies to get the tooltip number) — not
     * that the effect is genuinely worth zero. Treated as unresolved rather than a confidently
     * wrong "0", per the "flag, don't guess" rule this whole resolver follows.
     */
    private function effectValue(SpellEffect $effect): ?float
    {
        $value = $effect->scaled_value ?? $effect->base_value;

        return ((float) $value !== 0.0) ? (float) $value : null;
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

        $peek = fn () => $tokens[$pos] ?? null;
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

        return TalentTree::where(function ($q) use ($classId, $specId, $heroTreeId) {
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
        $className = $classId ? GameClass::find($classId)?->name : null;
        $specName = $specId ? Specialization::find($specId)?->name : null;

        return function (Spell $s) use ($className, $specName) {
            if ($className !== null && $s->name === $className) {
                return true;
            }

            return $className !== null && $specName !== null && $s->name === "{$specName} {$className}";
        };
    }
}
