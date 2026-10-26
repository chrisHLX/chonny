<?php

// The go rule against a proposed one, over every stored round (2026-10-26). Proposes; changes nothing.
//
// Today (RoundAnalysisService::goes): offensive cooldowns (+5s) and lockout control, joined from the
// chain's end within 5s, or within 10s through an offensive cooldown. A team pressing something
// every 10-13s never ends a go: 19:11 on 25 Oct held one 105s go.
//
// Proposed:
//   1. The CORE is the side's offensive cooldowns, each within CORE_GAP of the one before. A cooldown
//      further away starts a new go, and the go before it ends there.
//   2. SETUP is lockout control on the enemy that chains into the core's first cooldown, CC to CC
//      within 5s from the chain's end (today's tight link), counted back from the core.
//   3. FOLLOW-THROUGH is control landing between the core's start and the window's end.
//   4. The WINDOW runs from the first setup link to the last core cooldown + 15s (or the end of the
//      last control in it), cut at the next go's start.
//   5. PRESSURE starts at the first second, from the first core cooldown on, that the go's target
//      loses health (the stored health is per second, so this is to the second).
//
// Reads arena_rounds (version 16+: the stored goes give the offensive presses, the timeline the
// control and health). Usage: php tools/match-review/gorule.php [--user=2] [--gap=10] [--show="2026-10-25 19:1"]
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$opt = fn (string $k, ?string $d) => array_reduce($args, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$CORE_GAP = (float) $opt('gap', '10');
$TIGHT = 5.0;
$TAIL = 15.0;
$LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];
$show = $opt('show', null);

$oldLen = [];
$newLen = [];
$offGaps = [];
$setupToPressure = [];
$rounds = 0;
$oldCount = 0;
$newCount = 0;
$single = 0;
$singleCc = 0;

