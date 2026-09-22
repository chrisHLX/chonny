<?php

namespace App\Http\Services;

/**
 * The Matchup Lab engine: puts two 3v3 comps' real cooldowns on one clock and reads off where
 * each side's kill window opens.
 *
 * WHAT IT IS. A deterministic, rule-based structural simulator over data this project already
 * holds — the same kind of thing as DuelSimulatorService and held to the same contract: no
 * randomness, no damage model, no health pool, no positioning, and every assumption a named
 * constant in this file rather than a number buried in a loop. Its whole job is to answer
 * "whose window opens first, and why", in arena-structure.md's own vocabulary.
 *
 * WHAT IT IS NOT, AND THIS IS LOAD-BEARING.
 *
 * - **Not a win probability.** There is no outcome corpus to fit one to. Match search is
 *   discontinued upstream (CLAUDE.md rule 12), the archive is a fixed 689 matches, and its comp
 *   index holds two entries. A percentage on this page would be invented, and inventing one is
 *   the exact failure the project's "flag, don't guess" rule exists to prevent. The output is a
 *   structural read with its reasons attached; the reader decides what it is worth.
 * - **Not a kill.** An empty answer pool means the target has no button left — not that the
 *   incoming damage is lethal. The gap between those is a damage model this project cannot
 *   build: Part 11 of arena-structure.md names the blocker (static coefficient dumps are not
 *   tied to a real character's stats). So the engine reports a **kill window** — a window in
 *   which a go is a kill attempt rather than a strip — and never more. The Gemini source that
 *   prompted this feature called it "mathematically guaranteed"; that phrasing is rejected in
 *   Part 19.1 and must not creep back into the page copy.
 * - **Not tight.** Every period computed here is an UPPER BOUND. The database holds base and
 *   talent-modified cooldowns but not spend-driven reduction ("each cast of X takes 3s off Y"),
 *   because that is a rotation run at a rate and no rate is recorded anywhere. Real goes come
 *   round sooner than this says, by an unknown amount that differs per spec. See
 *   knowledge-gaps.md (2026-09-23) and arena-open-questions.md C12, which names the archive
 *   measurement that would close it.
 *
 * THE MODEL IT IMPLEMENTS is arena-structure.md Parts 2, 3, 5, 7, 12 and 19, specifically:
 *   - Part 2: the answer pool is the currency; it is PER PLAYER, not per team; the kill target
 *     is whoever's list is shortest, re-derived every go.
 *   - Part 5: a go's quality is globals denied — how many enemy players it reaches at once.
 *   - Part 7: time is counted in goes, and cadence is CHOSEN by aligning abilities on a common
 *     period. Hence two cadences below, not one, and neither is hardcoded to 30s.
 *   - Part 19.1: a player in CC has a reachable pool of zero whatever they hold.
 *   - Part 19.2: the answer to a threat is a ranked cascade, not a lookup. Applied at the
 *     'pool' execution setting and in the trigger table.
 *   - Part 19.3: execution is an input, not a rating band.
 *   - Part 12 / 19.4: dampening decays the value of every answer over the round.
 */
class CooldownGraphService
{
    /**
     * Six minutes. Long enough that a dampening game reaches its decisive stretch, short enough
     * that the timeline stays readable at a glance. A matchup where neither side's window opens
     * inside this horizon is not a modelling failure — it is Part 12's "playing the clock" case,
     * and the verdict says so in those words rather than extending the simulation until
     * something happens.
     */
    public const HORIZON_SECONDS = 360;

    /**
     * Fallback control duration when a spell has no curated `pvp_duration_seconds`. Reuses
     * CcChainBuilder's confirmed PvP ceiling rather than a second copy of the number, for the
     * same reason CooldownTabs reads WowComps' constants: a duplicated rule drifts, and the
     * symptom would be two pages disagreeing about how long a stun lasts.
     */
    public const DEFAULT_CONTROL_SECONDS = CcChainBuilder::PVP_CC_DURATION_CAP_SECONDS;

    /**
     * How long a team stays committed to a go — the stretch during which the defender is
     * actually being forced to answer. Derived as an assumption, not a measurement: it is the
     * rough length of an anchor burst window, which `BurstGuideBuilder` measures at 12s for real
     * matches (see ArenaLogService's topDpsWindow). Only used to decide whether a second team's
     * go in the same few seconds counts as simultaneous.
     */
    public const GO_COMMIT_SECONDS = 12;

    /**
     * How long a diminishing-returns category takes to reset. Reuses DuelSimulatorService's
     * constant rather than introducing a third number — arena-structure.md Part 7 says "~18s",
     * that class says 20s and calls it "the project's own confirmed 20s window", and two engines
     * on one site disagreeing about how long DR lasts is the drift CooldownTabs exists to
     * prevent.
     *
     * DR IS WHY A GO HAS A CADENCE AT ALL. Without it a Rogue holding Cheap Shot on a 12s
     * cooldown appears able to open a go every 12 seconds, which produced 31 goes in six minutes
     * on the first run of this engine and is obviously not arena. The second stun inside the
     * window is worth half, the third nothing, so a team is gated by how many *distinct*
     * categories it can bring — which is Part 7's point that time is counted in DRs.
     */
    public const DR_RESET_SECONDS = DuelSimulatorService::DR_RESET_SECONDS;

    /**
     * The 100% / 50% / immune falloff, same two-step rule CcChainBuilder and
     * DuelSimulatorService both already apply. Index is how many times that category has landed
     * on that player inside the current window.
     */
    public const DR_MULTIPLIERS = [1.0, 0.5, 0.0];

    /**
     * A go has to reach at least this many enemy players to count as a go rather than a strip.
     * Part 5: "the design criterion for a go is: how many free globals does it leave the enemy
     * team?" — a commitment that leaves two of the three free is not the thing the model is
     * describing. Part 2's language for the other case is that it is "a strip, and should be
     * planned as one".
     */
    public const MIN_DENIED_FOR_GO = 2;

