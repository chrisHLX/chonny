<?php

namespace App\Http\Services;

use App\Models\ModuleGameBuild;
use App\Models\Spell;
use App\Support\SpellProfile;
use Illuminate\Support\Collection;

/**
 * The only place a SpellProfile is constructed. Two entry points, matching the two genuinely
 * different shapes of demand this codebase has:
 *
 *   forDetail()   — "the user clicked one spell and wants to know everything about it."
 *                   Loads counters and class/spec availability (the two things that only ever
 *                   matter when looking at a single spell in isolation) and, when a spec context
 *                   is supplied, resolves the build-dependent half too. Used by
 *                   SpellDetailModal and the /spell/{id} page, which previously each had their
 *                   own hand-assembled version of this.
 *
 *   forKitEntry() — "we are rendering a whole spec's kit and have already done the bulk
 *                   preloading." Takes the already-resolved build-dependent pieces rather than
 *                   recomputing them, so SpecKitComputer keeps full ownership of its own
 *                   batching (preloadBaseCooldownCharges / preloadCategorize /
 *                   preloadPrioritySpells) — this builder owns the SHAPE, not the efficiency
 *                   strategy. Deliberately does NOT load counters/availability: neither is
 *                   rendered in a kit grid, and loading them per entry would be ~250 extra
 *                   queries per spec for data nothing displays.
 *
 * Both routes converge on the same SpellProfile, which is the entire point — before this, the
 * per-spell shape rendered by /wow-comps and the one rendered by the detail modal had already
 * drifted apart in both directions (the kit had isPriority/offensiveDefensive/source and no CC
 * immunity; the modal had CC immunity and none of the other three).
 */
class SpellProfileBuilder
{
    public function __construct(
        private readonly ModuleSpellReferenceService $service,
        private readonly TalentSelectionService $talentService,
    ) {}

    /**
     * Full profile for one spell, for a detail view.
     *
     * $classId/$specId are optional and independent of each other in practice: with a spec, the
     * talent-modified cooldown/charges and the resolved description are computed against that
     * spec's active build (the viewer's own if they have one, else the admin default — the same
     * resolveActiveBuild() chain every other page uses). With no spec, the build-dependent half
     * is still computed but against an empty selection, which is what "base, unmodified values"
     * means — never a guess at which build to use. This mirrors what SpellDetailModal already
     * did correctly and is preserved deliberately.
     */
    public function forDetail(Spell $spell, ?int $classId = null, ?int $specId = null, array $talentOverrides = []): SpellProfile
    {
        $spell->loadMissing(['effects', 'incomingRelationships.sourceSpell.effects']);

        $selected = new Collection;
        $ranks = new Collection;

        if ($specId !== null) {
            $build = $this->talentService->resolveActiveBuild(auth()->user(), $specId);
            if ($build->exists) {
                $selected = $this->talentService->selectedSpellIds($build);
                $ranks = $this->talentService->selectedRanks($build);
            }
        }

        // What the resolved build actually has, before the viewer's own experimentation — kept so
        // the view can mark which rows have been flipped away from it and offer a reset.
        $buildSelected = $selected;
        $selected = $this->applyTalentOverrides($selected, $talentOverrides);

        $gameBuild = new ModuleGameBuild([
            'class_id' => $classId,
            'specialization_id' => $specId,
        ]);

        $description = $this->service->resolveDescription($spell, $gameBuild);
        $modifiers = $this->service->modifiersFor($spell, $gameBuild, $selected, $ranks);

        return new SpellProfile(
            spell: $spell,
            category: $this->category($spell),
            grantsCcImmunity: $this->ccImmunity($spell),
            grantsSchoolImmunity: $spell->grants_school_immunity,
            counteredBy: $spell->counteredBy()->with('counterSpell')->get(),
            availability: $spell->classAvailability()->with(['gameClass', 'specialization'])->get(),
            description: $description,
            formulaModifiers: $description['uncertain'] ? $this->service->variablesModifiers($spell) : new Collection,
            modifiers: [
                'named' => $this->enrichModifiers($modifiers['named'], $gameBuild, $selected, $ranks),
                'baseline' => $modifiers['baseline'],
                // "Could be improved by..." — real, structurally-confirmed modifiers whose talent
                // isn't currently selected. Only meaningful once a spec context exists: with no
                // specId there is no resolved build for something to be "not selected" in, so
                // 'potential' and 'named' would be indistinguishable. That case is handled by the
                // view simply not rendering the section, not by hiding it here.
                'potential' => $this->enrichModifiers($modifiers['potential'], $gameBuild, $selected, $ranks),
            ],
            cooldown: $this->service->effectiveCooldown($spell, $gameBuild, $selected, $ranks),
            charges: $this->service->effectiveCharges($spell, $gameBuild, $selected, $ranks),
            resolvedDrCategory: $this->resolveDrCategory($spell, $selected),
            buildSelectedSpellIds: $buildSelected,
            talentOverrides: $talentOverrides,
        );
    }

