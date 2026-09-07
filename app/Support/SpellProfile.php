<?php

namespace App\Support;

use App\Models\Spell;
use App\Models\SpellCounter;
use ArrayAccess;
use Illuminate\Support\Collection;

/**
 * THE spell object. One definition of "everything we know about a spell", replacing the four
 * competing ones this codebase had grown by 2026-09-06:
 *
 *   1. Spell (the Eloquent model)           — every raw/curated column, but nothing derived
 *   2. SpecKitComputer::compute()'s entry    — rich, but an untyped array, and missing CC immunity
 *   3. SpellDetailModal::getEntryProperty()  — a SECOND rich untyped array that had already
 *                                              diverged from #2 in both directions
 *   4. whatever each of 13 blade templates rebuilt locally
 *
 * The split that actually matters, and that this class makes explicit in its own structure:
 *
 *   BUILD-INDEPENDENT facts (school, dr_category, duration, mechanic, category, the immunity
 *   fields, counters, base cooldown, the is_* flags) are true for every viewer forever. They are
 *   read straight off already-materialized columns — never recomputed per request. This is the
 *   same principle ImportSpellData::materializeSpellShape() established for spells.category on
 *   2026-09-03; before this class existed that column was written at import and then ignored by
 *   every display path, all of which still called categorize() live on every render.
 *
 *   BUILD-DEPENDENT facts (talent-modified cooldown/charges, the resolved description text,
 *   which modifiers are actually active, whether the viewer has this talent selected) genuinely
 *   cannot be answered without a TalentBuild. They stay computed by ModuleSpellReferenceService
 *   exactly as before, and are simply absent — not faked, not zeroed — on a profile built with no
 *   spec context. hasBuildContext() is how a caller tells the two apart.
 *
 * ModuleSpellReferenceService and TalentSelectionService are untouched by this: they remain the
 * engine that computes the build-dependent half. This is a façade over their output, not a
 * replacement for it. Constructed only by SpellProfileBuilder.
 *
 * ArrayAccess is a deliberate compatibility bridge, not an invitation. SpecKitComputer's entry
 * arrays are consumed by name ($entry['spell'], $entry['cooldown'], ...) across 13 blade
 * templates and are serialized into data/spell-kits/{class}/{spec}.json; implementing ArrayAccess
 * is what lets this consolidation land without rewriting every one of those templates in the
 * same change. New code should use the typed properties and accessors.
 */
final class SpellProfile implements ArrayAccess
{
    /**
     * @param  Collection<int, string>  $grantsCcImmunity  real mechanic names ("Stun", "Fear", ...)
     * @param  ?Collection<int, SpellCounter>  $counteredBy  null = not loaded (bulk kit builds skip
     *                                                       it); empty = loaded and genuinely none exist
     * @param  ?Collection<int, \App\Models\SpellClassAvailability>  $availability  null = not loaded
     * @param  ?array  $description  build-dependent; null when there is no spec context
     * @param  ?array{named: Collection, baseline: Collection, potential: Collection}  $modifiers
     */
    public function __construct(
        public readonly Spell $spell,
        public readonly string $category,
        public readonly Collection $grantsCcImmunity,
        public readonly ?string $grantsSchoolImmunity = null,
        public readonly ?Collection $counteredBy = null,
        public readonly ?Collection $availability = null,
        public readonly ?array $description = null,
        public readonly ?Collection $formulaModifiers = null,
        public readonly ?array $modifiers = null,
        public readonly ?array $cooldown = null,
        public readonly ?array $charges = null,
        public readonly ?bool $isSelected = null,
        public readonly ?string $source = null,
        public readonly ?bool $isPriority = null,
        public readonly ?array $offensiveDefensive = null,
        /**
         * The dr_category that applies to THIS viewer's build, once Spell::$conditional_dr_category
         * has been resolved against their real talent selections (see
         * SpellProfileBuilder::resolveDrCategory()). Null means "not resolved"  14 drCategory()
         * below then falls back to the spell's own base column, which is the honest answer for a
         * profile built with no spec context.
         */
        public readonly ?string $resolvedDrCategory = null,
        /**
         * What the resolved build actually selects, BEFORE any of the viewer's own on/off
         * experimentation. Null on a profile built without a spec context. Kept alongside the
         * effective selection so talentToggles() below can say which rows have been flipped away
         * from the default, which is the difference between "this build takes it" and "you turned
         * it on to see what it would do".
         */
        public readonly ?Collection $buildSelectedSpellIds = null,
        /** @var array<int, bool> keyed by selection_spell_id; only explicitly-flipped rows. */
        public readonly array $talentOverrides = [],
    ) {}