    /**
     * Dampening. Part 12 is [OBS] that it scales defensive cooldowns this patch and therefore
     * decays the value of every answer in the pool across a round — Calvish builds a whole comp
     * choice on it. The RATE is [HYP]: nothing measures how fast, and these two thresholds are
     * assumptions stated on the page rather than a finding.
     *
     * Modelled as an extra answer spent per go — after this point a single answer no longer
     * covers a threat on its own, which is the behavioural form of "the pool is not sufficient
     * any more" without pretending to know a healing-reduction percentage.
     */
    public const DAMPENING_FIRST_STEP_SECONDS = 180;

    public const DAMPENING_SECOND_STEP_SECONDS = 300;

    /**
     * The execution settings, Part 19.3. These are not rating bands — Part 15's correction is
     * that these are error rates that fall, never stages that are passed, and the world champion
     * makes the 1800-bracket error in a grand final. They are three readings of the same
     * matchup, and a guide written against one is a different document from a guide written
     * against another.
     */
    public const EXECUTION_LATE = 'late';

    public const EXECUTION_CLEAN = 'clean';

    public const EXECUTION_POOL = 'pool';

    public const EXECUTION_SETTINGS = [
        self::EXECUTION_LATE => [
            'label' => 'Answering late',
            'summary' => 'The defender answers a threat that has already landed, so a second answer lands on top of the first. One go costs two buttons.',
            'model' => 'arena-structure.md Part 3 — overlap as the dominant loss condition.',
        ],
        self::EXECUTION_CLEAN => [
            'label' => 'Trading cleanly',
            'summary' => 'One sufficient answer per threat, no overlap — but every go is sent the moment it is up, whether or not their pool is low.',
            'model' => 'Part 3\'s pre-agreed trigger working as intended.',
        ],
        self::EXECUTION_POOL => [
            'label' => 'Playing the pool',
            'summary' => 'Control the source before spending a cooldown, and hold a go rather than send it into a full pool. The go not sent is a move.',
            'model' => 'Parts 2, 6 and 8 — patience inside the window, and the cascade in Part 19.2.',
        ],
    ];

    /**
     * @param  array<int, array{role: string, profile: array}>  $teamA  three members, healer first by convention
     * @param  array<int, array{role: string, profile: array}>  $teamB
     */
    public function run(array $teamA, array $teamB, string $execution = self::EXECUTION_CLEAN): array
    {
        $execution = array_key_exists($execution, self::EXECUTION_SETTINGS) ? $execution : self::EXECUTION_CLEAN;

        $a = $this->initTeam('a', $teamA);
        $b = $this->initTeam('b', $teamB);

        $events = [];
        $samples = [];

        for ($t = 0; $t <= self::HORIZON_SECONDS; $t++) {
            // Both teams are evaluated at every second from the same state, and a team that goes
            // does not see the other team's go in the same tick. Order within a tick would
            // otherwise silently decide simultaneous goes, which is a coin flip dressed as a
            // model.
            $aGoes = $this->wantsToGo($a, $b, $t, $execution);
            $bGoes = $this->wantsToGo($b, $a, $t, $execution);

            if ($aGoes) {
                $events[] = $this->resolveGo($a, $b, $t, $execution);
            }

            if ($bGoes) {
                $events[] = $this->resolveGo($b, $a, $t, $execution);
            }

            $samples[] = [
                't' => $t,
                'aThreat' => $this->threatLevel($a, $t),
                'bThreat' => $this->threatLevel($b, $t),
                'aPool' => $this->poolLevels($a, $t),
                'bPool' => $this->poolLevels($b, $t),
            ];
        }

        usort($events, fn ($x, $y) => $x['t'] <=> $y['t']);

        return [
            'execution' => $execution,
            'executionSetting' => self::EXECUTION_SETTINGS[$execution],
            'horizon' => self::HORIZON_SECONDS,
            'teams' => [
                'a' => $this->teamSummary($a),
                'b' => $this->teamSummary($b),
            ],
            'samples' => $samples,
            'events' => $events,
            'killWindows' => array_values(array_filter($events, fn ($e) => $e['killWindow'])),
            'verdict' => $this->verdict($a, $b, $events),
            'triggers' => [
                'a' => $this->triggerTable($a, $b),
                'b' => $this->triggerTable($b, $a),
            ],
        ];
    }

    /**
     * Sets up one team's mutable state, and derives the terms Part 17 asks the data layer for.
     *
     * @param  array<int, array{role: string, profile: array}>  $members
     */
    private function initTeam(string $side, array $members): array
    {
        $players = [];

        foreach ($members as $index => $member) {
            $profile = $member['profile'];

            $answers = [];
            foreach ($profile['answers'] as $answer) {
                // An answer with no cooldown at all is not a scarce resource and cannot be
                // "spent" in Part 2's sense, so it is not part of the pool. Everything that
                // reaches this list has already cleared WowComps' reviewed cooldown floor via
                // CooldownTabs, so this only drops genuinely cooldown-less rows.
                if (($answer['cooldown'] ?? null) === null) {
                    continue;
                }

                $answers[] = $answer + ['readyAt' => 0.0];
            }

            // Cheapest first. Part 3's ladder: spend the cheap answer, keep the expensive one,
            // and the trinket is always last because it only buys time to press something else
            // (Part 2) — a trinket with nothing behind it is a two-second delay.
            usort($answers, function ($x, $y) {
                if (($x['kind'] === 'trinket') !== ($y['kind'] === 'trinket')) {
                    return $x['kind'] === 'trinket' ? 1 : -1;
                }

                return ($x['cooldown'] ?? 0) <=> ($y['cooldown'] ?? 0);
            });

            $control = [];
            foreach ($profile['control'] as $entry) {
                $control[] = $entry + ['readyAt' => 0.0];
            }

            $offensive = [];
            foreach ($profile['offensive'] as $entry) {
                $offensive[] = $entry + ['readyAt' => 0.0];
            }

            $players[] = [
                'index' => $index,
                'role' => $member['role'],
                'name' => $profile['specName'].' '.$profile['className'],
                'class' => $profile['class'],
                'spec' => $profile['spec'],
                'answers' => $answers,
                'control' => $control,
                'offensive' => $offensive,
                'controlledUntil' => -1.0,
                'answersSpent' => 0,
                // [category => ['count' => int, 'lastAt' => float]] — this player's own DR state,
                // as the ENEMY sees it. Tracked per player because DR is per target, which is
                // also why cross-CC exists at all.
                'dr' => [],
                'firstKillWindowAt' => null,
            ];
        }

        return [
            'side' => $side,
            'players' => $players,
            'goes' => 0,
            'lastGoAt' => null,
            'controlCadence' => $this->controlCadence($players),
            'cooldownCadence' => $this->cooldownCadence($players),
            'reach' => $this->reach($players),
            'firstKillWindowAt' => null,
        ];
    }

