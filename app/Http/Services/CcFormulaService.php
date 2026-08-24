<?php

namespace App\Http\Services;

use App\Models\GameClass;
use App\Models\Specialization;
use App\Models\Spell;
use Illuminate\Support\Collection;

/**
 * Core CC-chain-building algorithm, extracted 2026-08-23 from wow:cc-formula (the CLI tool it
 * was built for and verified against — see that command's own docblock for the full rationale
 * behind every rule below, none of it duplicated here) so the same logic can be reused by the
 * live WoW Comps Crowd Control page without drifting from the CLI tool's already-verified
 * behavior. The command is now a thin formatter over this service's structured return value;
 * WowComps calls the same method directly.
 *
 * Returns a plain array structure (spell models + labels + notes), not printed text — the two
 * callers (CLI, Blade) format it differently but must never compute it differently.
 *
 * TWO ENTRY POINTS, one shared computation — added 2026-08-24 after a real performance concern
 * (WoW Comps already had a documented history of heavy per-render cost — see WowComps's own
 * "same-day performance fix" note — and this tool's first wire-up made it worse by re-deriving
 * each spec's full kit from scratch via TalentSelectionService + a fresh Spell query, DUPLICATING
 * work getCompProperty() already did and cached for that exact same spec):
 * - buildChain() — the original CLI-facing entry point. Given (class, spec) pairs, queries each
 *   spec's full kit itself. Still used by wow:cc-formula, which has no pre-loaded comp data to
 *   reuse.
 * - buildChainFromComp() — added for WowComps. Given the SAME $comp array structure
 *   WowComps::getCompProperty() already computed (and Redis-caches per spec for 6 hours), reuses
 *   those already-loaded Spell models directly — zero additional spell queries. The two produce
 *   identical results for the same spec selection (both draw from the same underlying universe:
 *   every real talent-tree entry + PvP talent + verified/explicit-cooldown baseline ability), the
 *   only difference is where that universe's data came from.
 * Both funnel into the same private computeChain() so the actual algorithm can never drift
 * between the two callers.
 *
 * SECOND GO, added 2026-08-24 (direct instruction, following a real domain-expert walkthrough
 * of why a single static chain isn't enough): both entry points now return TWO chains, not one —
 * 'primary' (the original algorithm, unchanged) and 'nextGo' (what's realistically available
 * ~NEXT_GO_HORIZON_SECONDS later, once the primary chain's picks have had time to go on
 * cooldown). This is deliberately the SIMPLER of two designs discussed: a flat "what's available
 * by time T" snapshot, not a full wait-vs-take scheduling simulator (the harder version — should
 * you hold a weak instant option for a stronger one that's about to come off cooldown — is a
 * genuinely different, bigger problem, left for later). computeNextGoChain()'s own docblock has
 * the full exclusion rules.
 */
class CcFormulaService
{
    private const HARD_CC_CATEGORIES = ['Stun', 'Silence', 'Incapacitate', 'Disorient'];

    /**
     * The real, already-confirmed PvP DR reset window this project uses elsewhere (see
     * CLAUDE.md's "DR reset window length" note — domain-expert-confirmed 20s for the current
     * patch, not the looser "30s" floated earlier in the same conversation this feature comes
     * from). A "next go" chain assumes this much real time has passed since the primary chain
     * started, so every DR category is treated as fully reset — the SAME deliberate simplifying
     * assumption as treating the whole primary chain as happening at one instant (t=0): this
     * tool has no per-step match-clock, so "20s after the LAST primary step" and "20s after the
     * FIRST" are not distinguished. Flagged, not hidden.
     */
    private const NEXT_GO_HORIZON_SECONDS = 20.0;

    private const HEALER_SLUGS = [
        ['druid', 'restoration'], ['shaman', 'restoration'], ['priest', 'holy'], ['priest', 'discipline'],
        ['paladin', 'holy'], ['monk', 'mistweaver'], ['evoker', 'preservation'],
    ];

