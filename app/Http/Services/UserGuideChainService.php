<?php

namespace App\Http\Services;

use App\Enums\UserGuideBlockType;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\UserGuideBlock;
use App\Models\UserGuideSection;
use App\Support\CooldownTabs;
use Illuminate\Support\Collection;

/**
 * Turns one ability sequence — a chain, a go, or an opponent's defensives — into renderable data:
 * the palette its author drags from, the resolved and DR-annotated steps, and the numbers that
 * make it worth building.
 *
 * Scoped to a SECTION, not a guide. Text sections never come here (they have no abilities to
 * resolve), and every sequence section is resolved independently — see the DR note on
 * resolve() for why that independence is a correctness requirement rather than a convenience.
 *
 * Lives as a service rather than inside the builder component because the public read view has to
 * render a section identically to the way its author saw it while writing it. Two implementations
 * would drift, and the first symptom would be a guide that validates clean in the editor and shows
 * different numbers to a reader.
 *
 * Everything here reads through machinery that already exists — SpellCounterIndexer's pressable-CC
 * definition, CooldownTabs' offensive/defensive rule, SpecKitComputer's per-spec entry resolution,
 * CcChainBuilder's DR maths. Nothing in this file invents its own idea of what a spell is, what
 * counts as a cooldown, or what diminishes.
 */
class UserGuideChainService
{
    /**
     * Hard categories first, then movement/utility control — matches how the Synergies tab already
     * groups the same vocabulary, so an author sees CC organised the way the rest of the site
     * organises it.
     */
    private const CATEGORY_ORDER = ['Stun', 'Silence', 'Incapacitate', 'Disorient', 'Root', 'Knockback', 'Disarm', 'Slow'];

    private const OFFENSIVE_GROUP = 'Offensive cooldowns';

    private const DEFENSIVE_GROUP = 'Defensive cooldowns';

    public function __construct(
        private SpecKitComputer $kits,
        private ModuleSpellReferenceService $spellReferences,
        private TalentSelectionService $talents,
        private SpellCounterIndexer $counters,
        private CcChainBuilder $chains,
    ) {}

    /**
     * What the author can drag into this section, one group of sources per heading.
     *
     * Which specs are offered depends on the section's kind, and this is the whole point of the VS
     * layout: a Defensives section draws from its OPPONENT's kit, every other kind draws from the
     * author's own comp. A go additionally offers each member's real offensive cooldowns, because
     * a go is the coordinated thing — the control that creates the window and the damage that
     * spends it — and authoring one without the damage half would just be a chain with a
     * different name.
     *
     * @return Collection<int, array{spec: Specialization, groups: Collection<string, Collection<int, mixed>>}>
     */
    public function palette(UserGuideSection $section): Collection
    {
        if (! $section->kind->isSequence()) {
            return collect();
        }

        $patch = Patch::where('is_current', true)->first();
        if (! $patch) {
            return collect();
        }

        return $this->paletteSpecs($section)
            ->map(fn (Specialization $spec) => [
                'spec' => $spec,
                'groups' => $this->groupsFor($section, $spec, $patch),
            ])
            ->filter(fn (array $s) => $s['groups']->isNotEmpty())
            ->values();
    }

