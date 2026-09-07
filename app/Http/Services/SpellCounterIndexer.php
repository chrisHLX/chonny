<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellCounter;
use App\Models\SpellEffect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Materializes the spell_counters table.
 *
 * The matching rules here are NOT new — they are lifted from ClaudesCounters::
 * getCounterableByClassProperty(), which is where they were developed and verified (including
 * the 2026-09-04 Fear/Howl of Terror bug fix: a magic-school CC never enters the dodge/parry
 * roll at all, so Evasion is not a counter to it regardless of bypasses_active_defense). What
 * changed is only WHERE they run: once, at import, instead of on every page load — and therefore
 * WHO can ask the question, which was previously that one Livewire component and nothing else.
 *
 * Every input is build-independent (dr_category, usable_while_cc, Mechanic Immunity effects,
 * School Immunity effects, Modify Dodge%/Parry% effects, school, bypasses_active_defense), which
 * is precisely what makes materializing this correct rather than a cache that can go subtly
 * stale per viewer.
 *
 * Two deliberate conservatisms carried over unchanged, because loosening either would be
 * inventing facts this project has repeatedly had to catch and revert (Mind Sear, the abandoned
 * alwaysAvailableAbilityIds() heuristic):
 *
 *   - Only dr_category values with an EXACT vocabulary match on the other side are mapped.
 *     usable_while_cc's tokens (stun/fear/flee/confuse/charm/horror) and dr_category's eight
 *     values come from different vocabularies; only 'Stun' means the same real concept in both.
 *     Disorient -> 'confuse' is a guess, so it stays unmapped rather than guessed.
 *   - The "is this genuinely player-pressed" gap ClaudesCounters flagged as unclosed (Weakened
 *     Soul, Focused Will and friends are neither is_passive nor not_in_spellbook, so its hygiene
 *     filter could not exclude them) IS now closed, by narrowToPressable() below — see that
 *     method for the signal and the measured safety property. It was never missing data; it was
 *     three existing signals nobody had combined.
 *
 * STILL OPEN, and deliberately not resolved here: the `usable_while` mechanism is far weaker
 * evidence than the other three, and its raw pool is the source of ~75% of all rows. Blizzard's
 * own "Allow While Stunned (163)" attribute is set on things like Living Bomb and Freezing Trap,
 * where it plainly describes the AURA persisting through a stun rather than the caster being
 * able to press the button while stunned. The attribute is real (SpellDataFileParser reads it by
 * exact code); what is ambiguous is its subject. Resolving that needs game knowledge, not more
 * parsing, so all four mechanisms are stored and SpellCounter::MECHANISM_CONFIDENCE ranks them —
 * the UI separates high-confidence counters from this one rather than silently dropping it.
 */
class SpellCounterIndexer
{
    /** @see the class docblock for why this map is exact-match-only and deliberately tiny. */
    private const DR_CATEGORY_TO_CC_TOKEN = [
        'Stun' => 'stun',
    ];

    /** Same discipline against ModuleSpellReferenceService::MECHANIC_IMMUNITY_CODE_MAP's names. */
    private const DR_CATEGORY_TO_IMMUNITY_MECHANIC = [
        'Stun' => 'Stun',
        'Silence' => 'Silence',
        'Incapacitate' => 'Incapacitate',
    ];

    public function __construct(private readonly ModuleSpellReferenceService $service) {}

