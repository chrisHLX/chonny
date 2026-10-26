<?php

namespace App\Http\Services;

/**
 * Falls: goes found from pressure first (2026-10-26; match-review-operations.md, "The go, revised").
 *
 * Chriso watched 25 Oct 19:11 against the Watch page and every rule that cut goes by cooldown timing
 * failed: a team presses something every 10-20s, so where one go ended was arbitrary, and one ran
 * 105s. A fall is where a player's health dropped DROP+ points, until back within RECOVER or dead;
 * what the other side committed from LEAD seconds before it (offensive cooldowns, lockout control)
 * is attached as what caused it. Each fall is:
 *  - cooldown: an offensive cooldown is in it;
 *  - control: none is, but control is. The Rogue's Kidney Shot landing with control on our healer
 *    took Doubletapz to 5% with nothing pressed; under the go rule it was not a go at all, and
 *    Chriso, on the video: "100% they are goes";
 *  - damage: neither.
 * `sync` is the closest control on the fallen player and on their healer, and how far apart they
 * landed. Offensive cooldowns inside no fall moved nobody (`unmoved`), `alone` when none of their
 * side's control landed within 5s. Both are questions for the video, never faults.
 *
 * Read from a stored round (RoundAnalysisService version 16+) when a page is drawn, not measured:
 * health a second at a time and every control span are in `timeline`, the offensive presses in the
 * goes' links, the defensives in `defensives`. So the thresholds can be tuned per comp without
 * measuring again, and no app has to send its games again. The old `goes` stay beside falls until
 * each page reading them has moved over.
 */
class FallsService
{
    /** A fall: a player's health this many points below where it stood. */
    public const DROP = 20;

    /** It ends when they are back within this many points of it (or dead). */
    public const RECOVER = 10;

    /** The attacking side's commitment counts from this long before the fall began. */
    public const LEAD = 10.0;

    /** A high older than this, with health sliding, is reset: a slow slide is not one fall. */
    private const SLIDE = 8;

    /** An offensive cooldown counts as live this long after its press (for intensity). */
    private const LIVE = 10.0;

