<?php

// The revised go (match-review-operations.md, "The go, revised: commitment and pressure",
// 2026-10-26) over every stored round, by the attacking team's comp: does it read sensibly for
// TSG, Ret/War, casters, and not only for us? Proposes; changes nothing.
//
//   commitment: a side's offensive cooldowns, each live LIVE seconds (the stored round keeps no
//     offensive buff spans; the real measure will use the buff's own), plus its lockout control on
//     the other team. A go is a stretch of it holding an offensive cooldown, ending when nothing has
//     been live for END_GAP seconds. One cooldown and nothing else: "pressed alone", not a go.
//   intensity: the most cooldowns and controls live at once.
//   pressure: how far the target actually dropped (net of healing; health is stored a second at a
//     time): the deepest fall from a high to a later low over the go and 3s after it; its worst 3s.
//   failed: intensity 2+ and the target lost under FAILED points. Reasons, from the log: the
//     attackers' DPS locked out for 40%+ of their cooldowns' live time; a defensive by the defenders;
//     the defending healer locked under 20% of the go. None of them: "the log cannot see why".
//
// Usage: php -d memory_limit=2G tools/match-review/gomodel.php [--user=2] [--live=10] [--gap=5]
//        [--examples=2026-10-25] (goes from that day's games, which have video)
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$opt = fn (string $k, ?string $d) => array_reduce($args, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$LIVE = (float) $opt('live', '10');
$END_GAP = (float) $opt('gap', '5');
$FAILED = 15;
// --anchor=20: a go is its first offensive cooldown and every one pressed within ANCHOR seconds of it
// (one go, spread); control attaches as setup (CC to CC within 5s, back from the first cooldown)
// and follow-through (inside the go). Without it, a go is a stretch of anything live (too long:
// arena control never runs dry, one go held 76s).
$ANCHOR = $opt('anchor', null) !== null ? (float) $opt('anchor', '20') : null;
$TAIL = 15.0;
$drops = [];
$LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];
$exampleDay = $opt('examples', '2026-10-25');

/** Merge [from, to] spans whose gaps are under $gap. */
$merge = function (array $spans, float $gap): array {
    usort($spans, fn ($a, $b) => $a[0] <=> $b[0]);
    $out = [];
    foreach ($spans as $s) {
        if ($out && $s[0] - $out[count($out) - 1][1] < $gap) {
            $out[count($out) - 1][1] = max($out[count($out) - 1][1], $s[1]);
        } else {
            $out[] = $s;
        }
    }

    return $out;
};
$overlap = fn (array $spans, float $f, float $t) => array_sum(array_map(fn ($s) => max(0, min($t, $s[1]) - max($f, $s[0])), $spans));

$byComp = [];
$examples = [];
$all = ['goes' => 0, 'alone' => 0, 'len' => [], 'strong' => 0, 'some' => 0, 'little' => 0, 'failed' => 0, 'reasons' => []];

