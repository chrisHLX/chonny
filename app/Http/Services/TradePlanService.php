<?php

namespace App\Http\Services;

use App\Models\Patch;
use App\Models\Spell;

/**
 * How one side trades its answers against the other side's goes, read off a Matchup Lab run
 * (CooldownGraphService::run) and worded as a plan (2026-10-07).
 *
 * WHY. The counter to a go is trading, and the archive says what good trading is NOT: spending
 * more. Within a rating band, the side that spent more defensives per enemy go lost more (41% wins
 * against 61%), and the number of defensives a go drew did not predict whether it killed. What did:
 * the state at the next exchange. Their go met two or more of a team's big answers down: killed
 * 35–43% of the time, against 17–22% with one down (docs/learning/population-findings-2026-10-07-
 * trading.md). So a trade is judged by what is still up when their NEXT go comes, which makes it a
 * scheduling question: their cadence against your cooldowns.
 *
 * WHAT IT ADDS TO THE ENGINE'S RUN:
 *  - each enemy go with the answers it took and, for two or more on one go, their combined
 *    reduction. Reductions MULTIPLY, they do not add: Barkskin 20% and Survival Instincts 50% let
 *    0.8 × 0.5 = 40% through (60% less, not 70%). Values are the spell data's own "Modify Damage
 *    Taken%" effect, read by name across a spell's copies (rule 3); an immunity counts as 100%;
 *    a value the data does not hold is left out of the sum and said so, never guessed;
 *  - what the next go's target still had off cooldown when it came, from the spends themselves;
 *  - advice from the cadence: against a team that goes faster than your big answers come back,
 *    alternate the short ones and hold the long ones; against one that goes rarely, two answers on
 *    one go are affordable.
 *
 * It inherits every limit of the engine (MatchupLab::limitations()): no damage model, no win
 * chance, cooldowns as the slowest they could be. Cooldowns shortened in play (Savage Momentum's
 * 10s per kick) are not in the data either; the trading note says so.
 */
class TradePlanService
{
    /** Full immunities: everything stops while they hold. */
    private const IMMUNITIES = ['Divine Shield', 'Ice Block', 'Aspect of the Turtle', 'Netherwalk', 'Blessing of Protection', 'Cloak of Shadows', 'Dispersion'];

    /** Seconds of slack: an answer back within this of their next go counts as back for it. */
    private const BACK_IN_TIME = 3;

    /** @var array<string, ?float> name => fraction of damage removed */
    private array $reductions = [];

    /**
     * @param  array  $result  CooldownGraphService::run()
     * @param  string  $defender  'a' or 'b'
     * @param  array  $answers  the defender's answers, [player name => [answer rows from the profile]]
     */
    public function plan(array $result, string $defender, array $answers): array
    {
        $attacker = $defender === 'a' ? 'b' : 'a';
        $goes = array_values(array_filter($result['events'], fn ($e) => $e['side'] === $attacker));

        $this->loadReductions(collect($answers)->flatten(1)->pluck('name')->merge(collect($goes)->flatMap(fn ($g) => array_column($g['spent'], 'spell')))->unique()->all());

        // When each spent answer comes back, from the spends themselves: what is OFF COOLDOWN at
        // the next go, whatever crowd control the player is in at that second. (The engine's own
        // pool reads zero for a controlled player, which is right for "can press now" and wrong
        // for "did this trade leave an answer for the next go".)
        $cooldownOf = [];
        foreach ($answers as $player => $list) {
            foreach ($list as $a) {
                $cooldownOf[$player][$a['name']] = $a['cooldown'] ?? null;
            }
        }
        $readyAt = [];

        $rows = [];
        foreach ($goes as $i => $go) {
            $next = $goes[$i + 1] ?? null;
            foreach ($go['spent'] as $s) {
                $cd = $cooldownOf[$s['player']][$s['spell']] ?? null;
                if ($cd) {
                    $readyAt[$s['player']][$s['spell']] = $go['t'] + $cd;
                }
            }
            $held = null;
            if ($next !== null && isset($cooldownOf[$next['target'] ?? ''])) {
                $held = collect($cooldownOf[$next['target']])
                    ->filter(fn ($cd, $name) => ($readyAt[$next['target']][$name] ?? 0) <= $next['t'])
                    ->count();
            }
            $spent = array_map(fn ($s) => $s + ['reduction' => $this->reductions[$s['spell']] ?? null], $go['spent']);
            $rows[] = [
                't' => $go['t'],
                'target' => $go['target'],
                'spent' => $spent,
                'stack' => count($spent) >= 2 ? $this->stack($spent) : null,
                'heldAtNext' => $held,
                'nextAt' => $next['t'] ?? null,
                'nextTarget' => $next['target'] ?? null,
                'killWindow' => $go['killWindow'],
                'lockedOut' => $go['lockedOut'],
            ];
        }

        $gaps = [];
        for ($i = 1; $i < count($goes); $i++) {
            $gaps[] = $goes[$i]['t'] - $goes[$i - 1]['t'];
        }
        sort($gaps);
        $cadence = $gaps ? $gaps[intdiv(count($gaps) - 1, 2)] : null;

        return [
            'attacker' => $attacker,
            'defender' => $defender,
            'cadence' => $cadence,
            'goes' => $rows,
            'answers' => $this->answerRows($answers),
            'advice' => $this->advice($rows, $cadence, $answers),
        ];
    }