    /**
     * Applies a viewer's own on/off experimentation on top of the build's real selections.
     *
     * $talentOverrides is keyed by the id modifiersFor() actually gates on
     * (`$entry['selection_spell_id']`, which is a sibling id in the cases findConfidentSibling()
     * exists for — NOT necessarily the modifier's own spell id), mapping to true (force on) or
     * false (force off). Only ids the viewer explicitly flipped appear, so an empty array is
     * exactly "show me the build as it is" and nothing about the default path changes.
     *
     * Ranks are deliberately NOT synthesized for a toggled-ON talent. resolveRankAwareMagnitude()
     * already has a documented fallback for "selected, but no rank on file" — assume the highest
     * available rank — which is the honest reading of "what would this talent do for me", and
     * inventing a rank here would just duplicate that decision in a second place.
     *
     * @param  Collection<int, int>  $selected
     * @param  array<int, bool>  $talentOverrides
     * @return Collection<int, int>
     */
    private function applyTalentOverrides(Collection $selected, array $talentOverrides): Collection
    {
        if ($talentOverrides === []) {
            return $selected;
        }

        $off = array_keys(array_filter($talentOverrides, fn ($on) => $on === false));
        $on = array_keys(array_filter($talentOverrides, fn ($isOn) => $isOn === true));

        return $selected
            ->reject(fn ($id) => in_array((int) $id, array_map('intval', $off), true))
            ->merge(array_map('intval', $on))
            ->unique()
            ->values();
    }

    /**
     * Profile for one entry of an already-batched spec kit. Every build-dependent value is passed
     * in already-resolved by the caller — this method computes nothing expensive, it only decides
     * the shape.
     *
     * @param  array{named: Collection, baseline: Collection, potential: Collection}  $modifiers
     */
    public function forKitEntry(
        Spell $spell,
        string $category,
        array $description,
        Collection $formulaModifiers,
        array $modifiers,
        array $cooldown,
        array $charges,
        bool $isSelected,
        string $source,
        bool $isPriority,
        ?array $offensiveDefensive,
        ?string $resolvedDrCategory = null,
    ): SpellProfile {
        return new SpellProfile(
            spell: $spell,
            category: $category,
            grantsCcImmunity: $this->ccImmunity($spell),
            grantsSchoolImmunity: $spell->grants_school_immunity,
            description: $description,
            formulaModifiers: $formulaModifiers,
            modifiers: $modifiers,
            cooldown: $cooldown,
            charges: $charges,
            isSelected: $isSelected,
            source: $source,
            isPriority: $isPriority,
            offensiveDefensive: $offensiveDefensive,
            resolvedDrCategory: $resolvedDrCategory,
        );
    }