foreach (App\Models\ArenaRound::where('user_id', (int) $opt('user', '2'))->orderBy('played_at')->cursor() as $r) {
    $a = $r->payload['analysis'] ?? null;
    if (! is_array($a) || ($a['version'] ?? 0) < 16 || empty($a['timeline'])) {
        continue;
    }
    $side = $name = $spec = $healer = [];
    foreach ($a['players'] as $p) {
        $side[$p['guid']] = $p['side'];
        $name[$p['guid']] = explode('-', $p['name'])[0];
        $spec[$p['guid']] = $p['spec'];
        if (! empty($p['healer'])) {
            $healer[$p['side']] = $p['guid'];
        }
    }
    $hp = $a['timeline']['hp'] ?? [];
    $offs = ['us' => [], 'them' => []];
    foreach ($a['goes'] as $g) {
        foreach ($g['links'] as $l) {
            if ($l['cat'] === 'offensive') {
                $offs[$g['side']][] = ['t' => $g['from'] + $l['t'], 'spell' => $l['spell'], 'by' => $l['by']];
            }
        }
    }
    $cc = [];
    foreach ($a['timeline']['control'] as [$on, $by, $spell, $kind, $f, $t]) {
        if (in_array($kind, $LOCKOUT, true) && isset($side[$on], $side[$by]) && $side[$on] !== $side[$by]) {
            $cc[] = ['on' => $on, 'by' => $by, 'spell' => $spell, 'from' => $f, 'to' => $t];
        }
    }
    $locked = [];
    foreach ($cc as $c) {
        $locked[$c['on']][] = [$c['from'], $c['to']];
    }

    foreach (['us', 'them'] as $s) {
        $o = $s === 'us' ? 'them' : 'us';
        $dps = array_keys(array_filter($side, fn ($x, $g) => $x === $s && ($healer[$s] ?? null) !== $g, ARRAY_FILTER_USE_BOTH));
        $specs = array_map(fn ($g) => $spec[$g], $dps);
        sort($specs);
        $comp = implode(' + ', $specs);
        $comp = $comp === '' ? '(none)' : $comp;
        $mine = array_values(array_filter($cc, fn ($c) => $side[$c['by']] === $s));
        $spans = array_merge(array_map(fn ($x) => [$x['t'], $x['t'] + $LIVE], $offs[$s]), array_map(fn ($c) => [$c['from'], $c['to']], $mine));
        $windows = $merge($spans, $END_GAP);
        if ($ANCHOR !== null) {
            $windows = [];
            $sorted = $offs[$s];
            usort($sorted, fn ($x, $y) => $x['t'] <=> $y['t']);
            $groups = [];
            foreach ($sorted as $x) {
                if ($groups && $x['t'] - $groups[count($groups) - 1][0]['t'] <= $ANCHOR) {
                    $groups[count($groups) - 1][] = $x;
                } else {
                    $groups[] = [$x];
                }
            }
            $prevEnd = -INF;
            foreach ($groups as $k => $grp) {
                $first = $grp[0]['t'];
                $setup = $first;
                $edge = $first;
                $back = $mine;
                usort($back, fn ($x, $y) => $y['from'] <=> $x['from']);
                foreach ($back as $c) {
                    if ($c['from'] < $first && $c['from'] > $prevEnd && $c['to'] >= $edge - $END_GAP) {
                        $setup = min($setup, $c['from']);
                        $edge = min($edge, $c['from']);
                    }
                }
                $end = end($grp)['t'] + $TAIL;
                $windows[] = [$setup, $end];
                $prevEnd = $end;
            }
            // a go ends where the next one's setup begins
            for ($k = 0; $k < count($windows) - 1; $k++) {
                $windows[$k][1] = min($windows[$k][1], max($windows[$k + 1][0], $windows[$k][0] + 1));
            }
        }
        foreach ($windows as [$from, $to]) {
            $in = array_values(array_filter($offs[$s], fn ($x) => $x['t'] >= $from && $x['t'] <= $to));
            if (! $in) {
                continue;
            }
            $ccIn = array_values(array_filter($mine, fn ($c) => $c['from'] >= $from - 0.01 && $c['from'] <= $to));
            $byComp[$comp] ??= ['goes' => 0, 'alone' => 0, 'len' => [], 'strong' => 0, 'some' => 0, 'little' => 0, 'failed' => 0, 'reasons' => []];
            $bucket = [&$byComp[$comp], &$all];
            if (count($in) === 1 && ! $ccIn) {
                foreach ($bucket as &$b) {
                    $b['alone']++;
                }
                unset($b);

                continue;
            }
            // intensity: most live at once
            $intensity = 0;
            for ($u = $from; $u <= $to; $u += 0.5) {
                $n = count(array_filter($in, fn ($x) => $u >= $x['t'] && $u < $x['t'] + $LIVE)) + count(array_filter($ccIn, fn ($c) => $u >= $c['from'] && $u < $c['to']));
                $intensity = max($intensity, $n);
            }
            // target and pressure
            $target = null;
            $lost = -1;
            $worst3 = 0;
            $pStart = null;
            foreach ($side as $g => $sd) {
                if ($sd !== $o) {
                    continue;
                }
                $series = $hp[$g] ?? [];
                // How far they actually dropped: the deepest fall from a high to a later low.
                $sum = 0;
                $high = 0;
                $w = 0;
                $first = null;
                for ($u = (int) floor($from); $u < min((int) ceil($to) + 3, count($series)); $u++) {
                    $high = max($high, $series[$u]);
                    $sum = max($sum, $high - $series[$u]);
                    if ($first === null && $u >= $in[0]['t'] && isset($series[$u + 1]) && $series[$u + 1] < $series[$u]) {
                        $first = $u + 1;
                    }
                    if (isset($series[$u + 3])) {
                        $w = max($w, $series[$u] - $series[$u + 3]);
                    }
                }
                if ($sum > $lost) {
                    [$lost, $target, $worst3, $pStart] = [$sum, $g, $w, $first];
                }
            }
            $drops[] = $lost;
            $tier = $lost >= 40 ? 'strong' : ($lost >= $FAILED ? 'some' : 'little');
            // reasons a go did little
            $liveSpans = $merge(array_map(fn ($x) => [$x['t'], $x['t'] + $LIVE], $in), 0);
            $liveLen = array_sum(array_map(fn ($sp) => $sp[1] - $sp[0], $liveSpans));
            $atkLocked = 0;
            foreach ($dps as $g) {
                $atkLocked += $overlap($merge($locked[$g] ?? [], 0), $liveSpans[0][0], end($liveSpans)[1]);
            }
            $atkShare = $liveLen > 0 && $dps ? $atkLocked / ($liveLen * count($dps)) : 0;
            $defs = array_values(array_filter($a['defensives'][$o]['rows'] ?? [], fn ($d) => $d['t'] >= $from && $d['t'] <= $to));
            $hl = isset($healer[$o]) ? $overlap($merge($locked[$healer[$o]] ?? [], 0), $from, $to) / max(1, $to - $from) : 1;
            $killed = collect($a['deaths'] ?? [])->first(fn ($d) => ($d['side'] ?? null) === $o && $d['t'] >= $from && $d['t'] <= $to + 5);
            $why = [];
            if ($atkShare >= 0.4) {
                $why[] = 'attackers locked out';
            }
            if ($defs) {
                $why[] = 'their defensives';
            }
            if ($hl < 0.2) {
                $why[] = 'their healer free';
            }
            $failed = $intensity >= 2 && $tier === 'little' && ! $killed;
            foreach ($bucket as &$b) {
                $b['goes']++;
                $b['len'][] = $to - $from;
                $b[$tier]++;
                if ($failed) {
                    $b['failed']++;
                    foreach ($why ?: ['the log cannot see why'] as $w) {
                        $b['reasons'][$w] = ($b['reasons'][$w] ?? 0) + 1;
                    }
                }
            }
            unset($b);

            if (str_starts_with((string) $r->played_at, $exampleDay) && $r->bracket !== null) {
                $examples[] = sprintf("%s %s  %s go %5.1f-%5.1f (%2ds)  %s  intensity %d  on %s: lost %d (worst 3s %d), pressure from %s%s%s\n      cooldowns: %s\n      control: %s\n      their defensives: %s",
                    substr((string) $r->played_at, 11, 5), str_pad($s === 'us' ? 'OUR' : 'THEIR', 5), $comp, $from, $to, $to - $from, strtoupper($tier), $intensity,
                    $name[$target] ?? '?', $lost, $worst3, $pStart === null ? '-' : $pStart.'s', $killed ? ', KILL '.($name[$killed['who']] ?? '?') : '',
                    $failed ? '  FAILED: '.implode(', ', $why ?: ['the log cannot see why']).sprintf(' (attackers locked %d%% of cooldown time, their healer locked %d%%)', $atkShare * 100, $hl * 100) : '',
                    implode(', ', array_map(fn ($x) => sprintf('%s %.1f', $x['spell'], $x['t']), $in)),
                    implode(', ', array_map(fn ($c) => sprintf('%s>%s %.1f', $c['spell'], $name[$c['on']], $c['from']), $ccIn)) ?: '-',
                    implode(', ', array_map(fn ($d) => sprintf('%s %.1f', $d['spell'], $d['t']), $defs)) ?: '-');
            }
        }
    }
}