    public function __construct(
        private TalentSelectionService $talentService,
        private CcChainStatsService $chainStats,
    ) {
    }

    /**
     * @param  array<int, array{class: GameClass, spec: Specialization}>  $specEntries  exactly 3 entries
     * @return array{compLabel: string, primary: array, nextGo: array} each of primary/nextGo shaped per computeChain()'s own docblock
     */
    public function buildChain(array $specEntries, bool $useRealData = true): array
    {
        $compLabel = collect($specEntries)->map(fn ($e) => "{$e['spec']->name} {$e['class']->name}")->implode(' / ');

        $pool = $this->dedupPool($this->queryPool($specEntries, restrictToHardCc: true));
        $setupPool = $this->dedupPool($this->queryPool($specEntries, restrictToHardCc: false));

        $primary = $this->computeChain($pool, $setupPool, $useRealData);
        $nextGo = $this->computeNextGoChain($pool, $setupPool, $primary, $useRealData);

        return ['compLabel' => $compLabel, 'primary' => $primary, 'nextGo' => $nextGo];
    }

    /**
     * WowComps-facing entry point — see this class's own docblock for why this exists alongside
     * buildChain(). $comp is the exact array WowComps::getCompProperty() returns: one entry per
     * slot, each with 'class', 'spec', and 'entries' (every real talent/PvP/baseline spell for
     * that spec, already loaded — the same universe queryPool() below would otherwise re-derive).
     *
     * @param  array<int, array{label: string, class: ?GameClass, spec: ?Specialization, entries: array}>  $comp  exactly 3 members, each with a non-null spec
     */
    public function buildChainFromComp(array $comp, bool $useRealData = true): array
    {
        $compLabel = collect($comp)->map(fn ($m) => "{$m['spec']->name} {$m['class']->name}")->implode(' / ');

        $pool = collect();
        $setupPool = collect();

        foreach ($comp as $member) {
            $class = $member['class'];
            $spec = $member['spec'];
            $healer = in_array([$class->slug, $spec->slug], self::HEALER_SLUGS, true);
            $label = "{$spec->name} {$class->name}";

            foreach ($member['entries'] as $entry) {
                $spell = $entry['spell'];

                if ($spell->dr_category === null) {
                    continue;
                }

                $row = ['spell' => $spell, 'label' => $label, 'isHealer' => $healer];
                $setupPool->push($row);

                if (in_array($spell->dr_category, self::HARD_CC_CATEGORIES, true)) {
                    $pool->push($row);
                }
            }
        }

        $pool = $this->dedupPool($pool);
        $setupPool = $this->dedupPool($setupPool);

        $primary = $this->computeChain($pool, $setupPool, $useRealData);
        $nextGo = $this->computeNextGoChain($pool, $setupPool, $primary, $useRealData);

        return ['compLabel' => $compLabel, 'primary' => $primary, 'nextGo' => $nextGo];
    }

