<?php

namespace App\Http\Services;

use App\Models\Spell;

/**
 * "Claude's Counters" simulation engine — see data/claudes-counters/README.md for the full
 * design rationale and honest limitations. This is a deliberately simple, deterministic,
 * rule-based turn simulator, NOT a real combat engine: no damage coefficients, no health pool,
 * no positioning math. It exists to answer "roughly how might this exchange play out, given real
 * cooldowns/DR/mobility" as a readable narrative, not "who wins."
 *
 * Model, in brief:
 * - Two combatants (see buildCombatant()) alternate single actions ("turns"), strictly A/B/A/B.
 *   One turn = one GLOBAL_COOLDOWN_SECONDS-long tick (approximated uniformly at 1.5s for every
 *   ability regardless of real cast time/GCD haste — a deliberate simplification, not an attempt
 *   at frame-accurate combat timing).
 * - Every real ability (Offensive/Defensive/Crowd Control/Mobility, sourced from that spec's own
 *   Claude's Guide spellGrid lists — never re-derived independently) has a cooldown tracked in
 *   ticks. A null real cooldown = always available (filler/core rotation).
 * - Crowd control duration and diminishing returns reuse this project's own confirmed real rules:
 *   CcChainBuilder::PVP_CC_DURATION_CAP_SECONDS (6s flat ceiling) as the default duration when a
 *   spell has no curated `pvp_duration_seconds`, and the same 100%/50%/immune 2-step DR falloff
 *   CcChainBuilder itself uses — computed here with a rolling ~20s (DR_RESET_SECONDS) decay
 *   window, since a single duel runs long enough for DR to reset mid-fight, unlike
 *   CcChainBuilder's own use case (a static list with no time axis at all).
 * - "Pressure" (0-100, per combatant) is an explicitly abstract narrative meter — NOT a real
 *   damage/health simulation. It only exists to decide when a defensive should trigger and to
 *   give the read-out an honest "who's ahead" signal. Real burst-window damage numbers exist
 *   elsewhere on this site (Burst Windows / Class Guide) and are NOT reused here as a damage
 *   model — mixing a real number with fabricated combat math would be worse than not having one.
 */
class DuelSimulatorService
{
    /** One "turn" = this many real seconds. A deliberate flattening, not an accurate GCD model. */
    public const TICK_SECONDS = 1.5;

    /** How many DR-reset windows worth of ticks to track — matches the project's own confirmed 20s window. */
    public const DR_RESET_SECONDS = 20;

    // Same "hard CC" vs "utility CC" split WowComps' own Synergies tab uses (Diminishing Returns
    // Groups vs Utility, see CLAUDE.md) — hard categories rank first, utility ones are still real
    // options but lower priority when both are equally fresh.
    private const CC_PRIORITY = [
        'Stun' => 0, 'Silence' => 1, 'Incapacitate' => 2, 'Disorient' => 3,
        'Root' => 4, 'Knockback' => 5, 'Disarm' => 6, 'Slow' => 7,
    ];

    /**
     * Resolve one side's real ability pool from its Claude's Guide's own curated spellGrid lists
     * — deliberately reusing that guide's own selections (never re-deriving a kit independently),
     * grouped by the guide section it came from so the simulator's role logic (offensive vs
     * defensive vs cc vs mobility) is unambiguous, unlike the site-wide `categorize()` heuristic
     * which has known quirks (Fade/Eye Beam landing under Defensive despite reading as
     * Utility/Offensive to a player — see the Havoc Demon Hunter guide's own note on this).
     *
     * @param  array<string, array<int,int>>  $roleSpellIds  e.g. ['offensive' => [...], 'defensive' => [...], 'cc' => [...], 'mobility' => [...]]
     */
    public function buildCombatant(string $label, string $classSlug, string $specSlug, string $title, string $range, array $roleSpellIds, array $entriesByRole): array
    {
        $abilities = [];
        foreach ($roleSpellIds as $role => $spellIds) {
            foreach ($entriesByRole[$role] ?? [] as $entry) {
                $spell = $entry['spell'];
                $cooldownSeconds = $entry['cooldown']['seconds'] ?? null;
                $abilities[] = [
                    'spell' => $spell,
                    'role' => $role,
                    'cooldown_ticks' => $cooldownSeconds !== null ? max(1, (int) ceil($cooldownSeconds / self::TICK_SECONDS)) : null,
                    'available_at' => 0,
                ];
            }
        }

        return [
            'label' => $label,
            'classSlug' => $classSlug,
            'specSlug' => $specSlug,
            'title' => $title,
            'range' => $range, // 'melee' | 'ranged' — a small hand-authored hint, see the console command
            'abilities' => $abilities,
            'pressure' => 0,
            'cc_category' => null,
            'cc_until' => 0,
            'trinket_available' => true,
            'trinket_available_at' => 0,
            'dr' => [], // dr_category => ['count' => int, 'last_tick' => int]
        ];
    }