$q = App\Models\ArenaRound::where('user_id', (int) $opt('user', '2'))->orderBy('played_at');
foreach ($q->cursor() as $r) {
    $a = $r->payload['analysis'] ?? null;
    if (! is_array($a) || ($a['version'] ?? 0) < 16 || empty($a['timeline'])) {
        continue;
    }
    $rounds++;
    $side = [];
    $name = [];
    foreach ($a['players'] as $p) {
        $side[$p['guid']] = $p['side'];
        $name[$p['guid']] = explode('-', $p['name'])[0];
    }
    $hp = $a['timeline']['hp'] ?? [];
    $printing = $show !== null && str_starts_with((string) $r->played_at, $show);
    if ($printing) {
        echo "\n{$r->played_at}  TODAY'S GOES vs PROPOSED\n";
    }

    foreach (['us', 'them'] as $s) {
        $other = $s === 'us' ? 'them' : 'us';
        $old = array_values(array_filter($a['goes'], fn ($g) => $g['side'] === $s));
        foreach ($old as $g) {
            $oldLen[] = $g['to'] - $g['from'];
            $oldCount++;
        }

        // Offensive presses, from today's goes (every offensive cooldown is in one).
        $offs = [];
        foreach ($old as $g) {
            foreach ($g['links'] as $l) {
                if ($l['cat'] === 'offensive') {
                    $offs[] = ['t' => $g['from'] + $l['t'], 'spell' => $l['spell'], 'by' => $l['by']];
                }
            }
        }
        usort($offs, fn ($x, $y) => $x['t'] <=> $y['t']);
        for ($i = 1; $i < count($offs); $i++) {
            $offGaps[] = $offs[$i]['t'] - $offs[$i - 1]['t'];
        }
        // Lockout control this side landed on the other.
        $cc = [];
        foreach ($a['timeline']['control'] as $c) {
            [$on, $by, $spell, $kind, $from, $to] = $c;
            if (in_array($kind, $LOCKOUT, true) && ($side[$on] ?? null) === $other && ($side[$by] ?? null) === $s) {
                $cc[] = ['t' => $from, 'end' => $to, 'spell' => $spell, 'on' => $on];
            }
        }
        usort($cc, fn ($x, $y) => $x['t'] <=> $y['t']);

        // 1. cores
        $cores = [];
        foreach ($offs as $o) {
            if ($cores && $o['t'] - end($cores[count($cores) - 1])['t'] <= $CORE_GAP) {
                $cores[count($cores) - 1][] = $o;
            } else {
                $cores[] = [$o];
            }
        }
        $goes = [];
        foreach ($cores as $k => $core) {
            $start = $core[0]['t'];
            $last = end($core)['t'];
            $prevEnd = $k > 0 ? $goes[$k - 1]['coreLast'] : -INF;
            // 2. setup: walk back from the core start, CC chaining tightly into it.
            $setupFrom = $start;
            $edge = $start;
            foreach (array_reverse($cc) as $c) {
                if ($c['t'] >= $start || $c['t'] <= $prevEnd) {
                    continue;
                }
                if ($c['end'] >= $edge - $TIGHT) {
                    $setupFrom = min($setupFrom, $c['t']);
                    $edge = min($edge, $c['t']);
                }
            }
            // 3, 4. window and its control
            $to = $last + $TAIL;
            $next = $cores[$k + 1][0]['t'] ?? INF;
            $inGo = array_values(array_filter($cc, fn ($c) => $c['t'] >= $setupFrom && $c['t'] < min($to, $next)));
            foreach ($inGo as $c) {
                $to = max($to, $c['end']);
            }
            $to = min($to, $next);
            // target: the enemy that lost the most health over the window
            $target = null;
            $worst = -1;
            foreach ($side as $g => $sd) {
                if ($sd !== $other) {
                    continue;
                }
                $lost = 0;
                $series = $hp[$g] ?? [];
                for ($t = (int) floor($start); $t < min((int) ceil($to), count($series) - 1); $t++) {
                    $lost += max(0, ($series[$t] ?? 100) - ($series[$t + 1] ?? 100));
                }
                if ($lost > $worst) {
                    [$worst, $target] = [$lost, $g];
                }
            }
            // 5. pressure: first loss of health on the target from the core's start
            $pressure = null;
            $series = $hp[$target] ?? [];
            for ($t = (int) floor($start); $t < min((int) ceil($to), count($series) - 1); $t++) {
                if (($series[$t + 1] ?? 100) < ($series[$t] ?? 100)) {
                    $pressure = $t + 1;
                    break;
                }
            }
            $goes[] = ['from' => $setupFrom, 'core' => $start, 'coreLast' => $last, 'to' => $to, 'pressure' => $pressure, 'target' => $target, 'offs' => $core, 'cc' => $inGo];
        }
        // A go ends where the next one's setup begins, so no control belongs to two goes.
        foreach ($goes as $k => &$g) {
            if (isset($goes[$k + 1]) && $goes[$k + 1]['from'] < $g['to']) {
                $g['to'] = max($g['coreLast'], $goes[$k + 1]['from']);
                $g['cc'] = array_values(array_filter($g['cc'], fn ($c) => $c['t'] < $g['to']));
            }
            $newLen[] = $g['to'] - $g['from'];
            $newCount++;
            $single += count($g['offs']) === 1 ? 1 : 0;
            $singleCc += count($g['offs']) === 1 && $g['cc'] === [] ? 1 : 0;
            if ($g['pressure'] !== null) {
                $setupToPressure[] = $g['pressure'] - $g['from'];
            }
        }
        unset($g);

        if ($printing) {
            echo "  {$s} today: ".implode('  ', array_map(fn ($g) => sprintf('%.0f-%.0f (%ds)', $g['from'], $g['to'], $g['to'] - $g['from']), $old))."\n";
            foreach ($goes as $g) {
                printf("  %s new: setup %5.1f  core %5.1f-%5.1f  pressure %s  end %5.1f (%ds)  target %s\n", $s, $g['from'], $g['core'], $g['coreLast'],
                    $g['pressure'] === null ? '-' : sprintf('%3d', $g['pressure']), $g['to'], $g['to'] - $g['from'], $name[$g['target']] ?? '?');
                echo '        cooldowns: '.implode(', ', array_map(fn ($o) => sprintf('%s %.1f', $o['spell'], $o['t']), $g['offs']))."\n";
                echo '        control:   '.implode(', ', array_map(fn ($c) => sprintf('%s>%s %.1f', $c['spell'], $name[$c['on']] ?? '?', $c['t']), $g['cc']))."\n";
            }
        }
    }
}

$pct = function (array $xs, float $p): string {
    if (! $xs) {
        return '-';
    }
    sort($xs);

    return (string) round($xs[(int) floor(($p / 100) * (count($xs) - 1))], 1);
};
$over = fn (array $xs, float $n) => $xs ? round(100 * count(array_filter($xs, fn ($x) => $x > $n)) / count($xs)).'%' : '-';
echo "\n{$rounds} rounds\n";
printf("%-34s %8s %8s\n", '', 'today', 'proposed');
printf("%-34s %8d %8d\n", 'goes', $oldCount, $newCount);
foreach ([25, 50, 75, 90] as $p) {
    printf("%-34s %8s %8s\n", "length, {$p}th percentile (s)", $pct($oldLen, $p), $pct($newLen, $p));
}
printf("%-34s %8s %8s\n", 'longer than 45s', $over($oldLen, 45), $over($newLen, 45));
printf("%-34s %8s %8s\n", 'longer than 60s', $over($oldLen, 60), $over($newLen, 60));
echo "\nGap between a side's offensive cooldowns (s): 25th {$pct($offGaps, 25)}, median {$pct($offGaps, 50)}, 75th {$pct($offGaps, 75)}; within 5s ".(100 - (int) $over($offGaps, 5))."%, within 10s ".(100 - (int) $over($offGaps, 10))."%\n";
echo "Proposed goes with one offensive cooldown: ".round(100 * $single / max(1, $newCount))."%; of those with no control either: ".round(100 * $singleCc / max(1, $newCount))."% of all goes
";
echo "Setup to pressure, proposed (s): median {$pct($setupToPressure, 50)}, 75th {$pct($setupToPressure, 75)}, 90th {$pct($setupToPressure, 90)}\n";