    /**
     * The actual algorithm — identical regardless of where $pool/$setupPool's spells came from.
     * See this class's own docblock and wow:cc-formula's for the full rule-by-rule rationale.
     *
     * @return array{poolEmpty: bool, sequence: array<int, array>, totalDuration: float, missingDuration: bool, killTarget: array|null, noKillTargetMessage: string|null, leftover: array<int, array>}
     */
    private function computeChain(Collection $pool, Collection $setupPool, bool $useRealData): array
    {
        if ($pool->isEmpty()) {
            return [
                'poolEmpty' => true,
                'sequence' => [],
                'totalDuration' => 0.0,
                'missingDuration' => false,
                'killTarget' => null,
                'noKillTargetMessage' => null,
                'leftover' => [],
            ];
        }

        $openerRates = $this->chainStats->openerRates();
        $transitionRates = $this->chainStats->transitionRates();

        $scoreFn = function (array $entry, ?string $previousName) use ($openerRates, $transitionRates, $useRealData): array {
            $spell = $entry['spell'];
            $name = $spell->display_name;

            $realRate = 0.0;
            if ($useRealData) {
                $realRate = $previousName === null
                    ? ($openerRates[$name] ?? 0.0)
                    : ($transitionRates[$previousName][$name] ?? 0.0);
            }

            $ctScore = match ($spell->cast_type) {
                'instant' => 0,
                'cast' => 1,
                default => 2,
            };
            $healerScore = $entry['isHealer'] ? 1 : 0;
            $durationScore = -(float) ($spell->pvp_duration_seconds ?? 0);

            return [-$realRate, $ctScore * 10 + $healerScore, $durationScore];
        };

        $byCategory = $pool->groupBy(fn ($e) => $e['spell']->dr_category);
        $usedCategories = [];
        $rawSequence = [];

        while (true) {
            $available = $byCategory->reject(fn ($group, $cat) => in_array($cat, $usedCategories, true));

            if ($available->isEmpty()) {
                break;
            }

            $previousName = $rawSequence === [] ? null : end($rawSequence)['spell']->display_name;
            $currentScore = fn (array $e) => $scoreFn($e, $previousName);

            $eligibleByCategory = $previousName === null
                ? $available
                : $available
                    ->map(fn ($group) => $group->reject(fn ($e) => $e['spell']->requires_stealth || $e['spell']->requires_target_out_of_combat))
                    ->filter(fn ($group) => $group->isNotEmpty());

            if ($eligibleByCategory->isEmpty()) {
                break;
            }

            $bestPerCategory = $eligibleByCategory->map(fn ($group, $cat) => $group->sortBy($currentScore)->first());
            $chosenCategory = $bestPerCategory->sortBy($currentScore)->keys()->first();
            $chosen = $bestPerCategory[$chosenCategory];

            $alreadyUsedLabels = array_column($rawSequence, 'label');
            if (in_array($chosen['label'], $alreadyUsedLabels, true) && $this->isMeleeRange($chosen['spell']) === true) {
                $rangedAlternate = $eligibleByCategory[$chosenCategory]
                    ->filter(fn ($e) => $e['label'] === $chosen['label']
                        && $e['spell']->id !== $chosen['spell']->id
                        && $this->isMeleeRange($e['spell']) === false)
                    ->sortBy($currentScore)
                    ->first();

                if ($rangedAlternate) {
                    $chosen = $rangedAlternate;
                }
            }

            $rawSequence[] = $chosen;
            $usedCategories[] = $chosenCategory;
        }

        $sequence = [];
        $totalDuration = 0.0;
        $missingDuration = false;

        foreach ($rawSequence as $i => $entry) {
            $spell = $entry['spell'];
            $name = $spell->display_name;
            $previousName = $i === 0 ? null : $rawSequence[$i - 1]['spell']->display_name;
            $realRate = $previousName === null ? ($openerRates[$name] ?? null) : ($transitionRates[$previousName][$name] ?? null);
            $realRateLabel = match (true) {
                !$useRealData => 'real data ignored',
                $realRate !== null && $previousName === null => round($realRate * 100, 1).'% real opener rate',
                $realRate !== null => round($realRate * 100, 1)."% real transition rate from {$previousName}",
                default => 'no real data for this pick',
            };

            $dur = $spell->pvp_duration_seconds;
            if ($dur !== null) {
                $totalDuration += (float) $dur;
            } else {
                $missingDuration = true;
            }

            $stealthNote = match (true) {
                $spell->requires_stealth && $spell->requires_target_out_of_combat => 'requires stealth + target out of combat — opener only',
                $spell->requires_stealth => 'requires stealth — opener only',
                default => null,
            };

            $neededCategory = $spell->pairs_with_category;
            $requirementNote = null;

            if ($neededCategory !== null) {
                $satisfiedByEarlierStep = null;
                for ($j = 0; $j < $i; $j++) {
                    if ($rawSequence[$j]['spell']->dr_category === $neededCategory) {
                        $satisfiedByEarlierStep = ['index' => $j, 'spell' => $rawSequence[$j]['spell']];
                        break;
                    }
                }

                if ($satisfiedByEarlierStep) {
                    $requirementNote = [
                        'type' => 'satisfied',
                        'satisfiedByStepNumber' => $satisfiedByEarlierStep['index'] + 1,
                        'satisfiedBySpellName' => $satisfiedByEarlierStep['spell']->display_name,
                    ];
                } else {
                    $usedInSequenceIds = array_map(fn ($e) => $e['spell']->id, $rawSequence);
                    $setup = $this->findSetupStep($entry, $setupPool->reject(fn ($e) => in_array($e['spell']->id, $usedInSequenceIds, true)));

                    $requirementNote = $setup
                        ? ['type' => 'setup', 'setupSpell' => $setup['spell'], 'setupLabel' => $setup['label']]
                        : ['type' => 'warning', 'neededCategory' => $neededCategory];
                }
            }

            $alternates = $byCategory[$spell->dr_category]
                ->reject(fn ($e) => $e['spell']->id === $spell->id)
                ->map(fn ($e) => [
                    'spell' => $e['spell'],
                    'label' => $e['label'],
                    'note' => ($e['spell']->requires_stealth || $e['spell']->requires_target_out_of_combat) && $previousName !== null
                        ? 'opener only, not eligible here'
                        : null,
                ])
                ->values()
                ->all();

            $sequence[] = [
                'spell' => $spell,
                'label' => $entry['label'],
                'isHealer' => $entry['isHealer'],
                'stealthNote' => $stealthNote,
                'requirementNote' => $requirementNote,
                'realRateLabel' => $realRateLabel,
                'durationSeconds' => $dur,
                'castType' => $spell->cast_type,
                'rangeLabel' => match ($this->isMeleeRange($spell)) {
                    true => 'melee-range',
                    false => 'ranged',
                    null => 'range unknown',
                },
                'alternates' => $alternates,
            ];
        }

        $usedSpellIds = array_map(fn ($e) => $e['spell']->id, $rawSequence);
        $leftoverPool = $pool->reject(fn ($e) => in_array($e['spell']->id, $usedSpellIds, true));

        $killTargetCandidates = $leftoverPool->filter(fn ($e) => !$e['isHealer']
            && in_array($e['spell']->dr_category, ['Stun', 'Silence'], true)
            && $e['spell']->chain_target !== 'healer'
            && !$e['spell']->requires_stealth
            && !$e['spell']->requires_target_out_of_combat);

        $reserved = $killTargetCandidates->sortBy(fn ($e) => $scoreFn($e, null))->first();
        $killTarget = null;
        $noKillTargetMessage = null;

        if ($reserved) {
            $setup = $this->findSetupStep($reserved, $setupPool->reject(fn ($e) => in_array($e['spell']->id, $usedSpellIds, true)));
            $neededCategory = $reserved['spell']->pairs_with_category;

            $requirementNote = match (true) {
                $setup !== null => ['type' => 'setup', 'setupSpell' => $setup['spell'], 'setupLabel' => $setup['label']],
                $neededCategory !== null => ['type' => 'warning', 'neededCategory' => $neededCategory],
                default => null,
            };

            $reservedOpenerRate = $openerRates[$reserved['spell']->display_name] ?? null;
            $reservedRateLabel = match (true) {
                !$useRealData => 'real data ignored',
                $reservedOpenerRate !== null => round($reservedOpenerRate * 100, 1).'% real opener rate',
                default => 'no real data for this pick',
            };

            $killTarget = [
                'spell' => $reserved['spell'],
                'label' => $reserved['label'],
                'realRateLabel' => $reservedRateLabel,
                'castType' => $reserved['spell']->cast_type,
                'requirementNote' => $requirementNote,
            ];
        } else {
            $noKillTargetMessage = 'Nothing left over for the kill target — every non-breaking CC in this comp already went into the healer-lock sequence above.';
        }

        // Leftover CC, added 2026-08-24 for the web page's two-column layout (chain | leftover)
        // — every genuine hard-CC pool candidate not used in the sequence above and not reserved
        // for the kill target. Deliberately just the hard-CC pool (Stun/Silence/Incapacitate/
        // Disorient) — peels and utility (Root/Knockback/Disarm/Slow) are a different concept
        // shown elsewhere on the page, not "leftover chain candidates." wow:cc-formula's CLI
        // output is unaffected by this — it still prints alternates inline per step, unchanged.
        $leftover = $leftoverPool
            ->reject(fn ($e) => $reserved && $e['spell']->id === $reserved['spell']->id)
            ->map(fn ($e) => [
                'spell' => $e['spell'],
                'label' => $e['label'],
                'stealthNote' => match (true) {
                    $e['spell']->requires_stealth && $e['spell']->requires_target_out_of_combat => 'requires stealth + target out of combat — opener only',
                    $e['spell']->requires_stealth => 'requires stealth — opener only',
                    default => null,
                },
            ])
            ->values()
            ->all();

        return [
            'poolEmpty' => false,
            'sequence' => $sequence,
            'totalDuration' => $totalDuration,
            'missingDuration' => $missingDuration,
            'killTarget' => $killTarget,
            'noKillTargetMessage' => $noKillTargetMessage,
            'leftover' => $leftover,
        ];
    }