    /**
     * A section's blocks in author order, each with its resolved entry, DR verdict and real
     * duration.
     *
     * DIMINISHING RETURNS ARE TALLIED PER SECTION, NEVER ACROSS THE GUIDE. Two chains in one guide
     * are two separate attempts — a different go, a different game, or simply an alternative the
     * author is writing down beside the first. Carrying a DR tally from one into the next would
     * report the second chain's opener as already diminished, which is not just wrong but
     * discouraging in a way that would push authors to split guides they should be keeping
     * together. Section independence is the reason this method takes a section rather than a
     * guide.
     *
     * UNRESOLVED BLOCKS ARE KEPT, not dropped — a deliberate difference from
     * ClaudesGuides::resolveSpellEntries(), which silently drops an id with no match in the current
     * kit. That is right for a hand-authored site guide and wrong here: this is somebody's own
     * saved content, and quietly removing a step they wrote would be indistinguishable from data
     * loss.
     *
     * @return array<int, array{block: UserGuideBlock, entry: mixed, spec: ?Specialization, unresolved: bool, dr: ?array, duration: ?float}>
     */
    public function resolve(UserGuideSection $section): array
    {
        $blocks = $section->blocks()->get();
        if ($blocks->isEmpty()) {
            return [];
        }

        $specs = collect();
        $entriesByExternalId = $this->resolveEntriesForBlocks($blocks, $specs);

        // Only control steps take part in the tally: a damage or defensive cooldown has no
        // dr_category and must not consume a slot, and an unresolved block cannot diminish
        // anything. Both are skipped rather than counted as an unknown category, so neither can
        // shift the verdict on the control steps around them.
        $controlSpells = $blocks
            ->map(fn (UserGuideBlock $b) => $this->spellFor($b, $entriesByExternalId))
            ->filter(fn (?Spell $s) => $s !== null && ($entriesByExternalId[$s->spell_id] ?? null)?->drCategory() !== null)
            ->values();

        $annotations = collect($this->chains->annotateChain($controlSpells))
            ->keyBy(fn (array $a) => $a['spell']->id);

        return $blocks->map(function (UserGuideBlock $block) use ($entriesByExternalId, $annotations, $specs) {
            $externalId = $block->externalSpellId();
            $entry = $externalId !== null ? ($entriesByExternalId[$externalId] ?? null) : null;
            $spell = $this->spellFor($block, $entriesByExternalId);
            $dr = $spell !== null ? ($annotations[$spell->id] ?? null) : null;

            return [
                'block' => $block,
                'entry' => $entry,
                // The step's own caster. A guide spans a comp, so "which spec is this step" is
                // per-block information the section itself cannot supply.
                'spec' => $specs->get((int) ($block->payload['source_spec_id'] ?? 0)),
                'unresolved' => $block->block_type->referencesSpell() && $entry === null,
                'dr' => $dr,
                'duration' => $this->stepDuration($spell, $dr),
            ];
        })->all();
    }

    /**
     * The two numbers a section is judged on.
     *
     * CONTROL TIME is the sum of each control step's real PvP duration AFTER diminishing returns —
     * a second Stun contributes half its duration and a third contributes nothing, which is the
     * whole reason order matters. It is deliberately a sum of what is KNOWN: only 79% of per-spec
     * CC has a hand-verified `pvp_duration_seconds`, so steps without one are counted separately
     * and reported rather than guessed at or silently treated as zero. A total that quietly omitted
     * a third of the chain would be worse than no total at all.
     *
     * It is a sum, not a wall-clock span. Two abilities cast on different targets, or one landed
     * while another is still running, do not add up in real time — this answers "how much control
     * does this spend", not "how long is the enemy locked". The UI labels it as such.
     *
     * FREQUENCY is the longest cooldown in the section: the whole thing repeats only as often as
     * its slowest piece comes back, so the maximum is the gate and the ability holding it is named.
     * Cooldowns come from the resolved entry, so they are talent-aware and benefit from sibling
     * recovery. Steps with no cooldown are excluded and counted. It is computed for a Defensives
     * section too, where it answers the genuinely useful inverse — how often the opponent can
     * answer this at all.
     *
     * @return array{control_seconds: ?float, control_steps: int, unknown_duration_steps: int, diminished_steps: int, frequency_seconds: ?float, frequency_spell: ?string, unknown_cooldown_steps: int}
     */
    public function metrics(UserGuideSection $section): array
    {
        $steps = $this->resolve($section);
        $tracksControl = $section->kind->tracksControl();

        $controlSeconds = 0.0;
        $controlSteps = 0;
        $unknownDuration = 0;
        $diminished = 0;
        $maxCooldown = null;
        $frequencySpell = null;
        $unknownCooldown = 0;

        foreach ($steps as $step) {
            if ($step['unresolved'] || $step['entry'] === null) {
                continue;
            }

            if ($tracksControl && $step['entry']->drCategory() !== null) {
                $controlSteps++;

                if (($step['dr']['dr_percentage'] ?? 100) < 100) {
                    $diminished++;
                }

                if ($step['duration'] === null) {
                    $unknownDuration++;
                } else {
                    $controlSeconds += $step['duration'];
                }
            }

            $cooldown = $step['entry']['cooldown']['seconds'] ?? null;
            if ($cooldown === null) {
                $unknownCooldown++;
            } elseif ($maxCooldown === null || $cooldown > $maxCooldown) {
                $maxCooldown = (float) $cooldown;
                $frequencySpell = $step['entry']->displayName();
            }
        }

        return [
            // Null rather than 0.0 when nothing contributed — "no step has a verified duration"
            // and "this controls for zero seconds" are different statements.
            'control_seconds' => ($controlSteps - $unknownDuration) > 0 ? round($controlSeconds, 1) : null,
            'control_steps' => $controlSteps,
            'unknown_duration_steps' => $unknownDuration,
            'diminished_steps' => $diminished,
            'frequency_seconds' => $maxCooldown,
            'frequency_spell' => $frequencySpell,
            'unknown_cooldown_steps' => $unknownCooldown,
        ];
    }