    /**
     * Combined reduction of answers stacked on one go, multiplied, against what adding them up
     * would wrongly say, and what the last one added.
     */
    private function stack(array $spent): ?array
    {
        $known = array_values(array_filter($spent, fn ($s) => $s['reduction'] !== null));
        if (count($known) < 2) {
            return null;
        }

        $through = 1.0;
        $throughBeforeLast = 1.0;
        foreach ($known as $k => $s) {
            if ($k < count($known) - 1) {
                $throughBeforeLast *= 1 - $s['reduction'];
            }
            $through *= 1 - $s['reduction'];
        }

        return [
            'combined' => round((1 - $through) * 100),
            'added' => (int) round(min(100, array_sum(array_column($known, 'reduction')) * 100)),
            'lastAdds' => round(($throughBeforeLast - $through) * 100),
            'unknown' => count($spent) - count($known),
        ];
    }

    /** The plan in words, from their cadence against your answers' cooldowns. */
    private function advice(array $rows, ?float $cadence, array $answers): array
    {
        $out = [];
        $all = collect($answers)->flatten(1)->filter(fn ($a) => ($a['kind'] ?? null) !== 'trinket' && ($a['cooldown'] ?? null));

        if ($cadence !== null) {
            $short = $all->filter(fn ($a) => $a['cooldown'] <= $cadence + self::BACK_IN_TIME)->pluck('name')->unique()->values();
            $long = $all->filter(fn ($a) => $a['cooldown'] > $cadence + self::BACK_IN_TIME)->sortByDesc('cooldown')->pluck('name')->unique()->values();
            $every = $cadence >= 60 ? $this->clock($cadence) : (int) $cadence.'s';

            if ($cadence >= 120) {
                $out[] = "They go about every {$every}. Most of your answers are back before their next go, so meeting one go with two of them is affordable here.";
            } elseif ($short->count() >= 2) {
                $out[] = "They go about every {$every}. {$this->list($short)} come back between their goes: take turns with those, and keep {$this->list($long->take(3))} for the go that comes with their big cooldowns.";
            } else {
                $out[] = "They go about every {$every}, faster than nearly all your answers come back. One answer a go, never two: a second spent now is missing from the next go.";
            }
        }

        $stacked = collect($rows)->first(fn ($r) => $r['stack'] !== null);
        if ($stacked) {
            $names = implode(' + ', array_column(array_filter($stacked['spent'], fn ($s) => $s['reduction'] !== null), 'spell'));
            $out[] = "Stacking: {$names} cut the damage by {$stacked['stack']['combined']}%, not {$stacked['stack']['added']}%, because reductions multiply. The last one added only {$stacked['stack']['lastAdds']} points.";
        }

        $empty = collect($rows)->first(fn ($r) => $r['heldAtNext'] === 0);
        if ($empty) {
            $out[] = 'At '.$this->clock($empty['nextAt']).' their go meets '.$empty['nextTarget'].' with every answer still on cooldown from earlier goes. That is the trade to change: one answer held back before then is the one that is missing.';
        }

        return $out;
    }

    /** Every answer the defender holds, its cooldown and what it removes, longest cooldown first. */
    private function answerRows(array $answers): array
    {
        $rows = [];
        foreach ($answers as $player => $list) {
            foreach ($list as $a) {
                $rows[] = [
                    'player' => $player,
                    'spell' => $a['name'],
                    'icon' => $a['icon'] ?? null,
                    'cooldown' => $a['cooldown'] ?? null,
                    'kind' => $a['kind'] ?? null,
                    'reduction' => ($a['kind'] ?? null) === 'trinket' ? null : ($this->reductions[$a['name']] ?? null),
                ];
            }
        }
        usort($rows, fn ($x, $y) => ($y['cooldown'] ?? 0) <=> ($x['cooldown'] ?? 0));

        return $rows;
    }

    /**
     * Fraction of damage each named spell removes: an immunity 1.0, otherwise the strongest
     * all-school "Modify Damage Taken%" effect on any current copy of that name (rule 3: the
     * pressable copy often carries the emptier metadata). Null when no copy holds one.
     */
    private function loadReductions(array $names): void
    {
        $names = array_values(array_diff($names, array_keys($this->reductions)));
        if ($names === []) {
            return;
        }

        $patchId = Patch::where('is_current', true)->value('id');
        $found = [];
        Spell::where('patch_id', $patchId)
            // A copy can carry a "(desc=...)" suffix on its stored name (Spell::display_name).
            ->where(function ($q) use ($names) {
                $q->whereIn('name', $names);
                foreach ($names as $n) {
                    $q->orWhere('name', 'like', $n.' (desc=%');
                }
            })
            ->with(['effects' => fn ($q) => $q->where('type', 'Modify Damage Taken%')])
            ->get(['id', 'name'])
            ->each(function (Spell $s) use (&$found) {
                foreach ($s->effects as $e) {
                    if (($e->affected_schools === 'All' || $e->affected_schools === null) && $e->base_value < 0) {
                        $found[$s->display_name] = max($found[$s->display_name] ?? 0, min(100, -$e->base_value) / 100);
                    }
                }
            });

        foreach ($names as $name) {
            $this->reductions[$name] = in_array($name, self::IMMUNITIES, true) ? 1.0 : ($found[$name] ?? null);
        }
    }

    private function list($names): string
    {
        $names = collect($names)->values();

        return match ($names->count()) {
            0 => 'nothing',
            1 => $names[0],
            default => $names->slice(0, -1)->implode(', ').' and '.$names->last(),
        };
    }

    private function clock(float $s): string
    {
        return intdiv((int) $s, 60).':'.str_pad((string) ((int) $s % 60), 2, '0', STR_PAD_LEFT);
    }
}