    /**
     * How long a control lands for on this victim right now, after DR. Returns 0.0 when the
     * category is immune, which is what makes a team rotate categories rather than repeat one —
     * and therefore what gives a comp its real go rate.
     */
    private function effectiveControlSeconds(array $victim, string $category, float $base, float $t): float
    {
        $state = $victim['dr'][$category] ?? null;

        if ($state === null || $t - $state['lastAt'] > self::DR_RESET_SECONDS) {
            return $base;
        }

        $multiplier = self::DR_MULTIPLIERS[min($state['count'], count(self::DR_MULTIPLIERS) - 1)];

        return $base * $multiplier;
    }

    private function applyDr(array &$victim, string $category, float $t): void
    {
        $state = $victim['dr'][$category] ?? null;

        $victim['dr'][$category] = ($state === null || $t - $state['lastAt'] > self::DR_RESET_SECONDS)
            ? ['count' => 1, 'lastAt' => $t]
            : ['count' => $state['count'] + 1, 'lastAt' => $t];
    }

    /**
     * Part 7's period, for the CHEAP go: the cadence at which every member who owns hard control
     * can bring one at once. The max of each member's cheapest hard control, because the team is
     * gated by its slowest term — this is the arithmetic behind "Scatter Shot's 30s lining up
     * with Maim's is the reason to bring Scatter back in Jungle."
     *
     * Derived per comp, never assumed. The Gemini source proposed a flat 30s; 30 is Jungle's
     * number because Maim and Scatter are both 30s, not a property of arena (Part 19.1).
     *
     * @param  array<int, array>  $players
     */
    private function controlCadence(array $players): ?float
    {
        $slowest = null;

        foreach ($players as $player) {
            $cheapest = null;

            foreach ($player['control'] as $entry) {
                if (! $entry['hard'] || ($entry['cooldown'] ?? null) === null) {
                    continue;
                }

                if ($cheapest === null || $entry['cooldown'] < $cheapest) {
                    $cheapest = $entry['cooldown'];
                }
            }

            if ($cheapest !== null && ($slowest === null || $cheapest > $slowest)) {
                $slowest = $cheapest;
            }
        }

        return $slowest;
    }

    /**
     * Part 7's period for the FULL go — every member's anchor cooldown aligned. The longest of
     * each member's longest offensive cooldown. This is the number behind "we have combust
     * kingsbane in 20 seconds": how often the comp can produce a go with everything in it.
     *
     * DPS ONLY. A healer's longest classified offensive cooldown is routinely something like
     * Ultimate Penitence at 240s, which is not a term any comp aligns its goes to — the first
     * run of this engine reported a 240s burst cadence for RMP on exactly that basis. What Part
     * 7 describes teams aligning is damage cooldowns.
     *
     * @param  array<int, array>  $players
     */
    private function cooldownCadence(array $players): ?float
    {
        $slowest = null;

        foreach ($players as $player) {
            if ($player['role'] === 'healer') {
                continue;
            }

            $longest = null;

            foreach ($player['offensive'] as $entry) {
                if (($entry['cooldown'] ?? null) === null) {
                    continue;
                }

                if ($longest === null || $entry['cooldown'] > $longest) {
                    $longest = $entry['cooldown'];
                }
            }

            if ($longest !== null && ($slowest === null || $longest > $slowest)) {
                $slowest = $longest;
            }
        }

        return $slowest;
    }

    /**
     * Part 5's globals denied, as a structural maximum: how many enemy players this team could
     * reach at once if every member's control were up. Counts MEMBERS with hard control, not
     * abilities — three stuns on one player denies one player's globals, not three.
     *
     * Capped at 3 and silently assumes both enemy DPS are reachable, which Part 14.1 says is a
     * positional question the data cannot answer. The page states that assumption.
     *
     * @param  array<int, array>  $players
     */
    private function reach(array $players): int
    {
        $count = 0;

        foreach ($players as $player) {
            foreach ($player['control'] as $entry) {
                if ($entry['hard']) {
                    $count++;

                    break;
                }
            }
        }

        return min(3, $count);
    }

    /**
     * Does this team commit a go at $t?
     *
     * The trigger is the CONTROL cadence, not the cooldown cadence, because Part 1's correction
     * is that a go does not have to be a kill attempt to be correct — "a go is a commitment of
     * scarce resources aimed at changing the answer pool", and emptying their buttons is one of
     * the ways it pays.
     *
     * Two gates on top:
     *   - A team whose own members are mostly locked cannot commit. Part 19.1's stagger rule
     *     applied to the attacker.
     *   - At the 'pool' setting only, a go into a full pool is HELD. This is Part 8's patience
     *     and Part 6's "a legitimate output is: hold this, it is worth more elsewhere" — and it
     *     is the single behaviour that most distinguishes the top setting from the middle one.
     */
    private function wantsToGo(array &$team, array &$enemy, float $t, string $execution): bool
    {
        if ($team['lastGoAt'] !== null && $t - $team['lastGoAt'] < self::GO_COMMIT_SECONDS) {
            return false;
        }

        // A go is only a go if it reaches enough of them (Part 5). This is the DR-aware count:
        // a stun that lands for zero seconds because the category is immune denies nothing, so
        // a team holding three stuns and one victim has one go, not three.
        $plan = $this->planControl($team, $enemy, $t, $this->weakestTarget($enemy, $t));

        // max(1, ...) is load-bearing. `reach` is 0 for a comp with no hard control at all, and
        // min(2, 0) made the gate `count($plan) < 0` — vacuously false, so a comp holding
        // nothing but slows committed a go every twelve seconds. Caught by
        // MatchupLabTest::test_a_comp_with_no_hard_control_never_commits_a_go.
        $required = max(1, min(self::MIN_DENIED_FOR_GO, $team['reach']));

        if (count($plan) < $required) {
            return false;
        }

        // A go is the DR window PLUS whatever cooldowns are aligned to it (Part 7). Control on
        // its own, with nothing behind it, is not a go — it is CC spent for no reason, and Part
        // 6 prices that as a loss ("you shouldn't send a 2-minute cooldown DR'd after a Fear").
        // Without this gate the engine sends a go every time the cheapest stun comes back: 28
        // goes in six minutes on the run before this check existed.
        if (! $this->hasReadyBurst($team, $t)) {
            return false;
        }

        if ($execution === self::EXECUTION_POOL && ! $this->worthSending($team, $enemy, $t)) {
            return false;
        }

        return true;
    }