    /**
     * Which dr_category actually applies to the build being rendered.
     *
     * Nearly every spell has one flat, unconditional answer. A small hand-curated set genuinely
     * flips CC TYPE on a single talent, and for those the flat column is wrong for roughly half
     * of all viewers. The motivating case (2026-09-06): Holy Word: Chastise incapacitates by
     * default and STUNS once Censure is talented -- Blizzard states the flip in the spell's own
     * description text -- and Censure is selected in the Holy Priest admin-default build the site
     * renders, so the flat Incapacitate tag was wrong for essentially everyone looking at it.
     *
     * Both curated columns are set together or not at all (enforced at import), so a single null
     * check on the gating id is enough. $selectedSpellIds holds INTERNAL Spell ids -- the same
     * set TalentSelectionService::selectedSpellIds() returns -- while the curated gating column
     * holds Blizzard's EXTERNAL spell_id, so the gate is resolved through one patch-scoped lookup
     * rather than compared across two different id spaces (a mistake this codebase has made
     * before; see fetch-spell-icons.php's spells.id/spells.spell_id trace in CLAUDE.md).
     *
     * Returns null when nothing conditional applies, which SpellProfile::drCategory() reads as
     * "use the base column" -- never a guess, and never a write.
     *
     * @param  Collection<int, int>  $selectedSpellIds  internal Spell ids selected in the active build
     */
    public function resolveDrCategory(Spell $spell, Collection $selectedSpellIds): ?string
    {
        if ($spell->conditional_dr_gating_spell_id === null || $selectedSpellIds->isEmpty()) {
            return null;
        }

        $gatingId = Spell::where('patch_id', $spell->patch_id)
            ->where('spell_id', $spell->conditional_dr_gating_spell_id)
            ->value('id');

        if ($gatingId === null || ! $selectedSpellIds->contains($gatingId)) {
            return null;
        }

        return $spell->conditional_dr_category;
    }

    /**
     * Reads the materialized spells.category column, falling back to a live categorize() only
     * when it is genuinely absent.
     *
     * The fallback is NOT redundant caution: the column is written by
     * ImportSpellData::materializeSpellShape(), so it is null for any spell created outside a
     * real import — every test fixture in the suite, and any row added between a schema change
     * and the next import. Reading the column first is the actual fix here; before this, the
     * column was written at import and then ignored by every single display path, all of which
     * called categorize() live and therefore needed effects eager-loaded and a preload pass just
     * to answer a question already answered on disk.
     */
    public function category(Spell $spell): string
    {
        return $spell->category ?? $this->service->categorize($spell);
    }

    /**
     * Same read-the-column-first rule for CC immunity. Distinguishes "not yet materialized"
     * (null — fall back to computing it) from "materialized, grants nothing" (an empty array),
     * which is why the migration made the column nullable rather than defaulting it to '[]'.
     *
     * @return Collection<int, string>
     */
    public function ccImmunity(Spell $spell): Collection
    {
        if ($spell->grants_cc_immunity !== null) {
            return collect($spell->grants_cc_immunity);
        }

        return $spell->relationLoaded('effects')
            ? $this->service->ccImmunityFor($spell)
            : collect($spell->grants_cc_immunity_override ?? []);
    }

    /**
     * modifiersFor()'s raw output carries only the modifying spell and its magnitude — no
     * description/category/cooldown per modifier. This was previously duplicated byte-for-byte
     * in SpecKitComputer AND SpellDetailModal (the latter's own comment said as much); it now
     * exists once.
     *
     * @param  Collection<int, array>  $modifiers
     * @return Collection<int, array>
     */
    public function enrichModifiers(Collection $modifiers, ModuleGameBuild $build, Collection $selected, Collection $ranks): Collection
    {
        return $modifiers->map(function (array $mod) use ($build, $selected, $ranks) {
            $mod['description'] = $this->service->resolveDescription($mod['spell'], $build);
            $mod['category'] = $this->category($mod['spell']);
            $mod['cooldown'] = $this->service->effectiveCooldown($mod['spell'], $build, $selected, $ranks);

            return $mod;
        });
    }
}