    /**
     * Runs the full alternating-turn simulation. $totalTicks is the combined tick budget across
     * BOTH sides (so $totalTicks/2 actions each) — 30 ticks ≈ 45 real seconds, long enough to show
     * at least one full DR reset cycle and more than one CC exchange, short enough to stay
     * readable as a single narrative.
     *
     * @return array{beats: array, summary: array}
     */
    public function simulate(array $sideA, array $sideB, int $totalTicks = 30): array
    {
        $beats = [];
        $distance = 'close'; // 'close' (melee range) | 'far' (kited apart) — only load-bearing when the two sides differ in range
        $sides = [$sideA, $sideB];
        $ccChainDepth = ['A' => 0, 'B' => 0]; // consecutive CC applications landed on this side within the current burst, for trinket judgment

        for ($tick = 1; $tick <= $totalTicks; $tick++) {
            $actorIdx = ($tick - 1) % 2;
            $opponentIdx = 1 - $actorIdx;
            $actor = &$sides[$actorIdx];
            $opponent = &$sides[$opponentIdx];

            $beat = $this->takeTurn($actor, $opponent, $tick, $distance, $ccChainDepth);
            $beat['tick'] = $tick;
            $beat['time_seconds'] = round($tick * self::TICK_SECONDS, 1);
            // Pressure snapshot AFTER this beat resolved — an honest, explicitly-abstract "score"
            // readout for the rendered page, keyed by side label (not sideA/sideB, since actor
            // alternates) so the UI can show a running meter alongside the narrative.
            $beat['pressure'] = [$sides[0]['label'] => $sides[0]['pressure'], $sides[1]['label'] => $sides[1]['pressure']];
            $beat['cc_chain_depth'] = $ccChainDepth; // debug/transparency aid — how many uninterrupted CC applications each side has eaten so far
            $beats[] = $beat;

            if (isset($beat['new_distance'])) {
                $distance = $beat['new_distance'];
            }

            unset($actor, $opponent);

            if ($sides[0]['pressure'] >= 100 || $sides[1]['pressure'] >= 100) {
                break;
            }
        }

        return [
            'beats' => $beats,
            'summary' => [
                'sideA' => ['title' => $sideA['title'], 'final_pressure' => $sides[0]['pressure']],
                'sideB' => ['title' => $sideB['title'], 'final_pressure' => $sides[1]['pressure']],
                'ticks_run' => count($beats),
                'ended_early' => count($beats) < $totalTicks,
            ],
        ];
    }