    /**
     * Resolves one go. This is where Parts 2, 5, 6 and 19.2 meet.
     */
    private function resolveGo(array &$team, array &$enemy, float $t, string $execution): array
    {
        $targetIndex = $this->weakestTarget($enemy, $t);
        $denied = [];
        $controlSpent = [];

        foreach ($this->planControl($team, $enemy, $t, $targetIndex) as $step) {
            $team['players'][$step['by']]['control'][$step['entry']]['readyAt'] = $t + $step['cooldown'];

            $victim = &$enemy['players'][$step['victim']];
            $victim['controlledUntil'] = max($victim['controlledUntil'], $t + $step['seconds']);
            $this->applyDr($victim, $step['category'], $t);

            $denied[] = [
                'player' => $victim['name'],
                'role' => $victim['role'],
                'seconds' => round($step['seconds'], 1),
                'until' => round($t + $step['seconds'], 1),
                'diminished' => $step['diminished'],
            ];
            $controlSpent[] = [
                'by' => $team['players'][$step['by']]['name'],
                'spell' => $step['spell'],
                'category' => $step['category'],
                'seconds' => round($step['seconds'], 1),
            ];
            unset($victim);
        }

        $healerDenied = false;
        foreach ($denied as $entry) {
            if ($entry['role'] === 'healer') {
                $healerDenied = true;
            }
        }

        // What it costs the defender.
        $cost = $this->answersRequired($t, $execution);
        $spent = [];
        $peeled = null;
        $killWindow = false;
        $lockedOut = false;

        if ($targetIndex !== null) {
            // Part 19.2's cascade, top rung: control the source rather than spend a cooldown.
            // Only available at the top setting, and only to a defender who is free to press it
            // — a defender in CC answers with nothing, which is the point of Part 19.1.
            if ($execution === self::EXECUTION_POOL) {
                $peeled = $this->tryPeel($enemy, $t, $targetIndex);
            }

            if ($peeled === null) {
                $live = $this->liveAnswers($enemy['players'][$targetIndex], $t);

                if ($live === []) {
                    // Part 2: a go into an empty pool is a kill, and should be planned as one.
                    // It is only a window if the go also denied somebody — otherwise a free
                    // teammate answers it and the pool that mattered was never this player's.
                    //
                    // Only the FIRST one per side is reported as a window. Once a pool is empty
                    // it tends to stay empty for a while, so every subsequent go would also
                    // qualify — the first run of this engine reported 29 "kill windows" in one
                    // matchup, which is noise rather than a finding. What the later ones
                    // actually show is Part 3's state: "you are not allowed to play until you
                    // have these buttons back", and they are tagged as that instead.
                    $sustained = $team['firstKillWindowAt'] !== null;
                    $killWindow = $denied !== [] && ! $sustained;
                    $lockedOut = $denied !== [] && $sustained;

                    if ($killWindow) {
                        $team['firstKillWindowAt'] = $t;
                    }
                } else {
                    foreach (array_slice($live, 0, $cost) as $answerIndex) {
                        $answer = $enemy['players'][$targetIndex]['answers'][$answerIndex];
                        $enemy['players'][$targetIndex]['answers'][$answerIndex]['readyAt'] = $t + $answer['cooldown'];
                        $enemy['players'][$targetIndex]['answersSpent']++;
                        $spent[] = ['player' => $enemy['players'][$targetIndex]['name'], 'spell' => $answer['name'], 'kind' => $answer['kind']];
                    }
                }
            }
        }

        // The offensive cooldowns that went with it. Not used to compute damage — nothing here
        // computes damage — but spending them is what makes the next full go late, which is the
        // "ticking clock" the whole timeline exists to show.
        $burst = [];
        foreach ($team['players'] as $playerIndex => $player) {
            if ($player['controlledUntil'] > $t) {
                continue;
            }

            foreach ($player['offensive'] as $entryIndex => $entry) {
                if (($entry['cooldown'] ?? null) === null || $entry['readyAt'] > $t) {
                    continue;
                }

                $team['players'][$playerIndex]['offensive'][$entryIndex]['readyAt'] = $t + $entry['cooldown'];
                $burst[] = ['by' => $player['name'], 'spell' => $entry['name']];

                break;
            }
        }

        $team['goes']++;
        $team['lastGoAt'] = $t;

        return [
            't' => (int) $t,
            'side' => $team['side'],
            'target' => $targetIndex === null ? null : $enemy['players'][$targetIndex]['name'],
            'targetRole' => $targetIndex === null ? null : $enemy['players'][$targetIndex]['role'],
            'denied' => $denied,
            'healerDenied' => $healerDenied,
            'controlSpent' => $controlSpent,
            'burst' => $burst,
            'spent' => $spent,
            'peeledBy' => $peeled,
            'answersRequired' => $cost,
            'killWindow' => $killWindow,
            'lockedOut' => $lockedOut,
            'remaining' => $targetIndex === null ? 0 : count($this->liveAnswers($enemy['players'][$targetIndex], $t)),
        ];
    }

