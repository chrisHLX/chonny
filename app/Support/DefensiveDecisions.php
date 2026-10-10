<?php

namespace App\Support;

use App\Http\Services\CooldownLedgerService;

/**
 * Situation, choice, outcome for every enemy go in one stored round (RoundAnalysisService VERSION 12
 * or later), from the side defending it (2026-10-10, docs/learning/population-findings-2026-10-10-decisions.md).
 *
 * One classifier for both readers, so they cannot drift apart: wow:population counts these rows over
 * every game into norms.json (`decisions`), and the Basics tab sets a game's own rows against those
 * counts. tools/match-review/decisions.php is the research version of the same read.
 *
 *  - situation: `bigDown` (0, 1, 2 = two or more big answers already on cooldown as the go started)
 *    and `pressure` (heavy: two or more attackers pressed offensive cooldowns, or the defenders'
 *    healer was locked in the go's peak; only the attacker's inputs, since peak damage is measured
 *    after mitigation and so depends on the choice being judged);
 *  - choice: `none` (nothing pressed though something was pressable), `forced` (nothing pressable:
 *    every answer down or its owner locked out), `early` (the first answer went out before its target
 *    was in danger), `late` (only once in danger); and `count`, answers drawn (0, 1, 2 = two or more).
 *    Gladiator's Medallion is set apart: it answers control, not damage;
 *  - outcome: `died`, a defender died in the go or within 30s of its end.
 *
 * Goes starting after the round's first death are left out: defensive rows stop there, so such a go
 * would read as "pressed nothing".
 */
class DefensiveDecisions
{
    /**
     * @param  array  $analysis  one stored round's analysis
     * @return array<int, array{defending: string, bigDown: int, pressure: string, choice: string, count: int, died: bool, won: bool}>
     */
    public static function goRows(array $analysis): array
    {
        if (($analysis['version'] ?? 0) < 12) {
            return [];
        }
        $firstDeath = $analysis['deaths'][0]['t'] ?? INF;
        $won = (bool) ($analysis['won'] ?? false);
        $rows = [];

        foreach ($analysis['goes'] ?? [] as $go) {
            if (! is_array($go['cover'] ?? null) || (float) $go['from'] >= $firstDeath) {
                continue;
            }
            $defending = $go['side'] === 'us' ? 'them' : 'us';

            $pressers = [];
            foreach ($go['links'] ?? [] as $l) {
                if (in_array($l['cat'], ['offensive', 'mixed'], true) && $l['by']) {
                    $pressers[$l['by']] = true;
                }
            }
            $heavy = count($pressers) >= 2 || (float) ($go['peak']['healerLocked'] ?? 0) > 0;

            $answers = array_values(array_filter($analysis['defensives'][$defending]['rows'] ?? [],
                fn ($r) => $r['spell'] !== CooldownLedgerService::MEDALLION && $r['t'] >= $go['from'] && $r['t'] <= $go['to']));
            $pressable = array_filter($go['cover']['answers'] ?? [], fn ($x) => $x['up'] && ! ($x['locked'] ?? false));

            if ($answers === []) {
                $choice = $pressable ? 'none' : 'forced';
            } else {
                $choice = in_array('danger', $answers[0]['reasons'] ?? [], true) ? 'late' : 'early';
            }

            $rows[] = [
                'defending' => $defending,
                'bigDown' => min(2, (int) $go['cover']['bigDown']),
                'pressure' => $heavy ? 'heavy' : 'light',
                'choice' => $choice,
                'count' => min(2, count($answers)),
                'died' => (bool) $go['killLater'],
                // The round from the defenders' side: `won` is the logging side's result.
                'won' => $defending === 'us' ? $won : ! $won,
            ];
        }

        return $rows;
    }

    /** The cell a row belongs to in norms.json's `decisions` table. */
    public static function cell(array $row): string
    {
        return $row['bigDown'].'|'.$row['pressure'];
    }
}