    /** Which specs a section's palette draws from — the opponent's, or the author's own comp. */
    private function paletteSpecs(UserGuideSection $section): Collection
    {
        if ($section->kind->usesOpponent()) {
            $opponent = $section->opponentSpec;

            return $opponent ? collect([$opponent->loadMissing('gameClass')]) : collect();
        }

        return $section->guide->members()->with('specialization.gameClass')->get()
            ->map(fn ($m) => $m->specialization)
            ->filter()
            ->values();
    }

    /** @return Collection<string, Collection<int, mixed>> */
    private function groupsFor(UserGuideSection $section, Specialization $spec, Patch $patch): Collection
    {
        $entries = $this->specEntries($spec);

        if ($section->kind->usesOpponent()) {
            $defensives = $entries
                ->filter(fn ($e) => CooldownTabs::isEntry($e, 'defensive'))
                ->sortBy(fn ($e) => $e->displayName())
                ->values();

            return $defensives->isEmpty() ? collect() : collect([self::DEFENSIVE_GROUP => $defensives]);
        }

        $ccSpellIds = $this->pressableCcSpellIds($spec, $patch);

        $groups = $entries
            ->filter(fn ($e) => $ccSpellIds->contains($e['spell']->id))
            // drCategory() resolves the talent-conditional case (Holy Word: Chastise is
            // Incapacitate by default and a Stun once Censure is talented), so the palette groups
            // an ability where it will actually land for this spec rather than by its base column.
            ->groupBy(fn ($e) => $e->drCategory() ?? 'Uncategorised')
            ->sortBy(fn ($g, $category) => $this->categoryRank($category))
            ->map(fn (Collection $g) => $g->sortBy(fn ($e) => $e->displayName())->values());

        if ($section->kind->includesOffensive()) {
            $offensive = $entries
                // A CC ability that is also an offensive cooldown stays in its DR group — where it
                // lands is the more useful fact, and listing it twice would let an author add the
                // same ability from two places without noticing.
                ->reject(fn ($e) => $ccSpellIds->contains($e['spell']->id))
                ->filter(fn ($e) => CooldownTabs::isEntry($e, 'offensive'))
                ->sortBy(fn ($e) => $e->displayName())
                ->values();

            if ($offensive->isNotEmpty()) {
                $groups = $groups->put(self::OFFENSIVE_GROUP, $offensive);
            }
        }

        return $groups;
    }

    /**
     * One step's real contribution: its verified PvP duration reduced by whatever DR the section's
     * own earlier steps have already applied. Null when the ability has no hand-verified PvP
     * duration — `spells.duration_seconds` is confirmed unreliable for PvP (Polymorph's PvE
     * tooltip reads 60s against a real 6s) and is deliberately never substituted here.
     */
    private function stepDuration(?Spell $spell, ?array $dr): ?float
    {
        if ($spell === null || $spell->pvp_duration_seconds === null || $dr === null) {
            return null;
        }

        return round((float) $spell->pvp_duration_seconds * (($dr['dr_percentage'] ?? 100) / 100), 2);
    }

    /** Every kit entry for a spec, memoised per request — the palette asks for this repeatedly. */
    private array $specEntriesMemo = [];

    private function specEntries(Specialization $spec): Collection
    {
        return $this->specEntriesMemo[$spec->id] ??= collect(
            $this->kits->resolveEntriesForSpellIds(
                $this->kitSpells($spec)->pluck('spell_id')->all(),
                $spec,
                $this->spellReferences,
                $this->talents,
            )
        );
    }