    /**
     * Rebuilds the whole index for one patch. Replace-not-append: every row for the patch's CC
     * spells is deleted first, so a spell that stops being CC (or a counter that stops
     * qualifying) actually disappears instead of lingering. Same posture as
     * RoadmapService::persistStagesForUser() and TalentSelectionService::syncPvpChoices().
     *
     * @return array{counterable: int, rows: int} counts, for the caller to report
     */
    public function rebuild(?Patch $patch = null): array
    {
        $patch ??= Patch::where('is_current', true)->first();

        if ($patch === null) {
            return ['counterable' => 0, 'rows' => 0];
        }

        $availableSpellIds = SpellClassAvailability::whereHas('spell', fn ($q) => $q->where('patch_id', $patch->id))
            ->pluck('spell_id')
            ->unique();

        $ccSpells = $this->pressableCcSpells($patch, $availableSpellIds);

        DB::table('spell_counters')
            ->whereIn('countered_spell_id', Spell::where('patch_id', $patch->id)->select('id'))
            ->delete();

        if ($ccSpells->isEmpty()) {
            return ['counterable' => 0, 'rows' => 0];
        }

        $pools = $this->buildPools($patch, $availableSpellIds);

        $rows = [];
        $now = now();

        foreach ($ccSpells as $cc) {
            foreach ($this->countersFor($cc, $pools) as $counter) {
                $rows[] = [
                    'countered_spell_id' => $cc->id,
                    'counter_spell_id' => $counter['spell']->id,
                    'mechanism' => $counter['mechanism'],
                    'detail' => $counter['detail'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('spell_counters')->insertOrIgnore($chunk);
        }

        return ['counterable' => $ccSpells->count(), 'rows' => count($rows)];
    }

    /**
     * Every counter of one CC spell, as unsaved rows. Public so a caller can ask the question for
     * a single spell without touching the table (used by the test suite to prove the stored index
     * agrees with a live computation).
     *
     * @param  array<string, mixed>  $pools  from buildPools()
     * @return array<int, array{spell: Spell, mechanism: string, detail: ?string}>
     */
    public function countersFor(Spell $cc, array $pools): array
    {
        $found = [];

        $token = self::DR_CATEGORY_TO_CC_TOKEN[$cc->dr_category] ?? null;
        if ($token !== null) {
            foreach ($pools['usableWhile'][$token] ?? [] as $spell) {
                if ($spell->id !== $cc->id) {
                    $found[] = ['spell' => $spell, 'mechanism' => SpellCounter::MECHANISM_USABLE_WHILE, 'detail' => $token];
                }
            }
        }

        $mechanic = self::DR_CATEGORY_TO_IMMUNITY_MECHANIC[$cc->dr_category] ?? null;
        if ($mechanic !== null) {
            foreach ($pools['immunityByMechanic'][$mechanic] ?? [] as $spell) {
                if ($spell->id !== $cc->id) {
                    // A talent-gated immunity is a real counter but not an unconditional one —
                    // the base ability alone does nothing (Fade without Phase Shift is just a
                    // threat drop). It gets its own mechanism so the UI can say so, rather than
                    // being silently presented alongside immunities every owner of the spell has.
                    // Omitting them instead was rejected: it would drop the single most important
                    // defensive answers in PvP from the counters list entirely.
                    $found[] = [
                        'spell' => $spell,
                        'mechanism' => $spell->cc_immunity_gating_spell_id !== null
                            ? SpellCounter::MECHANISM_IMMUNITY_TALENT
                            : SpellCounter::MECHANISM_IMMUNITY_MECHANIC,
                        'detail' => $mechanic,
                    ];
                }
            }
        }

        // Dodge/parry only applies to a Physical-school ability that also doesn't bypass the
        // roll — a magic-school CC never enters the dodge/parry/block table at all, regardless of
        // that flag. Real bug found 2026-09-04: Fear/Howl of Terror were showing Evasion as a
        // counter despite being School: Shadow.
        if ($cc->school === 'Physical' && ! $cc->bypasses_active_defense) {
            foreach ($pools['dodgeParry'] as $spell) {
                if ($spell->id !== $cc->id) {
                    $found[] = ['spell' => $spell, 'mechanism' => SpellCounter::MECHANISM_DODGE_PARRY, 'detail' => 'Physical'];
                }
            }
        }

        foreach ($pools['schoolImmunity'] as $spell) {
            if ($spell->id !== $cc->id && $this->service->grantsSchoolImmunityFor($spell, $cc->school)) {
                $found[] = ['spell' => $spell, 'mechanism' => SpellCounter::MECHANISM_IMMUNITY_SCHOOL, 'detail' => $cc->school];
            }
        }

        return $found;
    }

    /**
     * The CC abilities this index is about: the ones a player actually presses, one row per
     * ability rather than one per internal spell_id copy.
     *
     * Shared with ClaudesCounters so the page and the index cannot disagree about which spells
     * exist — the bug this method was extracted for was exactly that kind of disagreement, in the
     * other direction (see below).
     *
     * dr_category is deliberately curated onto a CC's real AURA spell_id as well as onto the
     * ability that applies it: the aura id is what SPELL_AURA_APPLIED carries in a combat log, so
     * FindCcChains and CcTargetingAnalyzer need it to recognise control that actually landed
     * (hence the many "the REAL aura spell_id" lines in cc-synergies-overrides.txt). That is
     * correct for those pipelines, and untagging the aura copies to fix this page would break
     * them — so the narrowing happens here, at the point of use, and the curation is untouched.
     *
     * Without it the pool was every tagged copy — 157 — so a passive's effect aura got its own
     * counters list and rendered as though it were an ability, while being correctly absent from
     * WoW Comps' Crowd Control, which builds from the spec kit. Reported 2026-09-07 as exactly
     * that contradiction: Frost DK's "Absolute Zero" — the 3s freeze aura the Absolute Zero
     * PASSIVE adds to Frostwyrm's Fury, not a button — carried 44 counters here and appeared
     * nowhere in WoW Comps. 157 drops to 105: ~21 passive-granted auras and defunct records go,
     * and ~17 same-name pairs (Fear, Intimidation, Storm Bolt, Holy Word: Chastise, Freezing
     * Trap, Ring of Frost and friends each listed BOTH their aura copy and their real pressable
     * copy) collapse onto the pressable copy.
     *
     * A CC that survives but has no known counter is still returned — "no verified answer" is a
     * real answer this page shows deliberately, and is not the same as "not an ability".
     *
     * @param  ?Collection<int, int>  $availableSpellIds  pass the caller's own copy to avoid re-querying
     * @return Collection<int, Spell>
     */
    public function pressableCcSpells(Patch $patch, ?Collection $availableSpellIds = null): Collection
    {
        $availableSpellIds ??= SpellClassAvailability::whereHas('spell', fn ($q) => $q->where('patch_id', $patch->id))
            ->pluck('spell_id')
            ->unique();

        return $this->narrowToPressable(
            Spell::where('patch_id', $patch->id)
                ->whereIn('id', $availableSpellIds)
                ->whereNotNull('dr_category')
                ->get()
        );
    }

    /**
     * The four candidate pools, each computed once. Deliberately not one query per CC spell —
     * same "precompute the pool, don't N+1" discipline as ArenaLogService::preloadPrioritySpells().
     *
     * @param  Collection<int, int>  $availableSpellIds
     * @return array<string, mixed>
     */
    public function buildPools(Patch $patch, Collection $availableSpellIds): array
    {
        // Excludes internal/hidden duplicate records and unlearned entries — the same hygiene
        // filter verifiedBaselineAbilityIds()/explicitBaselineCooldownAbilityIds() already use.
        $hygiene = fn ($q) => $q->where('is_passive', false)
            ->where('not_in_spellbook', false)
            ->where('name', 'not like', '%(desc=%');

        $usableWhile = [];
        foreach (array_unique(array_values(self::DR_CATEGORY_TO_CC_TOKEN)) as $token) {
            $usableWhile[$token] = Spell::where('patch_id', $patch->id)
                ->whereIn('id', $availableSpellIds)
                ->where('usable_while_cc', 'like', "%{$token}%")
                ->tap($hygiene)
                ->get();
        }

        // Two disjoint sources, unioned. The effects branch is the original one. The override
        // branch is what makes a PvP talent's immunity reachable AT ALL: 220 of 250 PvP talents
        // have zero spell_effects rows (measured 2026-09-07), so whereHas('effects') could never
        // match them no matter what was curated — this query was the actual blocker, not the
        // matching logic below it.
        //
        // The override branch deliberately skips $hygiene. That filter exists to keep unverified
        // junk out of an auto-derived pool, and a hand-curated line in cc-immunity-overrides.txt
        // is exactly the verification it stands in for — so applying it here would only ever
        // discard a fact a human explicitly asserted. It is also load-bearing rather than
        // theoretical: the `name not like '%(desc=%'` clause would otherwise drop Evoker's
        // Obsidian Scales (desc=Black) and Verdant Embrace (desc=Green), where that suffix is
        // legitimate per-dragonflight-colour naming rather than a duplicate marker (the same
        // Evoker-specific trap MurlokTalentImportService::normalizeSpellName() already handles).
        $immunityCandidates = Spell::where('patch_id', $patch->id)
            ->whereIn('id', $availableSpellIds)
            ->where(fn ($q) => $q
                ->where(fn ($effectBranch) => $effectBranch
                    ->whereHas('effects', fn ($e) => $e->where('type', 'Mechanic Immunity')->whereNotNull('misc_value'))
                    ->tap($hygiene))
                ->orWhereNotNull('grants_cc_immunity_override'))
            ->with('effects')
            ->get();

        $immunityByMechanic = [];
        foreach ($immunityCandidates as $candidate) {
            foreach ($this->service->ccImmunityFor($candidate) as $mechanic) {
                $immunityByMechanic[$mechanic][] = $candidate;
            }
        }

        $dodgeParry = Spell::where('patch_id', $patch->id)
            ->whereIn('id', $availableSpellIds)
            ->whereHas('effects', fn ($q) => $q->whereIn('type', ['Modify Dodge%', 'Modify Parry%'])->where('base_value', '>', 0))
            ->tap($hygiene)
            ->get();

        // Pooled by NAME, not by a direct whereHas on the candidate itself: Cloak of Shadows'
        // own castable copy (31224) carries none of the School Immunity effects, it only triggers
        // a separate hidden spell (35729) that does. Pooling by name and then letting
        // grantsSchoolImmunityFor()'s own sibling fallback decide per candidate is what actually
        // surfaces Cloak of Shadows as a counter at all.
        $schoolImmunityNames = Spell::where('patch_id', $patch->id)
            ->whereIn('id', SpellEffect::where('type', 'School Immunity')->whereNotNull('affected_schools')->select('spell_id'))
            ->pluck('name')
            ->unique();

        $schoolImmunity = Spell::where('patch_id', $patch->id)
            ->whereIn('id', $availableSpellIds)
            ->whereIn('name', $schoolImmunityNames)
            ->tap($hygiene)
            ->with('effects')
            ->get();

        return [
            'usableWhile' => array_map(fn ($pool) => $this->narrowToPressable(collect($pool)), $usableWhile),
            'immunityByMechanic' => array_map(fn ($pool) => $this->narrowToPressable(collect($pool)), $immunityByMechanic),
            'dodgeParry' => $this->narrowToPressable($dodgeParry),
            'schoolImmunity' => $this->narrowToPressable($schoolImmunity),
        ];
    }

    /**
     * Narrows a raw candidate pool to abilities a player actually presses, then collapses
     * same-name internal duplicates down to one.
     *
     * Both halves close real, measured noise. Before this, Kidney Shot resolved to 401 "counters"
     * — a list containing Weakened Soul, Echo of Light, Sin and Punishment and a literal
     * "GGO - Test - Void Blink", plus five separate copies of Metamorphosis. That is not an answer
     * to "what counters Kidney Shot"; it is the raw pool with nothing asked of it. After: 41.
     *
     * The pressability test is "has a real cooldown or charges, OR is a real talent-tree entry /
     * PvP talent / verified baseline override". The three linkage sources are the same ones
     * TalentSelectionService already treats as evidence that a spell is genuinely part of a
     * player's kit; combining them is what makes this a lookup against known-real data rather
     * than another inference heuristic of the kind this project has had to revert before
     * (Mind Sear, alwaysAvailableAbilityIds()).
     *
     * The safety property that makes it trustworthy was measured, not assumed: across the full
     * 407-spell counter pool, the filter drops ZERO spells that have a real cooldown. Every
     * dropped row is a proc, a passive aura, a lingering debuff record or a test spell. A real
     * defensive counter without a cooldown is theoretically possible and would be wrongly
     * dropped — none exists in the current dataset, and the linkage clause catches the plausible
     * shape of one (a baseline defensive that is a real verified-override entry).
     *
     * Dedupe prefers a cooldown-bearing copy, then a kit-linked one, then the lowest id — the
     * same tiering resolveSpellByName()/preferSelectedPerName() already use for exactly this
     * "one real ability split across several internal spell_id records" problem.
     *
     * @param  Collection<int, Spell>  $pool
     * @return Collection<int, Spell>
     */
    private function narrowToPressable(Collection $pool): Collection
    {
        $linked = $this->kitLinkedSpellIds();

        return $pool
            ->filter(fn (Spell $s) => $s->cooldown_seconds !== null || $s->charges !== null || $linked->has($s->id))
            ->groupBy(fn (Spell $s) => $s->display_name)
            ->map(fn (Collection $copies) => $copies
                ->sortBy([
                    fn (Spell $s) => $s->cooldown_seconds !== null ? 0 : 1,
                    fn (Spell $s) => $linked->has($s->id) ? 0 : 1,
                    fn (Spell $s) => $s->id,
                ])
                ->first())
            ->values();
    }

    /**
     * Every spell id reachable as a real talent-tree entry, PvP talent, or hand-verified baseline
     * override — computed once per instance, since narrowToPressable() is called for every pool.
     *
     * @return Collection<int, int> flipped, so has() is an O(1) key lookup
     */
    private function kitLinkedSpellIds(): Collection
    {
        return $this->kitLinked ??= DB::table('talent_node_entries')->distinct()->pluck('spell_id')
            ->merge(DB::table('pvp_talents')->distinct()->pluck('spell_id'))
            ->merge(DB::table('spell_class_availability')->where('source', 'verified_override')->distinct()->pluck('spell_id'))
            ->unique()
            ->flip();
    }

    /** @var ?Collection<int, int> */
    private ?Collection $kitLinked = null;
}