    /**
     * The 'pool' setting's extra gate: is this go worth sending, or is it worth more later?
     * Part 6's "a legitimate output is: hold this — it is worth more elsewhere", and Part 8's
     * patience inside a window.
     *
     * Three ways it is worth sending, and the first two matter as much as the third:
     *
     *  - **Every DPS has a cooldown up.** This is the full go Part 7 describes aligning, and
     *    holding it is not patience, it is the "sitting on a window until their answers return"
     *    error Part 8 names in the same breath as the rushing one.
     *  - **Their shortest list is already down to its last button.** The go that cashes.
     *  - **Dampening has started.** Past that point a held cooldown is worth less than a spent
     *    one, which is the whole mechanism behind Part 12's clock.
     *
     * The first clause is not optional. An earlier version had only the last two, and the two
     * teams deadlocked: neither would go into a full pool, so no pool ever emptied, so neither
     * went until the dampening clause fired at 3:00. A model in which the best players never
     * open is obviously the wrong model — Part 2 is explicit that a go into a full pool is a
     * **strip, and should be planned as one**, not a go that never happens.
     */
    private function worthSending(array $team, array $enemy, float $t): bool
    {
        if ($t >= self::DAMPENING_FIRST_STEP_SECONDS) {
            return true;
        }

        $weakest = $this->weakestTarget($enemy, $t);

        if ($weakest !== null && count($this->liveAnswers($enemy['players'][$weakest], $t)) <= 1) {
            return true;
        }

        foreach ($team['players'] as $player) {
            if ($player['role'] === 'healer') {
                continue;
            }

            $ready = false;

            foreach ($player['offensive'] as $entry) {
                if (($entry['cooldown'] ?? null) !== null && $entry['readyAt'] <= $t) {
                    $ready = true;

                    break;
                }
            }

            if (! $ready) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does at least one free DPS have a real offensive cooldown ready? Healers are excluded for
     * the same reason cooldownCadence() excludes them — a healer's classified offensive
     * cooldown is not what a comp aligns a go to.
     */
    private function hasReadyBurst(array $team, float $t): bool
    {
        foreach ($team['players'] as $player) {
            if ($player['role'] === 'healer' || $player['controlledUntil'] > $t) {
                continue;
            }

            foreach ($player['offensive'] as $entry) {
                if (($entry['cooldown'] ?? null) !== null && $entry['readyAt'] <= $t) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Works out who this team would control, with what, if it committed a go at $t — without
     * changing anything. Called twice per go: once by wantsToGo() to decide whether there is a
     * go to commit at all, and once by resolveGo() to apply it. One allocator rather than two
     * so the decision and the resolution can never disagree.
     *
     * The allocation is Part 6's: "what a CC is *for* falls out of solving the allocation."
     * Healer control first, because a free healer is what neutralises a go (Part 5's worked
     * example is entirely about this); then whoever else is reachable; never the kill target,
     * which is the player the go is trying to kill rather than silence.
     *
     * A control whose DR multiplier has reached immune contributes nothing and is not spent —
     * which is the mechanism that gives a comp a real cadence instead of one bounded only by
     * its cheapest stun's cooldown.
     *
     * @return array<int, array{by: int, entry: int, victim: int, spell: string, category: string, seconds: float, cooldown: float, diminished: bool}>
     */
    private function planControl(array $team, array $enemy, float $t, ?int $targetIndex): array
    {
        $victims = [];
        foreach ($enemy['players'] as $index => $player) {
            if ($index === $targetIndex) {
                continue;
            }

            $victims[] = [$index, $player['role'] === 'healer' ? 0 : 1];
        }
        usort($victims, fn ($x, $y) => $x[1] <=> $y[1]);

        $plan = [];

        foreach ($victims as [$victimIndex]) {
            foreach ($team['players'] as $playerIndex => $player) {
                if ($player['controlledUntil'] > $t) {
                    continue;
                }

                // One control per player per go — the thing being counted is globals denied,
                // and a player only has one global.
                foreach ($plan as $step) {
                    if ($step['by'] === $playerIndex) {
                        continue 2;
                    }
                }

                foreach ($player['control'] as $entryIndex => $entry) {
                    if (! $entry['hard'] || ($entry['cooldown'] ?? null) === null || $entry['readyAt'] > $t) {
                        continue;
                    }

                    $base = $entry['duration'] ?? self::DEFAULT_CONTROL_SECONDS;
                    $seconds = $this->effectiveControlSeconds($enemy['players'][$victimIndex], $entry['drCategory'], $base, $t);

                    if ($seconds <= 0.0) {
                        continue;
                    }

                    $plan[] = [
                        'by' => $playerIndex,
                        'entry' => $entryIndex,
                        'victim' => $victimIndex,
                        'spell' => $entry['name'],
                        'category' => $entry['drCategory'],
                        'seconds' => $seconds,
                        'cooldown' => (float) $entry['cooldown'],
                        'diminished' => $seconds < $base,
                    ];

                    continue 3;
                }
            }
        }

        return $plan;
    }

    /**
     * Part 19.2's first rung, and the honest limit of it. A defending team answers the threat by
     * controlling the source when somebody who is free has hard control or an interrupt ready.
     *
     * [HYP], and flagged as such wherever it surfaces: the ordering "control before cooldowns"
     * is reasoned from cooldown cost, not observed. Part 6 prices control on an amplified enemy
     * as paid twice, which is the nearest thing to evidence for it, and C13 in
     * arena-open-questions.md is the question that would settle it.
     *
     * A PEEL HAS TO COME FROM A TEAMMATE. The kill target is excluded even though they are not
     * in CC — a player being trained is not the one peeling, and Part 5's whole criterion is
     * about which of their players is **left free to answer**. That exclusion is what makes this
     * rung fail against a well-formed go, which is the correct behaviour rather than a gap: a go
     * that denies the healer and the off-DPS leaves nobody to peel, and a sequence with no good
     * reply is precisely the zugzwang Part 5 says to aim for. Without the exclusion the target
     * peels for themselves every time, no answer is ever spent, and no matchup ever resolves —
     * which is what the first run of this engine produced.
     */
    private function tryPeel(array &$defenders, float $t, ?int $targetIndex): ?array
    {
        foreach ($defenders['players'] as $playerIndex => $player) {
            if ($playerIndex === $targetIndex || $player['controlledUntil'] > $t) {
                continue;
            }

            foreach ($player['control'] as $entryIndex => $entry) {
                if (($entry['cooldown'] ?? null) === null || $entry['readyAt'] > $t) {
                    continue;
                }

                if (! $entry['hard'] && ! $entry['isPeel'] && $entry['drCategory'] !== 'Disarm') {
                    continue;
                }

                $defenders['players'][$playerIndex]['control'][$entryIndex]['readyAt'] = $t + $entry['cooldown'];

                return ['by' => $player['name'], 'spell' => $entry['name']];
            }
        }

        return null;
    }

    /**
     * How many answers one go costs the defender.
     *
     * The base number is the execution setting (Part 19.3): answering late means a second answer
     * lands on top of a first that was still live, which is Part 3's overlap and the reason it
     * gets its own part in the model.
     *
     * The additions are dampening (Part 12 / 19.4) — as the value of every answer decays, one
     * button stops being enough. The thresholds are assumptions; see the constants.
     */
    private function answersRequired(float $t, string $execution): int
    {
        $cost = $execution === self::EXECUTION_LATE ? 2 : 1;

        if ($t >= self::DAMPENING_FIRST_STEP_SECONDS) {
            $cost++;
        }

        if ($t >= self::DAMPENING_SECOND_STEP_SECONDS) {
            $cost++;
        }

        return $cost;
    }

    /**
     * Part 2, the central rule: the kill target is whoever's list is shortest RIGHT NOW,
     * re-derived every go rather than decided at the opening.
     *
     * A player in CC is not chosen as the kill target here even though their reachable pool is
     * zero — the go allocates control to the players it is not trying to kill (Part 5), so a
     * controlled player is by construction not the target of this go.
     */
    private function weakestTarget(array $team, float $t): ?int
    {
        $best = null;
        $bestCount = null;

        foreach ($team['players'] as $index => $player) {
            if ($player['controlledUntil'] > $t) {
                continue;
            }

            $count = count($this->liveAnswers($player, $t));

            if ($bestCount === null || $count < $bestCount) {
                $best = $index;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * The answers this player can press at $t — indexes into their own answer list, in spend
     * order (cheapest first, trinket last).
     *
     * Part 19.1: owning a button and being able to press it are different things. A controlled
     * player's reachable pool is zero whatever is off cooldown, and that is the single term the
     * curve representation adds over the prose statement of Part 2.
     *
     * @return array<int, int>
     */
    private function liveAnswers(array $player, float $t): array
    {
        if ($player['controlledUntil'] > $t) {
            return [];
        }

        $live = [];

        foreach ($player['answers'] as $index => $answer) {
            if ($answer['readyAt'] <= $t) {
                $live[] = $index;
            }
        }

        return $live;
    }

    /**
     * The threat curve. NOT damage — Part 11's blocker means this project cannot compute damage,
     * and a curve drawn as though it were would be fabrication. This is availability: what share
     * of the team's offensive cooldowns are up, weighted toward the long ones (a 3-minute
     * cooldown is more of the team's capacity than a 45-second one), lifted by how many enemy
     * players the team can reach at once right now.
     */
    private function threatLevel(array $team, float $t): int
    {
        $weight = 0.0;
        $total = 0.0;
        $reach = 0;

        foreach ($team['players'] as $player) {
            if ($player['controlledUntil'] > $t) {
                continue;
            }

            foreach ($player['offensive'] as $entry) {
                $cd = $entry['cooldown'] ?? null;
                if ($cd === null) {
                    continue;
                }

                $total += $cd;
                if ($entry['readyAt'] <= $t) {
                    $weight += $cd;
                }
            }

            foreach ($player['control'] as $entry) {
                if ($entry['hard'] && $entry['readyAt'] <= $t) {
                    $reach++;

                    break;
                }
            }
        }

        if ($total <= 0) {
            return 0;
        }

        $availability = $weight / $total;
        $reachShare = min(3, $reach) / 3;

        // Half availability, half reach. A team with every cooldown up and no control ready
        // cannot produce a go worth the name (Part 5: a go that denies nothing is a strip at
        // best), and a team with all its control up and nothing to follow it with is the same
        // problem from the other side.
        return (int) round(100 * (0.5 * $availability + 0.5 * $reachShare));
    }

    /**
     * The answer curves — one per player, never one per team. Part 2: "the pool is per-player,
     * not per-team. Their healer holding three externals does not help a target the healer
     * cannot reach." The Gemini source drew a single team line; Part 19.1 records why that is
     * the wrong shape.
     *
     * @return array<int, array{name: string, role: string, live: int, total: int, controlled: bool}>
     */
    private function poolLevels(array $team, float $t): array
    {
        $out = [];

        foreach ($team['players'] as $player) {
            $out[] = [
                'name' => $player['name'],
                'role' => $player['role'],
                'live' => count($this->liveAnswers($player, $t)),
                'total' => count($player['answers']),
                'controlled' => $player['controlledUntil'] > $t,
            ];
        }

        return $out;
    }

    private function teamSummary(array $team): array
    {
        $players = [];

        foreach ($team['players'] as $player) {
            $players[] = [
                'name' => $player['name'],
                'role' => $player['role'],
                'class' => $player['class'],
                'spec' => $player['spec'],
                'answers' => count($player['answers']),
                'answersSpent' => $player['answersSpent'],
                'hardControl' => count(array_filter($player['control'], fn ($c) => $c['hard'])),
                'offensive' => count($player['offensive']),
            ];
        }

        return [
            'players' => $players,
            'goes' => $team['goes'],
            'controlCadence' => $team['controlCadence'],
            'cooldownCadence' => $team['cooldownCadence'],
            'reach' => $team['reach'],
            'poolSize' => array_sum(array_column($players, 'answers')),
        ];
    }

    /**
     * The structural read. Deliberately not a probability — see the class docblock.
     *
     * The primary term is which side's first kill window opens earlier, because that is the
     * thing the whole timeline is built to locate. Everything else is a reason, not a score, and
     * the reasons are given in the model's vocabulary so a reader can disagree with a specific
     * claim rather than with a number.
     */
    private function verdict(array $a, array $b, array $events): array
    {
        $firstA = null;
        $firstB = null;

        foreach ($events as $event) {
            if (! $event['killWindow']) {
                continue;
            }

            if ($event['side'] === 'a' && $firstA === null) {
                $firstA = $event;
            }

            if ($event['side'] === 'b' && $firstB === null) {
                $firstB = $event;
            }
        }

        $reasons = $this->reasons($a, $b);

        if ($firstA === null && $firstB === null) {
            return [
                'favoured' => null,
                'headline' => 'Neither side empties a pool inside six minutes.',
                'detail' => 'On these cooldowns alone, this matchup does not resolve through a go — it resolves through the clock. That is Part 12\'s case: the question stops being "whose window opens first" and becomes which side dampening favours, which this engine does not attempt to answer.',
                'reasons' => $reasons,
                'firstWindow' => ['a' => null, 'b' => null],
            ];
        }

        if ($firstA !== null && ($firstB === null || $firstA['t'] < $firstB['t'])) {
            $favoured = 'a';
        } elseif ($firstB !== null && ($firstA === null || $firstB['t'] < $firstA['t'])) {
            $favoured = 'b';
        } else {
            $favoured = null;
        }

        if ($favoured === null) {
            return [
                'favoured' => null,
                'headline' => 'Both sides reach a kill window at the same moment.',
                'detail' => 'The structure is symmetric on these terms. Which of the two lands is decided by things this engine does not hold — positioning, who calls it first, and whether the go is executed cleanly.',
                'reasons' => $reasons,
                'firstWindow' => ['a' => $firstA['t'] ?? null, 'b' => $firstB['t'] ?? null],
            ];
        }

        $winner = $favoured === 'a' ? $firstA : $firstB;
        $loser = $favoured === 'a' ? $firstB : $firstA;
        $label = $favoured === 'a' ? 'Team A' : 'Team B';

        $margin = $loser === null
            ? null
            : $loser['t'] - $winner['t'];

        return [
            'favoured' => $favoured,
            'headline' => "{$label}'s window opens first, at ".$this->clock($winner['t']).'.',
            'detail' => $loser === null
                ? 'The other side does not reach an empty pool inside six minutes at all, on these cooldowns.'
                : $this->marginSentence($loser, $margin, $favoured === 'a' ? $a : $b),
            'target' => $winner['target'],
            'reasons' => $reasons,
            'firstWindow' => ['a' => $firstA['t'] ?? null, 'b' => $firstB['t'] ?? null],
        ];
    }

    /**
     * States the margin in goes as well as in seconds, because Part 7 is that top players do not
     * count seconds — they count how many goes are left before something returns. Forty seconds
     * means nothing on its own; "one more go than they get" is the same fact in the unit a
     * player plans in.
     */
    private function marginSentence(array $loser, int $margin, array $favouredTeam): string
    {
        $cadence = $favouredTeam['controlCadence'];
        $sentence = "The other side's first window is at ".$this->clock($loser['t'])." — {$margin} seconds later.";

        if ($cadence === null || $cadence <= 0) {
            return $sentence;
        }

        $goes = (int) floor($margin / $cadence);

        if ($goes < 1) {
            return $sentence.' That is less than one full go apart, which is inside the margin of everything this model does not hold.';
        }

        return $sentence.' At a '.$cadence.'s cadence that is '.$goes.' extra '.($goes === 1 ? 'go' : 'goes').' before they get theirs.';
    }

    /**
     * The structural terms that differ between the two comps, each stated as a claim a reader
     * can disagree with, in arena-structure.md's vocabulary. Deliberately NOT summed into a
     * score: a score would imply the terms have known relative weights, and nothing establishes
     * that they do.
     *
     * @return array<int, array{term: string, favours: string, text: string}>
     */
    private function reasons(array $a, array $b): array
    {
        $reasons = [];

        if ($a['reach'] !== $b['reach']) {
            $side = $a['reach'] > $b['reach'] ? 'a' : 'b';
            $high = max($a['reach'], $b['reach']);
            $low = min($a['reach'], $b['reach']);
            $reasons[] = [
                'term' => 'Globals denied',
                'favours' => $side,
                'text' => "Reaches {$high} of the three at once against {$low}. Part 5 scores a go by how many enemy players it leaves with a free global, and a free player is the one who neutralises it.",
            ];
        }

        if ($a['controlCadence'] !== null && $b['controlCadence'] !== null && $a['controlCadence'] != $b['controlCadence']) {
            $side = $a['controlCadence'] < $b['controlCadence'] ? 'a' : 'b';
            $fast = min($a['controlCadence'], $b['controlCadence']);
            $slow = max($a['controlCadence'], $b['controlCadence']);
            $reasons[] = [
                'term' => 'Go cadence',
                'favours' => $side,
                'text' => "Can bring a coordinated go every {$fast}s against {$slow}s. More goes in the same round is more chances for one of them to land on an empty pool.",
            ];
        }

        $poolA = 0;
        $poolB = 0;
        foreach ($a['players'] as $player) {
            $poolA += count($player['answers']);
        }
        foreach ($b['players'] as $player) {
            $poolB += count($player['answers']);
        }

        if ($poolA !== $poolB) {
            $side = $poolA > $poolB ? 'a' : 'b';
            $reasons[] = [
                'term' => 'Answer pool',
                'favours' => $side,
                'text' => 'Holds '.max($poolA, $poolB).' answers across the three players against '.min($poolA, $poolB).'. Part 2: a kill is a calculation against a list, not against a health bar.',
            ];
        }

        $thinnestA = $this->thinnestPlayer($a);
        $thinnestB = $this->thinnestPlayer($b);

        if ($thinnestA !== null && $thinnestB !== null && $thinnestA['answers'] !== $thinnestB['answers']) {
            $side = $thinnestA['answers'] > $thinnestB['answers'] ? 'a' : 'b';
            $exposed = $thinnestA['answers'] > $thinnestB['answers'] ? $thinnestB : $thinnestA;
            $reasons[] = [
                'term' => 'Thinnest list',
                'favours' => $side,
                'text' => "The other side's {$exposed['name']} holds only {$exposed['answers']} answers, which is where every go gets pointed — Part 2 re-derives the kill target as whoever's list is shortest.",
            ];
        }

        return $reasons;
    }

    private function thinnestPlayer(array $team): ?array
    {
        $thinnest = null;

        foreach ($team['players'] as $player) {
            $count = count($player['answers']);

            if ($thinnest === null || $count < $thinnest['answers']) {
                $thinnest = ['name' => $player['name'], 'answers' => $count];
            }
        }

        return $thinnest;
    }

    /**
     * Part 3's per-matchup lookup — "enemy presses X → you press Y" — rebuilt as Part 19.2's
     * ranked cascade, because there is rarely one Y.
     *
     * Every row is one of the attacking team's real offensive cooldowns, with the defending
     * team's options ranked cheapest-sufficient-first: control the source, break away, spend a
     * cooldown, trinket last. Nothing here is simulated; it is a static read of both kits, which
     * is why Part 3 calls it the single most concretely buildable thing in the model and notes
     * it needs no new data.
     *
     * The ORDER is [HYP] (Part 19.2, question C13). The contents are not — every ability named
     * is one the spec really has, at its real talent-adjusted cooldown.
     */
    private function triggerTable(array $attackers, array $defenders): array
    {
        $rows = [];

        foreach ($attackers['players'] as $attacker) {
            foreach ($attacker['offensive'] as $threat) {
                if (($threat['cooldown'] ?? null) === null) {
                    continue;
                }

                $options = [];
                $seenTrinket = false;

                foreach ($defenders['players'] as $defender) {
                    foreach ($defender['control'] as $entry) {
                        if (($entry['cooldown'] ?? null) === null) {
                            continue;
                        }

                        if ($entry['hard'] || $entry['drCategory'] === 'Disarm' || $entry['isPeel']) {
                            $options[] = [
                                'rung' => 'control the source',
                                'by' => $defender['name'],
                                'spell' => $entry['name'],
                                'icon' => $entry['icon'],
                                'cooldown' => $entry['cooldown'],
                                'hard' => $entry['hard'],
                                'covers' => null,
                                'note' => $entry['drCategory'].($entry['duration'] === null ? '' : ', '.$entry['duration'].'s'),
                            ];
                        }
                    }
                }

                foreach ($defenders['players'] as $defender) {
                    foreach ($defender['answers'] as $answer) {
                        // Every player carries the same trinket, so listing it three times is
                        // three rows saying one thing. One row, and the page says whose.
                        if ($answer['kind'] === 'trinket') {
                            if ($seenTrinket) {
                                continue;
                            }

                            $seenTrinket = true;
                        }

                        $options[] = [
                            'rung' => $answer['kind'] === 'trinket' ? 'trinket' : ($answer['kind'] === 'immunity' ? 'immunity' : 'spend a cooldown'),
                            'by' => $answer['kind'] === 'trinket' ? 'any of them' : $defender['name'],
                            'spell' => $answer['name'],
                            'icon' => $answer['icon'],
                            'cooldown' => $answer['cooldown'],
                            'hard' => false,
                            'covers' => $this->covers($answer['duration'], $threat['duration']),
                            'note' => $answer['duration'] === null ? null : $answer['duration'].'s',
                        ];
                    }
                }

                $rows[] = [
                    'threat' => $threat['name'],
                    'icon' => $threat['icon'],
                    'by' => $attacker['name'],
                    'cooldown' => $threat['cooldown'],
                    'duration' => $threat['duration'],
                    'options' => $this->rankCascade($options),
                ];
            }
        }

        usort($rows, fn ($x, $y) => $y['cooldown'] <=> $x['cooldown']);

        return $rows;
    }

    /**
     * Ranks one threat's options as a cascade rather than a flat sorted list.
     *
     * Takes the best TWO of each rung rather than the best five overall, because a flat sort
     * puts the whole control rung at the top and the reader never sees the cooldown that is
     * actually the right answer when nobody is free to peel. The point of Part 19.2 is that the
     * options are different KINDS, and a list that shows only the cheapest kind is Part 3's
     * one-to-one lookup again with extra steps.
     *
     * @param  array<int, array>  $options
     * @return array<int, array>
     */
    private function rankCascade(array $options): array
    {
        $rungs = ['control the source', 'spend a cooldown', 'immunity', 'trinket'];
        $out = [];

        foreach ($rungs as $rung) {
            $inRung = array_values(array_filter($options, fn ($o) => $o['rung'] === $rung));

            usort($inRung, function ($x, $y) {
                // Hard control outranks a root or a disarm on the same rung: it denies globals,
                // which is the thing Part 5 is counting.
                if ($x['hard'] !== $y['hard']) {
                    return $x['hard'] ? -1 : 1;
                }

                // An answer that outlasts the threat is a different kind of answer from one that
                // does not. Part 3's corollary is the whole reason: "an 8-second defensive
                // against two stacked 20-second offensive cooldowns buys 8 seconds and then
                // leaves you exposed for 12."
                if ($x['covers'] !== $y['covers'] && $x['covers'] !== null && $y['covers'] !== null) {
                    return $x['covers'] ? -1 : 1;
                }

                return $x['cooldown'] <=> $y['cooldown'];
            });

            foreach (array_slice($inRung, 0, 2) as $option) {
                $out[] = $option;
            }
        }

        return $out;
    }

    /**
     * Does an answer of $answerSeconds outlast a threat of $threatSeconds?
     *
     * Null — not false — when either number is missing, and the page prints that as "duration
     * not recorded" rather than as a shrug. A missing duration is common (`duration_seconds` is
     * absent on plenty of real rows) and guessing it would quietly turn a hole into a claim.
     */
    private function covers(?float $answerSeconds, ?float $threatSeconds): ?bool
    {
        if ($answerSeconds === null || $threatSeconds === null) {
            return null;
        }

        return $answerSeconds >= $threatSeconds;
    }

    public function clock(int|float $seconds): string
    {
        $seconds = (int) round($seconds);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
