<?php

namespace App\Http\Services;

use App\Enums\UserGuideBlockType;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Models\UserGuide;
use App\Models\UserGuideBlock;
use App\Models\UserGuideSection;
use App\Support\CooldownTabs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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

    private const UTILITY_GROUP = 'Utility & other';

    /**
     * Bump when computeGroupsFor() changes WHICH abilities land in WHICH group.
     *
     * The palette cache is keyed on spellCacheVersion + deployedCodeFingerprint, and neither moves
     * when the grouping LOGIC changes — the first counts data edits, the second only changes on a
     * real deploy, so in development a shape change is served from a stale entry indefinitely and
     * looks like the change simply did not work. That is exactly what happened on 2026-09-09: both
     * the Garrote de-duplication and Rake's stun were invisible after the code was correct, which
     * cost a round of debugging aimed at the wrong layer.
     *
     * Deliberately NOT solved by calling bumpSpellCacheVersion(): that counter also keys all 40
     * precomputed spell kits, so using it to publish a change to one palette would drop WoW Comps
     * and Spell Explorer onto their slow live-compute path for no reason — the same
     * over-invalidation trap already recorded for the burst guides.
     */
    private const PALETTE_SHAPE_VERSION = 3;

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
     * author's own comp.
     *
     * A SEQUENCE OFFERS THE WHOLE PRESSABLE KIT, not a filtered slice of it (2026-09-08). It used
     * to offer control only, plus offensive cooldowns if the section had been created as a "go" —
     * so the kind chosen at creation time silently decided which half of your own class you were
     * allowed to reach for, with no way to change your mind afterwards. That filtering was the
     * only real difference between the two kinds, and removing it is what let them merge. The
     * grouping survives and does the same job better: control first, by DR category, then the
     * offensive and defensive cooldowns, then everything else that is a real button.
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
                'groups' => $this->groupsFor($section, $spec, $patch, $this->buildForSpec($section, $spec->id)),
            ])
            ->filter(fn (array $s) => $s['groups']->isNotEmpty())
            ->values();
    }

    /**
     * The talent build a spec is being played with in this guide, or null to use the spec's
     * admin-curated default.
     *
     * Only ever the author's OWN comp. A Defensives section's opponent is not in the guide's
     * roster and has no build of its own here, which is correct: you do not know what your
     * opponent talented, and inventing an answer would be worse than showing the meta default.
     *
     * A comp with the same spec in two slots (a mirror double-DPS) resolves to the first slot's
     * build. Steps record only which SPEC they came from, not which slot, so the two are already
     * indistinguishable downstream — a per-slot answer would need the block payload to carry the
     * member, which is a bigger change than this is worth until someone actually writes a guide
     * where the two copies run different talents.
     */
    private function buildForSpec(UserGuideSection $section, int $specId): ?TalentBuild
    {
        if ($section->kind->usesOpponent()) {
            return null;
        }

        return $this->memberBuilds($section)->get($specId);
    }

    /** @var array<int, Collection<int, TalentBuild>> guide id => spec id => build */
    private array $memberBuildsMemo = [];

    /** @return Collection<int, TalentBuild> keyed by spec id; a member with no build is absent. */
    private function memberBuilds(UserGuideSection $section): Collection
    {
        $guideId = (int) $section->user_guide_id;

        return $this->memberBuildsMemo[$guideId] ??= $section->guide->members()
            ->with('talentBuild')
            ->get()
            ->filter(fn ($m) => $m->talentBuild !== null)
            ->keyBy('spec_id')
            ->map(fn ($m) => $m->talentBuild);
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
        // Memoised because metrics() is resolve() plus arithmetic, and the builder asks for both
        // per section on every render — so every block query, entry resolution and DR annotation
        // was being done exactly twice. Per-request only, which is the correct lifetime: adding a
        // step must change what the next render resolves.
        return $this->resolveMemo[$section->id] ??= $this->computeResolve($section);
    }

    /** @var array<int, array> section id => resolved steps, this request only */
    private array $resolveMemo = [];

    private function computeResolve(UserGuideSection $section): array
    {
        $blocks = $section->blocks()->get();
        if ($blocks->isEmpty()) {
            return [];
        }

        $specs = collect();
        $entriesByExternalId = $this->resolveEntriesForBlocks($blocks, $specs, $section);

        // Only control steps take part in the tally: a damage or defensive cooldown has no
        // dr_category and must not consume a slot, and an unresolved block cannot diminish
        // anything. Both are skipped rather than counted as an unknown category, so neither can
        // shift the verdict on the control steps around them.
        //
        // The verdicts come back keyed by BLOCK id, never by spell id. An author can legitimately
        // use the same ability twice in one chain, and keying by spell collapses those two steps
        // onto a single annotation (last write wins) — so BOTH occurrences render the second
        // one's diminished verdict. Reported live 2026-09-10: two Cyclones in a row each showed
        // 50%, instead of 100% then 50%, which also understated the section's control time by
        // half a Cyclone. annotateChain() preserves the caller's order, so the nth verdict
        // belongs to the nth control block.
        $controlBlockIds = [];
        $controlSpells = collect();

        foreach ($blocks as $block) {
            $spell = $this->spellFor($block, $entriesByExternalId);

            if ($spell === null || ($entriesByExternalId[$spell->spell_id] ?? null)?->drCategory() === null) {
                continue;
            }

            $controlBlockIds[] = $block->id;
            $controlSpells->push($spell);
        }

        $annotations = [];
        foreach ($this->chains->annotateChain($controlSpells) as $i => $annotation) {
            $annotations[$controlBlockIds[$i]] = $annotation;
        }

        return $blocks->map(function (UserGuideBlock $block) use ($entriesByExternalId, $annotations, $specs) {
            $externalId = $block->externalSpellId();
            $entry = $externalId !== null ? ($entriesByExternalId[$externalId] ?? null) : null;
            $spell = $this->spellFor($block, $entriesByExternalId);
            $dr = $annotations[$block->id] ?? null;

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

    /**
     * What has drifted under this guide since it was written — the guide-level view of a fact the
     * per-step rendering already shows one step at a time.
     *
     * WHY A GUIDE NEEDS THIS AT ALL. Steps store Blizzard's external spell id and are re-resolved
     * against the CURRENT patch on every single render, never against the patch the guide was
     * authored on (user_guides.patch_id is informational and nothing reads it to resolve
     * anything). So a patch that retunes a cooldown, changes a talent, or flips a DR category is
     * picked up automatically and silently — which is the right default, because the alternative
     * is a guide confidently showing numbers that stopped being true. The one case that cannot be
     * handled silently is a spell id that no longer exists at all: the step is KEPT and rendered
     * as "Ability no longer found" so the author can replace it, rather than being deleted, which
     * would be indistinguishable from data loss.
     *
     * This method exists because that per-step marker is only visible to somebody already
     * scrolling the guide. An author who published six months ago has no reason to open it again,
     * so the count is surfaced at the top instead.
     *
     * Takes the already-computed resolve() output rather than recomputing it — both the builder
     * and the public page have it in hand, and resolving twice per render to count something would
     * double the cost of the page for a banner.
     *
     * @param  array<int, array{steps: array, metrics: array}>  $resolved  keyed by section id
     * @return array{unresolved:int, sections:array<int,string>, authored_patch:?string, current_patch:?string, patch_changed:bool}
     */
    public function health(UserGuide $guide, array $resolved): array
    {
        $unresolved = 0;
        $sections = [];

        foreach ($guide->sections as $section) {
            $count = collect($resolved[$section->id]['steps'] ?? [])
                ->filter(fn (array $s) => $s['unresolved'])
                ->count();

            if ($count > 0) {
                $unresolved += $count;
                $sections[$section->id] = $section->title;
            }
        }

        $authored = $guide->patch?->build_version;
        $current = Patch::where('is_current', true)->value('build_version');

        return [
            'unresolved' => $unresolved,
            'sections' => $sections,
            'authored_patch' => $authored,
            'current_patch' => $current,
            // Only ever true when the guide actually recorded a patch. A guide with none was
            // never really authored against one (patch_id is set on first real edit), and
            // claiming it is out of date would be inventing a fact.
            'patch_changed' => $authored !== null && $current !== null && $authored !== $current,
        ];
    }

    /** Which specs a section's palette draws from — the opponent's, or the author's own comp. */
    private function paletteSpecs(UserGuideSection $section): Collection
    {
        if ($section->kind->usesOpponent()) {
            // Narrowest wins, and each step is a deliberate answer to a different question:
            //
            //   1. the section's OWN opponent  - "this column is about their healer specifically"
            //   2. the guide's ENEMY TEAM      - the whole comp you are playing against, so a VS
            //                                    column can hold a full 3v3 rather than one spec
            //   3. a class guide's single opponent - "Rogue vs Disc", named once for the guide so
            //      the author is not asked for the same spec again in every section
            //
            // A section that names nobody used to fall straight through to empty on a comp guide,
            // which is exactly what made a VS column single-spec by construction.
            if ($section->opponentSpec) {
                return collect([$section->opponentSpec->loadMissing('gameClass')]);
            }

            $enemies = $section->guide->enemies()->with('specialization.gameClass')->get()
                ->map(fn ($m) => $m->specialization)
                ->filter()
                ->values();

            if ($enemies->isNotEmpty()) {
                return $enemies;
            }

            $opponent = $section->guide->opponentSpec;

            return $opponent ? collect([$opponent->loadMissing('gameClass')]) : collect();
        }

        return $section->guide->members()->with('specialization.gameClass')->get()
            ->map(fn ($m) => $m->specialization)
            ->filter()
            ->values();
    }

    /** @return Collection<string, Collection<int, mixed>> */
    /**
     * Cached across requests, not just memoised within one — but ONLY THE SHAPE.
     *
     * Everything below is a pure function of (spec, talent build, section SHAPE) — never of the
     * section's own blocks, its title, or which guide it belongs to. So two sections in the same
     * guide, and two different authors' guides using the same spec, share one entry. Worth
     * caching because it is the last real cost in an ability click: ~270ms and ~50 queries per
     * spec-set on a warm Redis, paid again on every round trip, since a Livewire request has no
     * memo from the last one.
     *
     * WHAT IS CACHED IS A LIST OF SPELL IDS PER GROUP, NOT THE ENTRIES THEMSELVES, and that is
     * not a micro-optimisation — the first version of this cached the entries and was fatal in
     * production. One spec's entries serialize to 6.4MB (each entry is ~6.6KB, of which 5.3KB is
     * the Spell model and its relations); the same grouping as bare ids is 2.0KB. Production runs
     * PHP with memory_limit=128M, and a 3-spec comp guide whose members all name their own talent
     * build reproducibly died inside RedisStore::serialize() — serialize() needs the whole string
     * in memory alongside the live objects, on top of the identical payload specEntries() is
     * already caching. Measured at prod's exact limit before this rewrite: one spec peaked at
     * 76MB, three specs OOMed outright.
     *
     * Rehydration is free: specEntries() is already loaded on this path anyway, so the ids are
     * mapped straight back through it. An id that no longer resolves (a patch removed the spell)
     * is dropped rather than rendering broken, and a group left empty disappears with it.
     */
    private function groupsFor(UserGuideSection $section, Specialization $spec, Patch $patch, ?TalentBuild $build = null): Collection
    {
        $key = sprintf(
            'guide_palette:s%d:%d:%s:%s:%s:v%s:%s',
            self::PALETTE_SHAPE_VERSION,
            $spec->id,
            $build === null ? 'default' : $build->id.'@'.($build->updated_at?->timestamp ?? 0),
            $section->kind->usesOpponent() ? 'opp' : 'own',
            $section->guide->type->usesWholeKit() ? 'whole' : 'cds',
            $this->talents->spellCacheVersion(),
            $this->talents->deployedCodeFingerprint(),
        );

        $shape = Cache::remember(
            $key,
            now()->addDay(),
            fn () => $this->computeGroupsFor($section, $spec, $patch, $build)
                ->map(fn (Collection $entries) => $entries->map(fn ($e) => $e['spell']->id)->all())
                ->all()
        );

        if ($shape === []) {
            return collect();
        }

        $byId = $this->specEntries($spec, $build)->keyBy(fn ($e) => $e['spell']->id);

        return collect($shape)
            ->map(fn (array $ids) => collect($ids)
                ->map(fn (int $id) => $byId->get($id))
                ->filter()
                ->values())
            ->filter(fn (Collection $entries) => $entries->isNotEmpty());
    }

    private function computeGroupsFor(UserGuideSection $section, Specialization $spec, Patch $patch, ?TalentBuild $build = null): Collection
    {
        $entries = $this->specEntries($spec, $build);

        if ($section->kind->usesOpponent()) {
            $defensives = $entries
                ->filter(fn ($e) => CooldownTabs::isEntry($e, 'defensive'))
                ->sortBy(fn ($e) => $e->displayName())
                ->values();

            return $defensives->isEmpty() ? collect() : collect([self::DEFENSIVE_GROUP => $defensives]);
        }

        $ccSpellIds = $this->pressableCcSpellIds($spec, $patch);
        $entries = $this->onePerDisplayName($entries);

        $groups = $entries
            ->filter(fn ($e) => $ccSpellIds->contains($e['spell']->id))
            // drCategory() resolves the talent-conditional case (Holy Word: Chastise is
            // Incapacitate by default and a Stun once Censure is talented), so the palette groups
            // an ability where it will actually land for this spec rather than by its base column.
            ->groupBy(fn ($e) => $e->drCategory() ?? 'Uncategorised')
            ->sortBy(fn ($g, $category) => $this->categoryRank($category))
            ->map(fn (Collection $g) => $g->sortBy(fn ($e) => $e->displayName())->values());

        // Every ability is offered exactly once, in the most informative group it qualifies for:
        // control beats cooldown, and offensive beats defensive for the handful classified as
        // both. Listing one ability twice would let an author add it from two places without
        // noticing, and would make the palette look bigger than the kit actually is.
        $claimed = $ccSpellIds->flip();
        $remaining = $entries->reject(fn ($e) => $claimed->has($e['spell']->id));

        foreach ([self::OFFENSIVE_GROUP => 'offensive', self::DEFENSIVE_GROUP => 'defensive'] as $label => $direction) {
            $matched = $remaining
                ->filter(fn ($e) => CooldownTabs::isEntry($e, $direction))
                ->sortBy(fn ($e) => $e->displayName())
                ->values();

            if ($matched->isNotEmpty()) {
                $groups = $groups->put($label, $matched);
                $claimed = $claimed->union($matched->pluck('spell.id')->flip());
                $remaining = $remaining->reject(fn ($e) => $claimed->has($e['spell']->id));
            }
        }

        // CLASS GUIDES ONLY. A 3v3 go is control and cooldowns; listing every filler, poison and
        // movement ability alongside them made the comp palette harder to use for no gain, which
        // was the direct report that split the two guide types apart ("we don't need utility or
        // other in the 3v3 2v2 guide section, that's for a different type of guide"). A rotation
        // or technique guide is built out of exactly those abilities, so it gets them.
        if ($section->guide->type->usesWholeKit()) {
            $explicitBaseline = $this->explicitBaselineSpellIds($spec);
            $utility = $remaining
                ->filter(fn ($e) => $this->isPressable($e, $explicitBaseline))
                ->sortBy(fn ($e) => $e->displayName())
                ->values();

            if ($utility->isNotEmpty()) {
                $groups = $groups->put(self::UTILITY_GROUP, $utility);
            }
        }

        return $groups;
    }

    /**
     * Whether an entry is a real button rather than a passive the kit happens to contain.
     *
     * A spec's kit is mostly passive talent modifiers — Improved Fade does not go in a sequence,
     * it changes what Fade does. Two signals, both already trusted elsewhere in this codebase and
     * neither invented here: Blizzard's own `Passive (6)` attribute (`spells.is_passive`, the same
     * flag <x-spells.table> splits Active Abilities from Buffs & Passives on) and
     * `Not In Spellbook (143)` (`spells.not_in_spellbook`, which flags internal data-carrier
     * copies of a visible ability).
     *
     * Those two alone are not enough, which is why the cooldown/charges/isPriority test below
     * exists: Blizzard does not flag every passive as passive, and the naive rule let 23 entries
     * into Subtlety's Utility group that are plainly not buttons (Control is King, Dagger in the
     * Dark, Silhouette, Thief's Bargain).
     *
     * $explicitBaseline IS THE ESCAPE HATCH, and it is provenance rather than another heuristic.
     * Those false positives are all TALENTS. An ability holding a baseline
     * spell_class_availability row that names THIS EXACT SPEC, is not passive and is not flagged
     * Not In Spellbook is Blizzard stating outright that it is a real spellbook button, so it
     * needs no further proof — and demanding one is what hid a Rogue's Rupture, Envenom and
     * Mutilate from a class guide, since a finisher has no cooldown, no charges, and (on a spec
     * with a thin match sample) no arena-log priority flag either. Absence of log evidence is not
     * evidence the button does not exist.
     *
     * @param  Collection<int, int>|null  $explicitBaseline  spell ids, see explicitBaselineSpellIds()
     */
    private function isPressable(mixed $entry, ?Collection $explicitBaseline = null): bool
    {
        $spell = $entry['spell'];

        if ($spell->is_passive || $spell->not_in_spellbook) {
            return false;
        }

        if ($explicitBaseline !== null && $explicitBaseline->contains($spell->id)) {
            return true;
        }

        return ($entry['cooldown']['seconds'] ?? null) !== null
            || ($entry['charges']['charges'] ?? null) !== null
            || ($entry['isPriority'] ?? false);
    }

    /** @var array<int, Collection<int, int>> spec id => explicit-baseline spell ids */
    private array $explicitBaselineMemo = [];

    /**
     * The spec's own explicitly-tagged baseline abilities — never the `spec_id = NULL` bucket,
     * which is the ambiguous one this codebase has repeatedly been burned by.
     */
    private function explicitBaselineSpellIds(Specialization $spec): Collection
    {
        return $this->explicitBaselineMemo[$spec->id]
            ??= $this->talents->explicitBaselineAbilityIds($spec->class_id, $spec->id);
    }

    /**
     * Collapse same-named copies of one ability to a single palette entry.
     *
     * A spec's kit routinely holds several `spells` rows sharing one display name — the pattern
     * documented all over this codebase (Penance, Ultimate Penitence, Smoke Bomb, Garrote), where
     * one copy carries the real cooldown and the others are internal data carriers. Without this
     * the palette offered "Thistle Tea" twice, and listed Smoke Bomb and Secret Technique under
     * both a cooldown group and Utility, because the id-based grouping saw genuinely different
     * spells. An author picking the wrong copy would then get a step with no cooldown for an
     * ability that plainly has one.
     *
     * Preference order matches the tiering TalentSelectionService::preferSelectedPerName() and
     * ModuleSpellReferenceService::resolveSpellByName() already use: a copy with real cooldown
     * data first, then one this spec's build actually has selected, then the lowest id so the
     * result is deterministic rather than dependent on query order.
     *
     * @param  Collection<int, mixed>  $entries
     * @return Collection<int, mixed>
     */
    private function onePerDisplayName(Collection $entries): Collection
    {
        // Two-argument comparators — see the note in SpellCounterIndexer::narrowToPressable(). The
        // one-argument form silently sorts by nonsense here, and did: it kept Secret Technique's
        // effect-less internal copy over the real 25s-cooldown ability, which then failed the
        // offensive-cooldown test and vanished from the palette entirely.
        return $entries
            ->groupBy(fn ($e) => $e->displayName())
            ->map(fn (Collection $copies) => $copies->sortBy([
                // A CURATED dr_category WINS EVERYTHING ELSE, and this comparator is why Rake's
                // stun was missing from Feral's palette entirely — reported 2026-09-09 as "no rake
                // (3s stun) available as CC".
                //
                // Rake is two rows: 1822, the damaging ability a Feral presses constantly, and
                // 163505, the stun it applies from stealth — which is the row carrying
                // dr_category. NEITHER has a cooldown, so the cooldown comparator tied, and
                // isPriority then decided it: 1822 is all over the arena logs and 163505 is not,
                // so the damage copy won, the survivor had no dr_category, and the ability
                // disappeared from crowd control rather than appearing in it.
                //
                // Losing the CC classification is a strictly worse error than losing a cooldown
                // number, and it costs nothing to avoid: ModuleSpellReferenceService::
                // resolveBaseCooldownCharges() already recovers a missing cooldown from a
                // same-named sibling, so preferring the tagged copy keeps the number too. Ties
                // (both copies tagged, e.g. Fear's two rows) fall through to the tests below
                // unchanged.
                fn ($a, $b) => (($a['spell']->dr_category !== null) ? 0 : 1)
                    <=> (($b['spell']->dr_category !== null) ? 0 : 1),
                // Two-argument comparators — see the note in SpellCounterIndexer::narrowToPressable().
                fn ($a, $b) => (($a['cooldown']['seconds'] ?? null) !== null ? 0 : 1)
                    <=> (($b['cooldown']['seconds'] ?? null) !== null ? 0 : 1),
                fn ($a, $b) => (($a['isPriority'] ?? false) ? 0 : 1) <=> (($b['isPriority'] ?? false) ? 0 : 1),
                fn ($a, $b) => (($a['isSelected'] ?? false) ? 0 : 1) <=> (($b['isSelected'] ?? false) ? 0 : 1),
                fn ($a, $b) => $a['spell']->id <=> $b['spell']->id,
            ])->first())
            ->values();
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

    /**
     * Every kit entry for a spec, resolved against $build's talents when one is given.
     *
     * A guide comp slot may name the build it is actually playing (user_guide_members
     * .talent_build_id), and when it does, every cooldown, charge count and talent-conditional DR
     * category on this spec's abilities has to be computed against THAT build — a guide that says
     * "Fade, 20s" because its author took Improved Fade must not show 30s because the admin
     * default build did not.
     *
     * A build-specific resolution cannot use the precomputed spell kit (written per spec against
     * the admin default), so it is a live compute — measured elsewhere in this codebase at several
     * seconds for a handful of specs. Cached on the build's own id and updated_at, so editing
     * talents invalidates it immediately and nothing else does, plus the same spellCacheVersion/
     * deployedCodeFingerprint keys every other spell cache here uses. The memo below still applies
     * within one request; this cache is what stops every page load paying for it again.
     */
    private function specEntries(Specialization $spec, ?TalentBuild $build = null): Collection
    {
        $memoKey = $spec->id.':'.($build?->id ?? 0);

        return $this->specEntriesMemo[$memoKey] ??= collect(
            $this->kits->fromJsonSafeArray(['entries' => $this->specEntriesRaw($spec, $build)])
        );
    }

    /**
     * A section's blocks resolved WITHOUT hydrating the spec's whole kit.
     *
     * The cached payload is the entire kit — 164 entries and 256KB for Discipline — and
     * fromJsonSafeArray() turns every one of them back into a Spell model with its effects and
     * incoming relationships (~40ms and a batched read per spec). A five-step section needs three
     * of those entries, so a render was rebuilding roughly fifty times the objects it went on to
     * use, once per spec, on every request.
     *
     * Filtering happens on the CACHED ARRAY, before hydration, which is the only place it saves
     * anything. It costs one cheap id lookup: the array is keyed by internal spells.id while a
     * block stores Blizzard's external spell_id, and translating the handful of ids we want is far
     * less work than hydrating the entries we don't. Deliberately NOT solved by adding the external
     * id to the cached shape — that shape is shared with the 40 precomputed kit files on disk, so
     * changing it would invalidate all of them for a saving this achieves without touching them.
     *
     * Falls through to the full hydration when it is already memoised for this spec, since the
     * palette on an open section has paid for it anyway and a second partial pass would be waste.
     *
     * @param  array<int, int>  $externalSpellIds
     * @return Collection<int, mixed> keyed by external spell_id
     */
    private function specEntriesForSpellIds(Specialization $spec, ?TalentBuild $build, array $externalSpellIds): Collection
    {
        $memoKey = $spec->id.':'.($build?->id ?? 0);

        if (isset($this->specEntriesMemo[$memoKey])) {
            return $this->specEntriesMemo[$memoKey]->keyBy(fn ($e) => $e['spell']->spell_id);
        }

        $wanted = Spell::where('patch_id', $this->currentPatchId())
            ->whereIn('spell_id', $externalSpellIds)
            ->pluck('id')
            ->flip();

        $subset = array_values(array_filter(
            $this->specEntriesRaw($spec, $build),
            fn (array $e) => $wanted->has($e['spellId'] ?? 0)
        ));

        return collect($this->kits->fromJsonSafeArray(['entries' => $subset]))
            ->keyBy(fn ($e) => $e['spell']->spell_id);
    }

    private ?int $currentPatchIdMemo = null;

    private function currentPatchId(): ?int
    {
        return $this->currentPatchIdMemo ??= Patch::where('is_current', true)->value('id');
    }

    /** @var array<string, array> the cached JSON-safe kit payload, before hydration */
    private array $specEntriesRawMemo = [];

    /** @return array<int, array> the cached JSON-safe entries for a spec+build */
    private function specEntriesRaw(Specialization $spec, ?TalentBuild $build = null): array
    {
        $memoKey = $spec->id.':'.($build?->id ?? 0);

        if (isset($this->specEntriesRawMemo[$memoKey])) {
            return $this->specEntriesRawMemo[$memoKey];
        }

        // The no-build path is cached too, and that is not redundant with the precomputed kit
        // behind it. resolveEntriesForSpellIds() falls back to a full live compute() whenever the
        // kit file is stale, and a stale file is the NORMAL state right after any import, deploy
        // or spell-cache bump — measured 2026-09-08 with all 40 kits one version behind: 9,545ms
        // and 4,290 queries per palette build, against 376ms and 87 with a fresh file. Leaving
        // this uncached is what turned a routine, self-correcting staleness into a 25x
        // site-feel regression that reported as "clicking an ability does nothing".
        $key = $build === null
            ? sprintf(
                'guide_kit:%d:default:v%s:%s',
                $spec->id,
                $this->talents->spellCacheVersion(),
                $this->talents->deployedCodeFingerprint(),
            )
            : sprintf(
                'guide_kit:%d:build%d:%s:v%s:%s',
                $spec->id,
                $build->id,
                $build->updated_at?->timestamp ?? 0,
                $this->talents->spellCacheVersion(),
                $this->talents->deployedCodeFingerprint(),
            );

        // STORED IN THE COMPACT JSON-SAFE SHAPE, NOT AS LIVE OBJECTS. Serializing the entries
        // directly costs 6.4MB per spec (a Spell model and its relations is 5.3KB of each ~6.6KB
        // entry); toJsonSafeArray() — the same representation the 40 precomputed kit files
        // already use — is 296KB, 22x smaller, and rehydrates in ~80ms. Production runs
        // memory_limit=128M, and serialize() holds the whole string alongside the live objects,
        // so a 3-spec comp guide whose members each name their own talent build reproducibly
        // died inside RedisStore::serialize() at that limit. Verified at prod's exact 128M
        // before and after.
        // kitSpells() IS RESOLVED INSIDE THE CLOSURE, not before it. It is four separate
        // per-spec id lookups plus a Spell read (~50 queries), and it is only ever an INPUT to
        // building the cache entry — on a hit, which is the overwhelmingly common case, nothing
        // needs it. Computing it eagerly meant every cache hit still paid for the miss path's
        // homework.
        return $this->specEntriesRawMemo[$memoKey] = Cache::remember(
            $key,
            now()->addDay(),
            fn () => $this->kits->toJsonSafeArray(
                $this->kits->resolveEntriesForSpellIds(
                    $this->kitSpells($spec)->pluck('spell_id')->all(),
                    $spec,
                    $this->spellReferences,
                    $this->talents,
                    $build,
                )
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
            // Not explicitBaselineCooldownAbilityIds(): that one's >=10s floor is written for
            // WoW Comps' cooldown tabs, and reusing it here silently answered a different
            // question — it dropped every cooldown-less core ability, which is why a class guide
            // could not name Rupture. See that method's sibling for why explicit-spec_id-only is
            // safe without curation.
            ->merge($this->talents->explicitBaselineAbilityIds($spec->class_id, $spec->id))
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
    /**
     * Aura copies that are correctly CC-tagged but are NOT a button, so an author must not be
     * offered them next to the ability they actually press.
     *
     * THE MIRROR IMAGE OF FindCcChains::CC_CHAIN_EXCLUDED_SPELL_IDS, and the same underlying
     * fact read from the other side. Garrote is two rows: 703 is the pressed ability (6s
     * cooldown, and its own description reads "Silences the target for $1330d when used from
     * Stealth"), and 1330 is the silence aura that line points at. A combat log records the
     * AURA, so the chain finder keeps 1330 and drops 703; a palette offers BUTTONS, so it keeps
     * 703 and drops 1330. Both rows must stay curated — neither side can untag the other's.
     *
     * Named explicitly rather than inferred from the " - " naming convention: across all 162
     * CC-tagged spells in the current patch, "Garrote - Silence" is the ONLY name of that shape,
     * so a pattern rule would be one instance dressed up as a rule. A list of one, with the
     * reason attached, is the honest version.
     */
    private const PALETTE_EXCLUDED_CC_SPELL_IDS = [1330]; // Garrote - Silence — press "Garrote" (703)

    private function pressableCcSpellIds(Specialization $spec, Patch $patch): Collection
    {
        $kitIds = $this->kitSpells($spec)->pluck('id');

        return $kitIds->isEmpty()
            ? collect()
            : $this->counters->pressableCcSpells($patch, $kitIds)
                ->reject(fn (Spell $s) => in_array((int) $s->spell_id, self::PALETTE_EXCLUDED_CC_SPELL_IDS, true))
                ->pluck('id');
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
     * RESOLVED THROUGH specEntries(), NOT SpecKitComputer::resolveEntriesForSpellIds() DIRECTLY,
     * and that is the difference between a builder that feels instant and one that does not.
     * That method has no cache of its own: it computes the spec's WHOLE kit and then narrows it
     * to the handful of ids asked for, so calling it per section per render meant a full live
     * compute() every time — including on a plain rename, which touches no ability at all. It
     * only avoids that when a precomputed kit file happens to be fresh, and a stale file is the
     * NORMAL state after any import, deploy or spell-cache bump (measured 2026-09-08 with all 40
     * kits stale: 4,708ms and 4,574 queries to resolve ONE five-step section, unchanged on a
     * second pass because nothing cached it). specEntries() computes the same kit once and caches
     * it, so a resolve is now a lookup in an already-loaded collection.
     *
     * An id that is not in the kit falls back to SpecKitComputer::baselineCoreEntry() — the same
     * fallback resolveEntriesForSpellIds() applies, shared rather than reimplemented, so an
     * unconditional core ability (Envenom, Rupture) still renders with a real name and icon.
     *
     * @param  Collection<int, UserGuideBlock>  $blocks
     * @param  Collection<int, Specialization>  $specs  filled in place, spec id => Specialization
     * @return array<int, mixed> external spell_id => entry
     */
    private function resolveEntriesForBlocks(Collection $blocks, Collection $specs, ?UserGuideSection $section = null): array
    {
        $resolved = [];

        $spellBlocks = $blocks->filter(fn (UserGuideBlock $b) => $b->block_type === UserGuideBlockType::Spell);

        foreach ($spellBlocks->groupBy(fn (UserGuideBlock $b) => (int) ($b->payload['source_spec_id'] ?? 0)) as $specId => $group) {
            // Memoised: a guide's sections draw from the same three specs over and over, so this
            // was re-reading the same rows once per section per render.
            $spec = $specId > 0 ? $this->specById((int) $specId) : null;
            if (! $spec) {
                continue;
            }

            $specs->put((int) $specId, $spec);

            $externalIds = $group->map(fn (UserGuideBlock $b) => $b->externalSpellId())->filter()->unique()->values()->all();
            if ($externalIds === []) {
                continue;
            }

            // Same build the palette offered this spec's abilities from, so a step's numbers do
            // not change the moment it stops being a palette preview and becomes a saved step.
            $build = $section !== null ? $this->buildForSpec($section, (int) $specId) : null;

            $byExternalId = $this->specEntriesForSpellIds($spec, $build, $externalIds);

            foreach ($externalIds as $externalId) {
                $entry = $byExternalId->get($externalId) ?? $this->kits->baselineCoreEntry((int) $externalId);

                if ($entry !== null) {
                    $resolved[$entry['spell']->spell_id] = $entry;
                }
            }
        }

        return $resolved;
    }

    /** @var array<int, ?Specialization> spec id => spec, this request only */
    private array $specByIdMemo = [];

    private function specById(int $specId): ?Specialization
    {
        return $this->specByIdMemo[$specId] ??= Specialization::with('gameClass')->find($specId);
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