    /** One side's single action for this tick — the whole decision tree lives here. */
    private function takeTurn(array &$actor, array &$opponent, int $tick, string $distance, array &$ccChainDepth): array
    {
        $label = $actor['label'];

        // 1. Currently hard-CC'd? Decide whether to trinket or ride it out.
        if ($actor['cc_until'] > $tick) {
            $remainingTicks = $actor['cc_until'] - $tick + 1;
            $severe = in_array($actor['cc_category'], ['Stun', 'Disorient', 'Incapacitate'], true);
            // Originally gated on `remainingTicks >= 3` too — dropped after generating the very
            // first real matchup and finding it almost never satisfiable: real curated PvP CC
            // durations in this dataset are mostly 2-6 ticks even at full duration (the confirmed
            // "flat ~6s PvP cap" this whole project already established — see CLAUDE.md's "PvP CC
            // duration cap" section), so a single CC application being "long enough on its own" to
            // clear a >=3-tick bar was the exception, not the rule. The real trinket-worthy signal
            // is being chain-CC'd repeatedly (consecutive different-category locks with no window
            // to act at all) or taking real sustained pressure while locked — not the raw length
            // of whichever CC happens to be active at the exact moment of the check.
            $worthBreaking = $severe
                && $actor['trinket_available']
                && ($actor['pressure'] >= 50 || $ccChainDepth[$label] >= 2);

            if ($worthBreaking) {
                $actor['trinket_available'] = false;
                $actor['cc_until'] = 0;
                $actor['cc_category'] = null;
                $ccChainDepth[$label] = 0;

                return [
                    'actor' => $label, 'action_type' => 'trinket',
                    'ability' => null,
                    'note' => "{$actor['title']} trinkets out of the {$this->fmtCategory($actor['cc_category'])}effect — {$remainingTicks} ticks of control still remaining was judged too costly to ride out.",
                ];
            }

            return [
                'actor' => $label, 'action_type' => 'stuck',
                'ability' => null,
                'note' => "{$actor['title']} is still locked down ({$remainingTicks} tick(s) of {$actor['cc_category']} remaining) and can't act this turn.",
            ];
        }

        // 2a. Reactive defensive — opponent just landed a real Offensive-role hit and pressure is climbing.
        if ($actor['pressure'] >= 55) {
            $defensive = $this->pickDefensive($actor, $tick, $actor['pressure'] >= 85);
            if ($defensive) {
                $this->consume($actor, $defensive, $tick);
                $reduced = $actor['pressure'] >= 85 ? 35 : 20;
                $actor['pressure'] = max(0, $actor['pressure'] - $reduced);

                return [
                    'actor' => $label, 'action_type' => 'defensive',
                    'ability' => $this->abilityRef($defensive),
                    'note' => "{$actor['title']} answers the pressure with {$defensive['spell']->display_name}, easing off the incoming damage.",
                ];
            }
        }

        // 2b. Cash in a ready offensive cooldown WHILE the opponent is still locked down from an
        // earlier CC, rather than reflexively re-locking them again — this is the actual "conjunction"
        // moment every guide in this series describes, and it must win over further CC-chaining or
        // nothing ever gets spent (a real bug caught on the very first generated matchup: two
        // control-heavy specs kept re-CC'ing each other forever with zero pressure ever landing,
        // because CC-chaining was checked before this cash-in step — fixed by moving this here,
        // ahead of the general CC pick below).
        if ($opponent['cc_until'] > $tick) {
            $offensive = $this->pickOffensive($actor, $tick);
            if ($offensive) {
                return $this->useOffensive($actor, $opponent, $offensive, $tick, $label);
            }
        }

        // 2c. Crowd control — a fresh opener (opponent not currently CC'd) or, when no offensive
        // is ready yet to cash in with (see above), a category-diverse chain to buy more time
        // instead of letting the lock lapse for nothing.
        $cc = $this->pickCc($actor, $opponent, $tick);
        if ($cc) {
            $duration = $cc['spell']->pvp_duration_seconds ?? \App\Http\Services\CcChainBuilder::PVP_CC_DURATION_CAP_SECONDS;

            [$percentage, $immune] = $this->drState($opponent, $cc['spell']->dr_category, $tick);
            if ($immune) {
                // Fully diminished — landing it would do nothing. Treat as a wasted beat rather
                // than silently skip; a real player misjudging DR is itself worth showing.
                $this->consume($actor, $cc, $tick);

                return [
                    'actor' => $label, 'action_type' => 'cc_wasted',
                    'ability' => $this->abilityRef($cc),
                    'note' => "{$actor['title']} lands {$cc['spell']->display_name}, but {$opponent['title']} is already immune to {$cc['spell']->dr_category} this window — no effect.",
                ];
            }

            $appliedDuration = $duration * ($percentage / 100);
            $appliedTicks = max(1, (int) ceil($appliedDuration / self::TICK_SECONDS));
            $this->consume($actor, $cc, $tick);
            $opponent['cc_category'] = $cc['spell']->dr_category;
            $opponent['cc_until'] = $tick + $appliedTicks;
            $this->recordDr($opponent, $cc['spell']->dr_category, $tick);
            $ccChainDepth[$opponent['label']] = ($ccChainDepth[$opponent['label']] ?? 0) + 1;

            $note = $percentage < 100
                ? "{$actor['title']} lands {$cc['spell']->display_name} ({$cc['spell']->dr_category}) on {$opponent['title']} — diminished to {$percentage}% duration from a recent repeat."
                : "{$actor['title']} lands {$cc['spell']->display_name} ({$cc['spell']->dr_category}) on {$opponent['title']}.";

            return ['actor' => $label, 'action_type' => 'cc', 'ability' => $this->abilityRef($cc), 'note' => $note];
        }

        // 2c/2d. Distance management — only load-bearing when the two sides actually differ in range.
        if ($actor['range'] !== $opponent['range']) {
            $wantsDistance = $actor['range'] === 'ranged' && $distance === 'close';
            $wantsToClose = $actor['range'] === 'melee' && $distance === 'far';

            if ($wantsDistance || $wantsToClose) {
                $mobility = $this->pickMobility($actor, $tick);
                if ($mobility) {
                    $this->consume($actor, $mobility, $tick);
                    $newDistance = $wantsDistance ? 'far' : 'close';
                    $verb = $wantsDistance ? 'creates distance with' : 'closes the gap with';

                    return [
                        'actor' => $label, 'action_type' => 'mobility',
                        'ability' => $this->abilityRef($mobility),
                        'note' => "{$actor['title']} {$verb} {$mobility['spell']->display_name}.",
                        'new_distance' => $newDistance,
                    ];
                }

                if ($wantsToClose) {
                    // No gap-closer available — an honest, informative dead end for some kits
                    // (Arms Warrior has no real disengage the other direction either).
                    return [
                        'actor' => $label, 'action_type' => 'chasing',
                        'ability' => null,
                        'note' => "{$actor['title']} has no gap-closer available and can't reach {$opponent['title']} this turn.",
                    ];
                }
            }
        }

        // 2e. A real offensive cooldown — the opponent isn't currently locked at this point (that
        // case was already handled by the cash-in check above), so this is routine pressure.
        $offensive = $this->pickOffensive($actor, $tick);
        if ($offensive) {
            return $this->useOffensive($actor, $opponent, $offensive, $tick, $label);
        }

        // 2f. Nothing meaningful available — an honest "nothing to do" beat rather than forcing an action.
        return [
            'actor' => $label, 'action_type' => 'wait',
            'ability' => null,
            'note' => "{$actor['title']} has nothing impactful available and holds position.",
        ];
    }