    /**
     * Builds the "next go" chain — what's realistically available roughly
     * NEXT_GO_HORIZON_SECONDS after the primary chain, once its own picks have had time to go on
     * cooldown. See this class's own docblock for the overall feature and the deliberate
     * "simpler snapshot, not a full wait-vs-take scheduler" scoping.
     *
     * Exclusion rules for a spell used in the primary chain (sequence steps + the reserved
     * kill-target pick, if any):
     * - cooldown_seconds === null => still available. Confirmed 2026-08-24 against real game
     *   knowledge for every CC-relevant spell checked so far (Sap, Polymorph, Fear all
     *   genuinely have no cooldown — NULL is the correct fact, not a data gap). This project DOES
     *   have a documented counter-example elsewhere (Evoker's Dragonrage/Fire Breath/Living
     *   Flame/Pyre are NULL due to a real, separate capture gap, not "no cooldown") — flagged in
     *   case this default ever needs re-checking for a spec outside what's been verified so far.
     * - cooldown_seconds <= NEXT_GO_HORIZON_SECONDS => available (it's back up by then).
     * - cooldown_seconds > NEXT_GO_HORIZON_SECONDS => excluded (not back up yet) — this is what
     *   correctly removes Blind (120s) from a next-go chain while leaving Cheap Shot (12s) or
     *   Kidney Shot (30s... still excluded at a 20s horizon, correctly) available per their own
     *   real numbers, not a guess.
     * A spell NEVER used in the primary chain is always available — this tool has no visibility
     * into anything outside its own CC-chain scope (a different cooldown spent on damage, a
     * defensive, etc.), so "not used here" is the only fact it can act on.
     *
     * requires_stealth/requires_target_out_of_combat spells are excluded ENTIRELY (not just
     * gated to the opening slot the way the primary chain treats them) — assuming a caster can
     * re-enter stealth within ~20s isn't realistic (Vanish-style re-stealth cooldowns run several
     * minutes), and Sap's real blocker (target combat state) doesn't reset just because time has
     * passed while the target is actively being fought.
     *
     * DR categories reset fully fresh for this computation (computeChain() always starts with no
     * used categories) — correct, since the whole premise of "next go" is that enough time (the
     * real confirmed 20s reset window) has passed.
     */
    private function computeNextGoChain(Collection $pool, Collection $setupPool, array $primaryChain, bool $useRealData): array
    {
        if ($primaryChain['poolEmpty']) {
            return $primaryChain;
        }

        $usedSpellIds = collect($primaryChain['sequence'])->pluck('spell.id')->all();

        if ($primaryChain['killTarget']) {
            $usedSpellIds[] = $primaryChain['killTarget']['spell']->id;
        }

        $availableBySecondGo = function (array $entry) use ($usedSpellIds): bool {
            $spell = $entry['spell'];

            if ($spell->requires_stealth || $spell->requires_target_out_of_combat) {
                return false;
            }

            if (!in_array($spell->id, $usedSpellIds, true)) {
                return true;
            }

            if ($spell->cooldown_seconds === null) {
                return true;
            }

            return (float) $spell->cooldown_seconds <= self::NEXT_GO_HORIZON_SECONDS;
        };

        $nextGoPool = $pool->filter($availableBySecondGo)->values();
        $nextGoSetupPool = $setupPool->filter($availableBySecondGo)->values();

        return $this->computeChain($nextGoPool, $nextGoSetupPool, $useRealData);
    }