    /** True once the build-dependent half has actually been resolved against a real spec. */
    public function hasBuildContext(): bool
    {
        return $this->description !== null;
    }

    public function hasPotentialImprovement(): bool
    {
        $potential = $this->modifiers['potential'] ?? null;

        return $potential instanceof Collection && $potential->isNotEmpty();
    }

    // --- build-independent shape, read straight off the materialized row ---------------------

    public function displayName(): string
    {
        return $this->spell->display_name;
    }

    /**
     * The DR category to SHOW. Almost always just the spell's own curated column  14 but a
     * handful of spells genuinely flip type on one talent (Holy Word: Chastise incapacitates,
     * and stuns once Censure is talented), and for those the answer depends on the build being
     * rendered. Resolving that is SpellProfileBuilder's job; this just prefers its answer when
     * one exists.
     *
     * Display only. The base spells.dr_category column is never overwritten, because every
     * build-INDEPENDENT consumer is right to keep reading it: a real combat log already records
     * whichever variant actually landed, under its own distinct aura spell_id.
     */
    public function drCategory(): ?string
    {
        return $this->resolvedDrCategory ?? $this->spell->dr_category;
    }

    public function school(): ?string
    {
        return $this->spell->school;
    }

    /**
     * The duration that actually matters in arena, when one is known. pvp_duration_seconds is
     * hand-verified per spell (see CLAUDE.md's "PvP CC duration cap" work) and is NOT derivable
     * from duration_seconds — Polymorph's PvE tooltip reads 60s against a real 6s in PvP, and
     * Kidney Shot's stored 3.00 is a low-combo-point value against a real 6s. Never compute one
     * from the other; show whichever is genuinely known and label it for what it is, which is
     * what durationIsPvpVerified() exists for.
     */
    public function effectiveDurationSeconds(): ?float
    {
        if ($this->spell->pvp_duration_seconds !== null) {
            return (float) $this->spell->pvp_duration_seconds;
        }

        return $this->spell->duration_seconds !== null ? (float) $this->spell->duration_seconds : null;
    }

    public function durationIsPvpVerified(): bool
    {
        return $this->spell->pvp_duration_seconds !== null;
    }

    /**
     * The short "what kind of thing is this" labels, assembled once — the answer to "show me
     * that it's a Stun AND that it's Crowd Control" in one place, rather than every page
     * building its own badge row out of a different subset of these columns (which is how
     * /wow-comps, /cc-chains, /burst-guides and the detail modal each ended up with their own
     * partial view of the same spell).
     *
     * @return array<int, array{label: string, tone: string}>
     */
    public function traits(): array
    {
        $traits = [['label' => $this->category, 'tone' => 'category']];

        if ($this->drCategory() !== null) {
            $traits[] = ['label' => $this->drCategory(), 'tone' => 'dr'];
        }
        if ($this->spell->is_interrupt) {
            $traits[] = ['label' => 'Interrupt', 'tone' => 'plain'];
        }
        if ($this->spell->is_peel) {
            $traits[] = ['label' => 'Peel', 'tone' => 'plain'];
        }
        if ($this->spell->is_mobility) {
            $traits[] = ['label' => 'Mobility', 'tone' => 'plain'];
        }
        if ($this->spell->is_passive) {
            $traits[] = ['label' => 'Passive', 'tone' => 'muted'];
        }
        if ($this->spell->requires_stealth) {
            $traits[] = ['label' => 'Requires stealth', 'tone' => 'muted'];
        }

        return $traits;
    }