    /** Shared by both offensive-usage sites (cashing in on a locked opponent, and routine pressure). */
    private function useOffensive(array &$actor, array &$opponent, array $offensive, int $tick, string $label): array
    {
        $this->consume($actor, $offensive, $tick);
        $opponentCcActive = $opponent['cc_until'] > $tick;
        $big = $offensive['cooldown_ticks'] !== null && $offensive['cooldown_ticks'] >= (int) ceil(40 / self::TICK_SECONDS);
        $gain = ($big ? 20 : 8) * ($opponentCcActive ? 2 : 1);
        $opponent['pressure'] = min(100, $opponent['pressure'] + $gain);

        $note = $opponentCcActive
            ? "{$actor['title']} presses {$offensive['spell']->display_name} while {$opponent['title']} is still locked down — free pressure."
            : "{$actor['title']} presses {$offensive['spell']->display_name}.";

        return ['actor' => $label, 'action_type' => 'offensive', 'ability' => $this->abilityRef($offensive), 'note' => $note];
    }

    private function pickDefensive(array $actor, int $tick, bool $needBig): ?array
    {
        $available = collect($actor['abilities'])
            ->filter(fn ($a) => $a['role'] === 'defensive' && $a['available_at'] <= $tick);

        if ($available->isEmpty()) {
            return null;
        }

        // "Ladder to threat" — cheapest/most-frequent first, unless the pressure is severe enough
        // to warrant the biggest available answer instead.
        $sorted = $available->sortBy(fn ($a) => $a['cooldown_ticks'] ?? 0);

        return ($needBig ? $sorted->reverse()->first() : $sorted->first());
    }