    /**
     * @param  array<int, array{class: GameClass, spec: Specialization}>  $specEntries
     * @return Collection<int, array{spell: Spell, label: string, isHealer: bool}>
     */
    private function queryPool(array $specEntries, bool $restrictToHardCc): Collection
    {
        $pool = collect();

        foreach ($specEntries as $entry) {
            $class = $entry['class'];
            $spec = $entry['spec'];
            $healer = in_array([$class->slug, $spec->slug], self::HEALER_SLUGS, true);

            $ids = $this->talentService->allTalentSpellIds($spec->id)
                ->merge($this->talentService->allPvpTalentSpellIds($spec->id))
                ->merge($this->talentService->verifiedBaselineAbilityIds($spec->id))
                ->merge($this->talentService->explicitBaselineCooldownAbilityIds($class->id, $spec->id))
                ->unique();

            $query = Spell::whereIn('id', $ids);
            $query = $restrictToHardCc
                ? $query->whereIn('dr_category', self::HARD_CC_CATEGORIES)
                : $query->whereNotNull('dr_category');

            foreach ($query->get() as $spell) {
                $pool->push([
                    'spell' => $spell,
                    'label' => "{$spec->name} {$class->name}",
                    'isHealer' => $healer,
                ]);
            }
        }

        return $pool;
    }