$med = function (array $xs) {
    if (! $xs) {
        return '-';
    }
    sort($xs);

    return round($xs[intdiv(count($xs), 2)]);
};
$row = function (string $label, array $b) use ($med) {
    $n = max(1, $b['goes']);
    arsort($b['reasons']);
    $top = implode(', ', array_map(fn ($k, $v) => "{$k} ".round(100 * $v / max(1, $b['failed'])).'%', array_keys(array_slice($b['reasons'], 0, 3, true)), array_slice($b['reasons'], 0, 3, true)));
    printf("%-48s %5d %5s %6d%% %6d%% %6d%% %6d%% %7d%%  %s\n", mb_strimwidth($label, 0, 48), $b['goes'], $med($b['len']), round(100 * $b['alone'] / max(1, $b['goes'] + $b['alone'])),
        round(100 * $b['strong'] / $n), round(100 * $b['some'] / $n), round(100 * $b['little'] / $n), round(100 * $b['failed'] / $n), $top);
};
sort($drops);
$q = fn ($p) => $drops ? $drops[(int) floor($p / 100 * (count($drops) - 1))] : '-';
echo 'Deepest drop of the go target, in health points: 10th '.$q(10).', 25th '.$q(25).', median '.$q(50).', 75th '.$q(75).', 90th '.$q(90)."\n";
printf("%-48s %5s %5s %7s %7s %7s %7s %8s  %s\n", 'attacking DPS', 'goes', 'med s', 'alone', 'strong', 'some', 'little', 'failed', 'why the failed ones failed');
$row('ALL', $all);
uasort($byComp, fn ($x, $y) => $y['goes'] <=> $x['goes']);
foreach (array_slice($byComp, 0, 25, true) as $comp => $b) {
    $row($comp, $b);
}
echo "\nEXAMPLES, {$exampleDay} (these games have video)\n";
foreach ($examples as $e) {
    echo $e."\n";
}