    private function pickCc(array $actor, array $opponent, int $tick): ?array
    {
        $available = collect($actor['abilities'])
            ->filter(fn ($a) => $a['role'] === 'cc' && $a['available_at'] <= $tick);

        if ($available->isEmpty()) {
            return null;
        }

        $opponentCcActive = $opponent['cc_until'] > $tick;

        // By the time control reaches this method, the caller has already checked (and skipped)
        // the case where the actor has an offensive ready to cash in on an already-locked
        // opponent — see takeTurn()'s own cash-in-first ordering. So if we're here AND the
        // opponent is still locked, it specifically means no offensive is ready yet: chaining a
        // genuinely different category buys time until one is, rather than letting the lock
        // lapse for nothing. Chaining the SAME category as what's already active would be pure
        // waste (it'd land at reduced/zero duration on top of an effect that hasn't even worn off
        // yet), so that's still excluded.
        if ($opponentCcActive) {
            $available = $available->filter(fn ($a) => $a['spell']->dr_category !== $opponent['cc_category']);
            if ($available->isEmpty()) {
                return null;
            }
        }

        // DR-aware selection: a smart player reaches for whichever available category is
        // currently freshest, not just the "highest priority" one regardless of how diminished it
        // already is. Caught on the very first generated matchup — a Frost Mage kept reaching for
        // Polymorph (Incapacitate) even after it was fully DR'd, producing several wasted beats in
        // a row, while Frost Nova/Dragon's Breath (still at full duration) sat unused. Sorting by
        // DR freshness FIRST, category priority second, fixes this without discarding priority
        // entirely — two equally-fresh options still resolve by the same hard-CC-first ordering.
        return $available
            ->sortBy([
                fn ($a, $b) => $this->drState($opponent, $b['spell']->dr_category, $tick)[0] <=> $this->drState($opponent, $a['spell']->dr_category, $tick)[0],
                fn ($a, $b) => (self::CC_PRIORITY[$a['spell']->dr_category] ?? 9) <=> (self::CC_PRIORITY[$b['spell']->dr_category] ?? 9),
            ])
            ->first();
    }

    private function pickMobility(array $actor, int $tick): ?array
    {
        return collect($actor['abilities'])
            ->filter(fn ($a) => $a['role'] === 'mobility' && $a['available_at'] <= $tick)
            ->first();
    }

    private function pickOffensive(array $actor, int $tick): ?array
    {
        $available = collect($actor['abilities'])
            ->filter(fn ($a) => $a['role'] === 'offensive' && $a['available_at'] <= $tick);

        if ($available->isEmpty()) {
            return null;
        }

        // Prefer the biggest available real cooldown (rarest, most impactful) over always-up
        // filler — matches every guide's own "cooldown ledger" framing (spend the rare one when
        // it's actually available, don't let it sit banked while filler ticks by).
        return $available->sortByDesc(fn ($a) => $a['cooldown_ticks'] ?? -1)->first();
    }

    private function consume(array &$actor, array $ability, int $tick): void
    {
        foreach ($actor['abilities'] as &$a) {
            if ($a['spell']->id === $ability['spell']->id && $a['role'] === $ability['role']) {
                $a['available_at'] = $a['cooldown_ticks'] !== null ? $tick + $a['cooldown_ticks'] : $tick;
                break;
            }
        }
        unset($a);
    }

    /** @return array{Spell, string} */
    private function abilityRef(array $ability): array
    {
        return ['spell_id' => $ability['spell']->spell_id, 'name' => $ability['spell']->display_name, 'role' => $ability['role']];
    }

    private function recordDr(array &$side, string $category, int $tick): void
    {
        $side['dr'][$category] = ['count' => (($side['dr'][$category]['count'] ?? 0) + 1), 'last_tick' => $tick];
    }

    /** @return array{int, bool} [percentage, immune] — reuses CcChainBuilder's own confirmed 100/50/0 falloff, with a rolling reset window this project's own DR_RESET_SECONDS confirms. */
    private function drState(array $side, string $category, int $tick): array
    {
        $entry = $side['dr'][$category] ?? null;
        if ($entry === null) {
            return [100, false];
        }

        $resetTicks = (int) ceil(self::DR_RESET_SECONDS / self::TICK_SECONDS);
        if ($tick - $entry['last_tick'] > $resetTicks) {
            return [100, false]; // window elapsed — DR has reset
        }

        $occurrence = min($entry['count'] + 1, 3);
        $percentage = match ($occurrence) {
            1 => 100, 2 => 50, default => 0,
        };

        return [$percentage, $percentage === 0];
    }

    private function fmtCategory(?string $category): string
    {
        return $category ? "{$category} " : '';
    }
}
