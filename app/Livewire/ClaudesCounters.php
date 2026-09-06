<?php

namespace App\Livewire;

use App\Http\Services\ModuleSpellReferenceService;
use App\Models\PageViewEvent;
use App\Models\Spell;
use App\Models\SpellClassAvailability;
use App\Models\SpellEffect;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * "Spell Counters" — rewritten 2026-09-04, dropping the two-spec matchup picker entirely
 * (auto-pairing, closest-equivalent matching, the DuelSimulatorService pressure bar) per direct
 * instruction: no picker, just a plain per-class list of every real CC ability ("counterable
 * spell") and its real counters ("counter spells"), found GLOBALLY across every class rather than
 * scoped to one chosen opponent. See git history for the prior matchup-comparison version if that
 * functionality is ever wanted back — DuelSimulatorService/wow:simulate-duel themselves are
 * untouched, only this page's use of them was removed.
 *
 * A genuine simplification, not just a smaller UI: every fact this page needs (dr_category,
 * usable_while_cc, ccImmunityGrantedBy() via spell_effects, bypasses_active_defense, school) is
 * build-independent — none of it depends on which talents a viewer has selected. That means this
 * page needs NO TalentSelectionService/SpecKitComputer resolution at all, unlike the matchup
 * version it replaces — plain, cheap, precomputed-pool DB queries, same "materialize what's
 * build-independent" principle established earlier this session for spells.category etc.
 */
class ClaudesCounters extends Component
{
    /**
     * Only the dr_category <-> "usable while X" token correspondences that are an EXACT, already-
     * verified match (see CLAUDE.md's CC-immunity investigation, 2026-09-02/03) — never a guessed
     * mapping. usable_while_cc's tokens (stun/fear/flee/confuse/charm/horror) come from a
     * completely different vocabulary than dr_category's 8 values (Stun/Silence/Incapacitate/
     * Disorient/Root/Knockback/Disarm/Slow), and only 'Stun' has a token that means exactly the
     * same real-game concept on both sides. Guessing e.g. Disorient->'confuse' would be inventing
     * a fact this project has repeatedly had to catch and revert elsewhere (Mind Sear, the
     * alwaysAvailableAbilityIds() heuristic) — left unmapped rather than guessed.
     */
    private const DR_CATEGORY_TO_CC_TOKEN = [
        'Stun' => 'stun',
    ];

    /**
     * Same discipline for dr_category <-> ModuleSpellReferenceService::MECHANIC_IMMUNITY_CODE_MAP
     * mechanic names — only exact string matches (both use the same word for the same real
     * mechanic), no fuzzy correspondence invented for the rest.
     */
    private const DR_CATEGORY_TO_IMMUNITY_MECHANIC = [
        'Stun' => 'Stun',
        'Silence' => 'Silence',
        'Incapacitate' => 'Incapacitate',
    ];

    public function mount(): void
    {
        PageViewEvent::log('claudes_counters');
    }

    /**
     * Every real CC ability ("counterable spell") in the current patch, grouped by class, each
     * paired with its real counters found ACROSS EVERY CLASS (not scoped to one opponent — see
     * this class's own docblock for why removing the matchup picker made this both possible and
     * simpler). "Real" means actually available to some class/spec via spell_class_availability —
     * a spell with dr_category set but no real availability row is dead/internal data, excluded.
     *
     * Candidate counter pools (usable-while-stunned, grants-immunity-by-mechanic, dodge/parry-
     * boosting) are each computed ONCE up front via a handful of queries, then matched in memory
     * per CC spell — deliberately not one query per CC spell (227 in the current dataset), same
     * "precompute the pool, don't N+1" discipline as ArenaLogService::preloadPrioritySpells().
     *
     * @return Collection<string, Collection<int, array{spell: Spell, usableWhileThis: Collection, grantsImmunity: Collection, dodgeParryBoost: Collection, schoolImmunity: Collection, hasAnyCounter: bool}>>
     */
    public function getCounterableByClassProperty(): Collection
    {
        $service = app(ModuleSpellReferenceService::class);

        $availableSpellIds = SpellClassAvailability::whereHas('spell', fn ($q) => $q->whereHas('patch', fn ($q2) => $q2->where('is_current', true)))
            ->pluck('spell_id')
            ->unique();

        // Same hygiene filter already established elsewhere in this codebase for baseline-ability
        // noise (verifiedBaselineAbilityIds()/explicitBaselineCooldownAbilityIds()) — excludes
        // internal/hidden duplicate records and unlearned entries. Real, known limitation this
        // does NOT close (documented rather than silently claimed fixed): a handful of usable_
        // while_cc-flagged spells are auto-triggered debuffs/procs, not something a player
        // deliberately presses as a counter (e.g. Weakened Soul, Focused Will) — neither is_
        // passive nor not_in_spellbook, so this filter can't distinguish them from a real ability.
        // Flagged, not guessed around — a further "is this genuinely player-pressed" signal isn't
        // captured anywhere in this schema yet.
        $hygieneFilter = fn ($q) => $q->where('is_passive', false)
            ->where('not_in_spellbook', false)
            ->where('name', 'not like', '%(desc=%');

        // --- usable-while-X pools, one per distinct token actually used above ---
        $usableWhilePools = [];
        foreach (array_unique(array_values(self::DR_CATEGORY_TO_CC_TOKEN)) as $token) {
            $usableWhilePools[$token] = Spell::whereIn('id', $availableSpellIds)
                ->where('usable_while_cc', 'like', "%{$token}%")
                ->tap($hygieneFilter)
                ->get();
        }

        // --- grants-immunity pool, grouped by real mechanic name ---
        $immunityCandidates = Spell::whereIn('id', $availableSpellIds)
            ->whereHas('effects', fn ($q) => $q->where('type', 'Mechanic Immunity')->whereNotNull('misc_value'))
            ->tap($hygieneFilter)
            ->with('effects')
            ->get();
        $immunityByMechanic = [];
        foreach ($immunityCandidates as $candidate) {
            foreach ($service->ccImmunityGrantedBy($candidate) as $mechanic) {
                $immunityByMechanic[$mechanic][] = $candidate;
            }
        }

        // --- dodge/parry-boost pool (Modify Dodge%/Modify Parry%, real nonzero magnitude) ---
        $dodgeParryPool = Spell::whereIn('id', $availableSpellIds)
            ->whereHas('effects', fn ($q) => $q->whereIn('type', ['Modify Dodge%', 'Modify Parry%'])->where('base_value', '>', 0))
            ->tap($hygieneFilter)
            ->get();

        // --- school-immunity pool (Cloak of Shadows/Divine Shield/Blessing of Protection-style —
        // see ModuleSpellReferenceService::grantsSchoolImmunityFor()'s own docblock). Applies to
        // ANY CC spell with a real `school`, not gated by the narrow dr_category map above.
        //
        // Matched by NAME, not a direct whereHas('effects', ...) on the candidate itself — Cloak
        // of Shadows' own displayed/available copy (31224) carries none of the School Immunity
        // effects itself, it only triggers a separate hidden spell_id (35729) that does. Pooling
        // by name (any patch-scoped copy with the effect, regardless of whether THAT copy is
        // itself available) then letting grantsSchoolImmunityFor()'s own sibling fallback resolve
        // the real answer per-candidate is what actually surfaces Cloak of Shadows here.
        $schoolImmunityNames = Spell::whereIn('id', SpellEffect::where('type', 'School Immunity')->whereNotNull('affected_schools')->pluck('spell_id'))
            ->whereHas('patch', fn ($q) => $q->where('is_current', true))
            ->pluck('name')
            ->unique();
        $schoolImmunityPool = Spell::whereIn('id', $availableSpellIds)
            ->whereIn('name', $schoolImmunityNames)
            ->tap($hygieneFilter)
            ->with('effects')
            ->get();

        $ccSpells = Spell::whereIn('id', $availableSpellIds)
            ->whereNotNull('dr_category')
            ->with(['classAvailability.gameClass'])
            ->orderBy('name')
            ->get();

        $grouped = [];
        foreach ($ccSpells as $spell) {
            $drCategory = $spell->dr_category;

            $ccToken = self::DR_CATEGORY_TO_CC_TOKEN[$drCategory] ?? null;
            $usableWhileThis = $ccToken === null
                ? collect()
                : ($usableWhilePools[$ccToken] ?? collect())->reject(fn ($s) => $s->id === $spell->id)->values();

            $immunityMechanic = self::DR_CATEGORY_TO_IMMUNITY_MECHANIC[$drCategory] ?? null;
            $grantsImmunity = $immunityMechanic === null
                ? collect()
                : collect($immunityByMechanic[$immunityMechanic] ?? [])->reject(fn ($s) => $s->id === $spell->id)->values();

            // Dodge/parry only applies to a Physical-school ability that also doesn't bypass the
            // roll — a magic-school CC never enters the dodge/parry/block table at all, regardless
            // of that flag (real bug found and fixed 2026-09-04 in the matchup version this
            // replaces — Fear/Howl of Terror were showing Evasion as a counter despite being
            // School: Shadow).
            $dodgeParryBoost = ($spell->school === 'Physical' && !$spell->bypasses_active_defense)
                ? $dodgeParryPool->reject(fn ($s) => $s->id === $spell->id)->values()
                : collect();

            $schoolImmunity = $schoolImmunityPool
                ->reject(fn ($s) => $s->id === $spell->id)
                ->filter(fn ($s) => $service->grantsSchoolImmunityFor($s, $spell->school))
                ->values();

            $hasAnyCounter = $usableWhileThis->isNotEmpty() || $grantsImmunity->isNotEmpty()
                || $dodgeParryBoost->isNotEmpty() || $schoolImmunity->isNotEmpty();

            $row = compact('spell', 'usableWhileThis', 'grantsImmunity', 'dodgeParryBoost', 'schoolImmunity', 'hasAnyCounter');

            foreach ($spell->classAvailability as $availability) {
                $className = $availability->gameClass->name ?? '(unassigned)';
                $grouped[$className][$spell->id] = $row; // keyed by spell id — collapses a spell with several availability rows for the same class to one entry
            }
        }

        ksort($grouped);

        return collect($grouped)->map(fn (array $rows) => collect($rows)
            ->sortByDesc('hasAnyCounter')
            ->values());
    }

    public function render()
    {
        return view('livewire.claudes-counters', [
            'counterableByClass' => $this->counterableByClass,
        ])->layout('layouts.app', [
            'title' => 'Spell Counters | MindCollector',
            'description' => 'Every real crowd-control ability in the game, grouped by class, alongside what actually counters it — usable-while-CC\'d abilities, immunity-granting cooldowns, and real dodge/parry counters.',
        ]);
    }
}
