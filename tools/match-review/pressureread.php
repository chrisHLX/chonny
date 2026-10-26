<?php

// Goes found from PRESSURE first (2026-10-26). Rules that cut goes by cooldown timing alone all fail
// one way or another (gorule.php, gomodel.php): a team presses something every 10-20s, so where one
// go ends is arbitrary. Here a go is a pressure episode, a player's health falling DROP+ points from
// where it stood and ending when it is back within RECOVER of it (or they die), with the attacking
// side's commitment attached: its offensive cooldowns and lockout control from LEAD seconds before the
// fall to its end. What is left over is reported too: cooldowns that moved nobody, control that came
// with no pressure.
//
// Usage: php -d memory_limit=2G tools/match-review/pressureread.php [--show="2026-10-25 19:1"] [--drop=20 --recover=10 --lead=10]
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$opt = fn (string $k, ?string $d) => array_reduce($args, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
[$DROP, $RECOVER, $LEAD] = [(int) $opt('drop', '20'), (int) $opt('recover', '10'), (float) $opt('lead', '10')];
$show = $opt('show', null);
$LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];

$len = [];
$cdsAll = 0;
$cdsInEpisode = 0;
$episodes = 0;
$withCd = 0;

foreach (App\Models\ArenaRound::where('user_id', (int) $opt('user', '2'))->orderBy('played_at')->cursor() as $r) {
    $a = $r->payload['analysis'] ?? null;
    if (! is_array($a) || ($a['version'] ?? 0) < 16 || empty($a['timeline'])) {
        continue;
    }
    $side = $name = [];
    foreach ($a['players'] as $p) {
        $side[$p['guid']] = $p['side'];
        $name[$p['guid']] = explode('-', $p['name'])[0];
    }
    $offs = [];
    foreach ($a['goes'] as $g) {
        foreach ($g['links'] as $l) {
            if ($l['cat'] === 'offensive') {
                $offs[] = ['t' => $g['from'] + $l['t'], 'spell' => $l['spell'], 'side' => $g['side'], 'used' => false];
            }
        }
    }
    $cc = [];
    foreach ($a['timeline']['control'] as [$on, $by, $spell, $kind, $f, $t]) {
        if (in_array($kind, $LOCKOUT, true) && isset($side[$on], $side[$by]) && $side[$on] !== $side[$by]) {
            $cc[] = ['on' => $on, 'side' => $side[$by], 'spell' => $spell, 'from' => $f, 'used' => false];
        }
    }
    $deaths = collect($a['deaths'] ?? [])->keyBy('who');
    $eps = [];
    foreach ($a['timeline']['hp'] as $g => $series) {
        if (! isset($side[$g])) {
            continue;
        }
        $high = $series[0] ?? 100;
        $highAt = 0;
        $in = null;
        $n = count($series);
        for ($u = 1; $u < $n; $u++) {
            $h = $series[$u];
            if ($in === null) {
                if ($h >= $high) {
                    [$high, $highAt] = [$h, $u];
                } elseif ($high - $h >= $DROP) {
                    $in = ['who' => $g, 'from' => $highAt, 'high' => $high, 'low' => $h];
                } elseif ($u - $highAt > 8) {
                    // a slow slide does not hold the high forever
                    [$high, $highAt] = [max(array_slice($series, $u - 8, 9)), $u];
                }
            } else {
                $in['low'] = min($in['low'], $h);
                $dead = isset($deaths[$g]) && $u >= $deaths[$g]['t'];
                if ($h >= $in['high'] - $RECOVER || $dead || $u === $n - 1) {
                    $in['to'] = $u;
                    $in['dead'] = $dead;
                    $eps[] = $in;
                    [$in, $high, $highAt] = [null, $h, $u];
                }
            }
        }
    }
    usort($eps, fn ($x, $y) => $x['from'] <=> $y['from']);
    foreach ($eps as &$e) {
        $att = $side[$e['who']] === 'us' ? 'them' : 'us';
        $e['side'] = $att;
        $e['cds'] = [];
        $e['cc'] = [];
        foreach ($offs as &$o) {
            if ($o['side'] === $att && $o['t'] >= $e['from'] - $LEAD && $o['t'] <= $e['to']) {
                $e['cds'][] = $o['spell'].' '.round($o['t'], 1);
                $o['used'] = true;
            }
        }
        unset($o);
        foreach ($cc as &$c) {
            if ($c['side'] === $att && $c['from'] >= $e['from'] - $LEAD && $c['from'] <= $e['to']) {
                $e['cc'][] = $c['spell'].'>'.$name[$c['on']].' '.round($c['from'], 1);
                $c['used'] = true;
            }
        }
        unset($c);
        $episodes++;
        $len[] = $e['to'] - $e['from'];
        $withCd += $e['cds'] ? 1 : 0;
    }
    unset($e);
    $cdsAll += count($offs);
    $cdsInEpisode += count(array_filter($offs, fn ($o) => $o['used']));

    if ($show !== null && str_starts_with((string) $r->played_at, $show)) {
        echo "{$r->played_at}: pressure episodes (a player down {$DROP}+ until back within {$RECOVER})\n";
        foreach ($eps as $e) {
            printf("  %5.0f-%-5.0f %-5s on %-13s %3d -> %3d%s\n        cooldowns: %s\n        control:   %s\n", $e['from'], $e['to'], $e['side'] === 'us' ? 'OURS' : 'THEIRS',
                $name[$e['who']], $e['high'], $e['low'], $e['dead'] ? '  DIED' : '', implode(', ', $e['cds']) ?: '-', implode(', ', $e['cc']) ?: '-');
        }
        echo "  cooldowns that moved nobody: ".(implode(', ', array_map(fn ($o) => ($o['side'] === 'us' ? 'our ' : 'their ').$o['spell'].' '.round($o['t'], 1), array_filter($offs, fn ($o) => ! $o['used']))) ?: '-')."\n";
    }
}
sort($len);
$q = fn ($p) => $len ? $len[(int) floor($p / 100 * (count($len) - 1))] : '-';
echo "\n{$episodes} pressure episodes; length median {$q(50)}s, 75th {$q(75)}s, 90th {$q(90)}s\n";
echo 'Episodes with an offensive cooldown in them: '.round(100 * $withCd / max(1, $episodes))."%\n";
echo 'Offensive cooldowns inside an episode: '.round(100 * $cdsInEpisode / max(1, $cdsAll))."% (the rest moved nobody {$DROP}+)\n";
