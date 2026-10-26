<?php

namespace App\Http\Services;

use App\Support\KickPriorities;

/**
 * One fall, broken down the way Chriso read 25 Oct 19:11 on video (2026-10-26): "the kidney shot
 * lands on the kill target almost exactly the same time CC lands on the healer", "cheap shot kidney
 * into polymorph into maim into clone", "I'm free, I should have stopped the clone". For a fall
 * (FallsService) it says, from the stored round alone:
 *
 *  - steps: what the attacking side did, in order, timed from the start of the fall; each control
 *    with its diminishing returns on that player (full, half, immune: two steps then immunity, reset
 *    after DR_RESET seconds without one, the rule CcChainBuilder and the Basics check state);
 *  - kick chances: a control that is a cast worth a kick for its caster's spec, by the kick list the
 *    archive has seen stopped (data/kick-priorities), with each player of the falling side who was
 *    free and had their interrupt ready as it came in. Whether they were in range or in sight the
 *    stored round cannot say, so it is never called a miss;
 *  - the falling side at the start: each player free, locked (by what, how long) or dead, and the
 *    defensives, interrupts and control they had ready, from the arena frames (each player's own
 *    buttons and presses; "ready" means not pressed within its cooldown, so it can be ready sooner);
 *  - what the attackers spent and what was answered.
 *
 * A description, never a verdict: what was there and what happened (match-review.md, rules 6 and 8).
 */
class FallBreakdownService
{
    /** Seconds without a control of a kind before its diminishing returns reset. */
    private const DR_RESET = 20.0;

    /** How long before a kickable control landed its cast is taken to have been coming in. */
    private const CAST_LEAD = 1.0;

    /**
     * @param  array<string, mixed>  $fall  one fall from FallsService::read()
     * @param  array<string, mixed>  $analysis  the stored round
     * @param  array<string, array<string, mixed>>  $frames  guid => that player's arena frame (WatchPageService::abilities())
     * @param  array<string, string>  $names  guid => name for the page
     * @return array<string, mixed>
     */
    public function for(array $fall, array $analysis, array $frames, array $names): array
    {
        $name = fn (?string $g) => $g === null ? '?' : ($names[$g] ?? '?');
        $side = [];
        $specKey = [];
        foreach ($analysis['players'] ?? [] as $p) {
            $side[$p['guid']] = $p['side'];
            $spec = isset($p['specExternalId']) ? app(ArenaLogService::class)->specForExternalId((string) $p['specExternalId']) : null;
            $specKey[$p['guid']] = ($p['classSlug'] ?? '?').'/'.($spec?->slug ?? $p['specSlug'] ?? '?');
        }
        $from = (float) $fall['from'];
        $falling = $side[$fall['on']] ?? null;
        $dr = $this->diminishing($analysis['timeline']['control'] ?? []);

        $steps = [];
        foreach ($fall['links'] as $l) {
            $step = [
                'at' => round($l['t'] - $from, 1),
                't' => $l['t'],
                'cat' => $l['cat'],
                'text' => $l['cat'] === 'control' ? $l['spell'].' on '.$name($l['on']) : $l['spell'],
                'by' => $name($l['by']),
            ];
            if ($l['cat'] === 'control') {
                $level = $dr[$l['on'].'|'.$l['t']] ?? 1;
                $step['dr'] = $l['dr'].', '.($level === 1 ? 'full' : ($level === 2 ? 'half' : 'immune'));
                if ($this->kickable($l['spell'], $specKey[$l['by']] ?? '')) {
                    $at = $l['t'] - self::CAST_LEAD;
                    $free = [];
                    foreach ($frames as $g => $f) {
                        if (($side[$g] ?? null) !== $falling || $this->lockedAt($f, $at) !== null || $this->deadAt($f, $at)) {
                            continue;
                        }
                        foreach ($f['abilities'] as $a) {
                            if ($a['kind'] === 'interrupts' && $this->readyAt($a, $at)) {
                                $free[] = $f['name'].' ('.$a['name'].' ready)';
                                break;
                            }
                        }
                    }
                    $step['kick'] = $free;
                }
            }
            $steps[] = $step;
        }

        $defenders = [];
        foreach ($frames as $g => $f) {
            if (($side[$g] ?? null) !== $falling) {
                continue;
            }
            $lock = $this->lockedAt($f, $from);
            $ready = ['answers' => [], 'interrupts' => [], 'control' => []];
            foreach ($f['abilities'] as $a) {
                if (isset($ready[$a['kind']]) && $this->readyAt($a, $from)) {
                    $ready[$a['kind']][] = $a['name'];
                }
            }
            $defenders[] = [
                'name' => $f['name'],
                'color' => $f['color'],
                'state' => $this->deadAt($f, $from) ? 'dead' : ($lock ? sprintf('%s, %ds left', $lock[2], (int) ceil($lock[1] - $from)) : 'free'),
                'ready' => $ready,
            ];
        }

        return [
            'steps' => $steps,
            'defenders' => $defenders,
            'spent' => array_values(array_map(fn ($l) => $l['spell'].' ('.$name($l['by']).')', array_filter($fall['links'], fn ($l) => $l['cat'] === 'offensive'))),
            'answered' => array_map(fn ($a) => $a['spell'].' ('.$name($a['who']).')', $fall['answers']),
        ];
    }