    /**
     * The talents that affect this spell, as one ordered list of on/off rows — the two separate
     * "Modifies / Enhances" (active) and "Could Be Improved By" (not taken) lists merged, since
     * they were only ever two views of the same question and a viewer flicking a talent on and
     * off would otherwise watch rows jump between two sections.
     *
     * Active rows come first, then alphabetically, so the list doesn't reorder under the cursor
     * as things are toggled.
     *
     * `selectionSpellId` is what a toggle must flip — see modifiersFor()'s own note on why that
     * is not always the row's own spell id. `isOverridden` marks a row the viewer has moved away
     * from the build's real answer, which is what the reset affordance keys off.
     *
     * Baseline modifiers are deliberately absent: they are generic always-on class passives, not
     * talents, so there is nothing to toggle.
     *
     * @return array<int, array{spell: \App\Models\Spell, selectionSpellId: int, isActive: bool, isOverridden: bool, category: string, description: array, cooldown: ?array, modifierValue: ?float, modifierUnit: ?string}>
     */
    public function talentToggles(): array
    {
        if ($this->modifiers === null) {
            return [];
        }

        $rows = collect($this->modifiers['named'] ?? [])
            ->map(fn (array $m) => $this->toggleRow($m, true))
            ->merge(collect($this->modifiers['potential'] ?? [])->map(fn (array $m) => $this->toggleRow($m, false)))
            ->filter(fn (?array $row) => $row !== null)
            // A modifier can legitimately appear more than once (one source, several distinct
            // relationship types to the same target — see modifiersFor()'s 2026-08-02 fix); it is
            // still ONE talent, so it gets one switch.
            ->unique('selectionSpellId')
            ->sortBy([
                fn (array $a, array $b) => ($b['isActive'] <=> $a['isActive']),
                fn (array $a, array $b) => strcmp($a['spell']->display_name, $b['spell']->display_name),
            ])
            ->values();

        return $rows->all();
    }

    /** @param array<string, mixed> $mod */
    private function toggleRow(array $mod, bool $isActive): ?array
    {
        $selectionSpellId = $mod['selection_spell_id'] ?? null;

        // No selection id means modifiersFor() never ran its selection gate on this entry, so
        // there is nothing a toggle could flip. Dropped rather than rendered as a dead switch.
        if ($selectionSpellId === null) {
            return null;
        }

        return [
            'spell' => $mod['spell'],
            'selectionSpellId' => (int) $selectionSpellId,
            'isActive' => $isActive,
            'isOverridden' => array_key_exists((int) $selectionSpellId, $this->talentOverrides),
            'category' => $mod['category'] ?? 'Other',
            'description' => $mod['description'] ?? ['text' => '', 'uncertain' => false],
            'cooldown' => $mod['cooldown'] ?? null,
            'modifierValue' => $mod['modifier_value'] ?? null,
            'modifierUnit' => $mod['modifier_unit'] ?? null,
        ];
    }

    /** True once the viewer has moved any talent away from what the build actually selects. */
    public function hasTalentOverrides(): bool
    {
        return $this->talentOverrides !== [];
    }

    /** @return Collection<string, Collection<int, SpellCounter>> empty when counters weren't loaded */
    public function countersByMechanism(): Collection
    {
        return ($this->counteredBy ?? collect())->groupBy('mechanism');
    }

    // --- ArrayAccess bridge (see class docblock) ----------------------------------------------

    /** The exact key set SpecKitComputer's entry arrays exposed, so existing blades keep working. */
    private function bridge(string $key): mixed
    {
        return match ($key) {
            'spell' => $this->spell,
            'category' => $this->category,
            'grantsCcImmunity' => $this->grantsCcImmunity,
            'description' => $this->description,
            'formulaModifiers' => $this->formulaModifiers ?? collect(),
            'modifiers' => $this->modifiers ?? ['named' => collect(), 'baseline' => collect(), 'potential' => collect()],
            'hasPotentialImprovement' => $this->hasPotentialImprovement(),
            'cooldown' => $this->cooldown,
            'charges' => $this->charges,
            'isSelected' => $this->isSelected,
            'source' => $this->source,
            'isPriority' => $this->isPriority,
            'offensiveDefensive' => $this->offensiveDefensive,
            'drCategory' => $this->drCategory(),
            default => null,
        };
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->bridge((string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->bridge((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('SpellProfile is readonly — construct a new one via SpellProfileBuilder.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('SpellProfile is readonly — construct a new one via SpellProfileBuilder.');
    }
}