    /**
     * Dedup by spell->id, preferring a non-healer attribution over a healer one when the same
     * ability is reachable from more than one spec in the comp — see wow:cc-formula's own
     * docblock for the real Hammer-of-Justice bug this fixed.
     *
     * @param  Collection<int, array{spell: Spell, label: string, isHealer: bool}>  $pool
     * @return Collection<int, array{spell: Spell, label: string, isHealer: bool}>
     */
    private function dedupPool(Collection $pool): Collection
    {
        return $pool->groupBy(fn ($e) => $e['spell']->id)
            ->map(fn ($group) => $group->firstWhere('isHealer', false) ?? $group->first())
            ->values();
    }

    private function isMeleeRange(Spell $spell): ?bool
    {
        if ($spell->range_yards === null || !preg_match('/(\d+)/', $spell->range_yards, $m)) {
            return null;
        }

        return (int) $m[1] <= 10;
    }

    /**
     * @return array{spell: Spell, label: string, isHealer: bool}|null
     */
    private function findSetupStep(array $entry, Collection $setupPool): ?array
    {
        $neededCategory = $entry['spell']->pairs_with_category;

        if ($neededCategory === null) {
            return null;
        }

        $candidates = $setupPool->filter(fn ($e) => $e['spell']->dr_category === $neededCategory);

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->firstWhere('label', $entry['label']) ?? $candidates->first();
    }
}