    /**
     * Each control's diminishing-returns step on its player: 1 full, 2 half, 3 immune, counted per
     * player and kind and reset after DR_RESET seconds with none of that kind on them.
     *
     * @return array<string, int> "guid|from" => step
     */
    private function diminishing(array $control): array
    {
        usort($control, fn ($a, $b) => $a[4] <=> $b[4]);
        $state = [];
        $out = [];
        foreach ($control as [$on, , , $kind, $from, $to]) {
            $key = $on.'|'.$kind;
            $s = $state[$key] ?? ['count' => 0, 'end' => -INF];
            if ($from - $s['end'] >= self::DR_RESET) {
                $s['count'] = 0;
            }
            $s['count']++;
            $s['end'] = max($s['end'], (float) $to);
            $state[$key] = $s;
            $out[$on.'|'.round((float) $from, 1)] = min(3, $s['count']);
        }

        return $out;
    }

    /** A cast worth a kick for its caster's spec, by the list the archive has seen stopped. */
    private function kickable(string $spell, string $specKey): bool
    {
        static $sets = [];
        $sets[$specKey] ??= array_fill_keys(array_column(array_filter(KickPriorities::forSpec($specKey), fn ($r) => $r['counts'] && ! $r['channel']), 'name'), true);

        return isset($sets[$specKey][$spell]);
    }

    /** The lockout on this player at $t, as [from, to, spell], or null. */
    private function lockedAt(array $frame, float $t): ?array
    {
        foreach ($frame['locked'] as $l) {
            if ($t >= $l[0] && $t < $l[1]) {
                return $l;
            }
        }

        return null;
    }

    private function deadAt(array $frame, float $t): bool
    {
        return $frame['died'] !== null && $t >= $frame['died'];
    }

    /** Whether a button had a charge at $t, by its presses and cooldown (as the page's frames). */
    public function readyAt(array $a, float $t): bool
    {
        $max = max(1, (int) $a['charges']);
        $ready = $max;
        $back = null;
        foreach ($a['presses'] as $p) {
            if ($p > $t) {
                break;
            }
            while ($ready < $max && $back !== null && $back <= $p) {
                $ready++;
                $back = $ready < $max ? $back + $a['cd'] : null;
            }
            if ($ready < 1) {
                $ready = 1;
                $back = $ready < $max ? $back : null;
            }
            $ready--;
            $back ??= $p + $a['cd'];
        }
        while ($ready < $max && $back !== null && $back <= $t) {
            $ready++;
            $back = $ready < $max ? $back + $a['cd'] : null;
        }

        return $ready > 0;
    }
}