    private array $kitSpellsMemo = [];

    /**
     * The documented-safe per-spec spell sources only. Deliberately NOT
     * TalentSelectionService::alwaysAvailableAbilityIds() — see its "DO NOT WIRE IN" docblock; it
     * derives spec from the ambiguous spec_id = NULL bucket and leaked Shadow spells onto
     * Discipline Priest in 2026-08-06.
     */
    private function kitSpells(Specialization $spec): Collection
    {
        if (isset($this->kitSpellsMemo[$spec->id])) {
            return $this->kitSpellsMemo[$spec->id];
        }

        $ids = $this->talents->allTalentSpellIds($spec->id)
            ->merge($this->talents->allPvpTalentSpellIds($spec->id))
            ->merge($this->talents->verifiedBaselineAbilityIds($spec->id))
            ->merge($this->talents->explicitBaselineCooldownAbilityIds($spec->class_id, $spec->id))
            ->unique()
            ->values();

        return $this->kitSpellsMemo[$spec->id] = Spell::whereIn('id', $ids)->get();
    }

    /**
     * Reuses SpellCounterIndexer::pressableCcSpells() rather than filtering `dr_category IS NOT
     * NULL` directly, because those are not the same question: many curated dr_category rows sit on
     * passive-granted aura copies nobody casts (Absolute Zero's freeze aura is tagged Stun and is
     * not a keybind). /spell-counters shipped with that looser filter and listed abilities no
     * player can press; narrowing both sides to one shared definition is what fixed it on
     * 2026-09-07. An author must not be able to drag an ability that does not exist as a button.
     */
    private function pressableCcSpellIds(Specialization $spec, Patch $patch): Collection
    {
        $kitIds = $this->kitSpells($spec)->pluck('id');

        return $kitIds->isEmpty()
            ? collect()
            : $this->counters->pressableCcSpells($patch, $kitIds)->pluck('id');
    }

    private function categoryRank(string $category): int
    {
        $rank = array_search($category, self::CATEGORY_ORDER, true);

        return $rank === false ? count(self::CATEGORY_ORDER) : $rank;
    }

    /**
     * Resolve every spell block's external id to a kit entry, one batched call per distinct source
     * spec.
     *
     * A guide spans a comp, so blocks come from several specs at once and each has to be resolved
     * against ITS OWN spec — resolving them all against one spec would produce talent-aware
     * cooldowns from the wrong build, which is the kind of wrong that looks completely fine.
     *
     * @param  Collection<int, UserGuideBlock>  $blocks
     * @param  Collection<int, Specialization>  $specs  filled in place, spec id => Specialization
     * @return array<int, mixed> external spell_id => entry
     */
    private function resolveEntriesForBlocks(Collection $blocks, Collection $specs): array
    {
        $resolved = [];

        $spellBlocks = $blocks->filter(fn (UserGuideBlock $b) => $b->block_type === UserGuideBlockType::Spell);

        foreach ($spellBlocks->groupBy(fn (UserGuideBlock $b) => (int) ($b->payload['source_spec_id'] ?? 0)) as $specId => $group) {
            $spec = $specId > 0 ? Specialization::with('gameClass')->find($specId) : null;
            if (! $spec) {
                continue;
            }

            $specs->put((int) $specId, $spec);

            $externalIds = $group->map(fn (UserGuideBlock $b) => $b->externalSpellId())->filter()->unique()->values()->all();
            if ($externalIds === []) {
                continue;
            }

            foreach ($this->kits->resolveEntriesForSpellIds($externalIds, $spec, $this->spellReferences, $this->talents) as $entry) {
                $resolved[$entry['spell']->spell_id] = $entry;
            }
        }

        return $resolved;
    }

    /** The Spell behind a block, or null for a non-spell block or one that no longer resolves. */
    private function spellFor(UserGuideBlock $block, array $entriesByExternalId): ?Spell
    {
        $externalId = $block->externalSpellId();
        if ($externalId === null) {
            return null;
        }

        return $entriesByExternalId[$externalId]['spell'] ?? null;
    }
}