    /** Control a player cannot act through. */
    private const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];

    /**
     * @param  array<string, mixed>  $analysis  a stored round's `analysis`
     * @return array{falls: array<int, array<string, mixed>>, unmoved: array<int, array<string, mixed>>}
     */
    public function read(array $analysis): array
    {
        $hp = $analysis['timeline']['hp'] ?? null;
        if (! is_array($hp) || ($analysis['version'] ?? 0) < 16) {
            return ['falls' => [], 'unmoved' => []];
        }

        $side = [];
        $healer = [];
        foreach ($analysis['players'] ?? [] as $p) {
            $side[$p['guid']] = $p['side'];
            if (! empty($p['healer'])) {
                $healer[$p['side']] = $p['guid'];
            }
        }
        $died = [];
        foreach ($analysis['deaths'] ?? [] as $d) {
            $died[$d['who']] ??= (float) $d['t'];
        }

        // Every offensive press is in some go's links: each one starts a chain or joins one.
        $offensive = [];
        foreach ($analysis['goes'] ?? [] as $g) {
            foreach ($g['links'] ?? [] as $l) {
                if ($l['cat'] === 'offensive' && isset($side[$l['by'] ?? ''])) {
                    $offensive[] = ['t' => round($g['from'] + $l['t'], 1), 'spell' => $l['spell'], 'by' => $l['by']];
                }
            }
        }
        usort($offensive, fn ($a, $b) => $a['t'] <=> $b['t']);
        $control = [];
        foreach ($analysis['timeline']['control'] ?? [] as [$on, $by, $spell, $dr, $from, $to]) {
            if (in_array($dr, self::LOCKOUT, true) && isset($side[$on], $side[$by]) && $side[$on] !== $side[$by]) {
                $control[] = ['on' => $on, 'by' => $by, 'spell' => $spell, 'dr' => $dr, 'from' => (float) $from, 'to' => (float) $to];
            }
        }
        $defensive = [];
        foreach (['us', 'them'] as $s) {
            foreach ($analysis['defensives'][$s]['rows'] ?? [] as $row) {
                $defensive[] = ['t' => (float) $row['t'], 'spell' => $row['spell'], 'who' => $row['who'], 'side' => $s];
            }
        }

        $found = [];
        foreach ($hp as $g => $series) {
            if (isset($side[$g])) {
                foreach ($this->episodes($series, $died[$g] ?? null) as $e) {
                    $found[] = $e + ['who' => $g];
                }
            }
        }
        usort($found, fn ($a, $b) => $a['from'] <=> $b['from']);

        $used = [];
        $falls = [];
        foreach ($found as $f) {
            $on = $side[$f['who']];
            $by = $on === 'us' ? 'them' : 'us';
            [$from, $to] = [(float) $f['from'], (float) $f['to']];
            $links = [];
            foreach ($offensive as $i => $c) {
                if ($side[$c['by']] === $by && $c['t'] >= $from - self::LEAD && $c['t'] <= $to) {
                    $links[] = ['t' => $c['t'], 'spell' => $c['spell'], 'cat' => 'offensive', 'by' => $c['by'], 'on' => null, 'role' => null];
                    $used[$i] = true;
                }
            }
            foreach ($control as $c) {
                if ($side[$c['by']] === $by && $c['from'] >= $from - self::LEAD && $c['from'] <= $to) {
                    $role = ($healer[$on] ?? null) === $c['on'] ? 'healer' : ($c['on'] === $f['who'] ? 'target' : 'cross');
                    $links[] = ['t' => round($c['from'], 1), 'spell' => $c['spell'], 'cat' => 'control', 'by' => $c['by'], 'on' => $c['on'],
                        'role' => $role, 'until' => round($c['to'], 1), 'dr' => $c['dr']];
                }
            }
            usort($links, fn ($a, $b) => $a['t'] <=> $b['t']);
            $offs = array_values(array_filter($links, fn ($l) => $l['cat'] === 'offensive'));
            $ccs = array_values(array_filter($links, fn ($l) => $l['cat'] === 'control'));

            $intensity = 0;
            for ($u = $from - self::LEAD; $u <= $to; $u += 0.5) {
                $live = count(array_filter($offs, fn ($l) => $u >= $l['t'] && $u < $l['t'] + self::LIVE))
                    + count(array_filter($ccs, fn ($l) => $u >= $l['t'] && $u < $l['until']));
                $intensity = max($intensity, $live);
            }

            // The fallen player's control against their healer's: landing together is the go. The
            // closest pair, not the first of each: on 19:11 a Ring of Frost came 5s before the Kidney
            // Shot that landed with the control on our healer.
            [$onTarget, $onHealer] = [null, null];
            foreach (array_filter($ccs, fn ($l) => $l['role'] === 'target') as $tc) {
                foreach (array_filter($ccs, fn ($l) => $l['role'] === 'healer') as $hc) {
                    if ($onTarget === null || abs($tc['t'] - $hc['t']) < abs($onTarget['t'] - $onHealer['t'])) {
                        [$onTarget, $onHealer] = [$tc, $hc];
                    }
                }
            }

            $falls[] = [
                'side' => $by,
                'on' => $f['who'],
                'from' => $from,
                'to' => $to,
                'high' => $f['high'],
                'low' => $f['low'],
                'lowAt' => (float) $f['lowAt'],
                'died' => $f['died'],
                'kind' => $offs ? 'cooldown' : ($ccs ? 'control' : 'damage'),
                'intensity' => $intensity,
                'links' => $links,
                'sync' => $onTarget && $onHealer ? [
                    'target' => $onTarget['t'], 'targetBy' => $onTarget['spell'],
                    'healer' => $onHealer['t'], 'healerBy' => $onHealer['spell'],
                    'gap' => round(abs($onTarget['t'] - $onHealer['t']), 1),
                ] : null,
                'answers' => array_values(array_map(fn ($c) => ['t' => round($c['t'], 1), 'spell' => $c['spell'], 'who' => $c['who']],
                    array_filter($defensive, fn ($c) => $c['side'] === $on && $c['t'] >= $from - 2 && $c['t'] <= $to))),
            ];
        }

        $unmoved = [];
        foreach ($offensive as $i => $c) {
            if (isset($used[$i])) {
                continue;
            }
            $s = $side[$c['by']];
            $alone = ! collect($control)->contains(fn ($cc) => $side[$cc['by']] === $s && abs($cc['from'] - $c['t']) <= 5);
            $unmoved[] = $c + ['side' => $s, 'alone' => $alone];
        }

        return ['falls' => $falls, 'unmoved' => $unmoved];
    }

    /**
     * One player's falls, from their health a second at a time.
     *
     * @param  array<int, int|float>  $series
     * @return array<int, array{from: int, to: int, high: int|float, low: int|float, lowAt: int, died: bool}>
     */
    private function episodes(array $series, ?float $diedAt): array
    {
        $out = [];
        $n = count($series);
        $high = $series[0] ?? 100;
        $highAt = 0;
        $in = null;
        for ($s = 1; $s < $n; $s++) {
            $h = $series[$s];
            if ($in === null) {
                if ($h >= $high) {
                    [$high, $highAt] = [$h, $s];
                } elseif ($high - $h >= self::DROP) {
                    $in = ['from' => $highAt, 'high' => $high, 'low' => $h, 'lowAt' => $s];
                } elseif ($s - $highAt > self::SLIDE) {
                    [$high, $highAt] = [max(array_slice($series, $s - self::SLIDE, self::SLIDE + 1)), $s];
                }

                continue;
            }
            if ($h < $in['low']) {
                [$in['low'], $in['lowAt']] = [$h, $s];
            }
            $dead = $diedAt !== null && $s >= $diedAt;
            if ($h >= $in['high'] - self::RECOVER || $dead || $s === $n - 1) {
                $out[] = $in + ['to' => $s, 'died' => $dead];
                [$in, $high, $highAt] = [null, $h, $s];
            }
        }

        return $out;
    }
}
