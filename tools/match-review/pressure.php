<?php

// Commitment against pressure, a few seconds at a time, for one stored round (2026-10-26). A
// prototype for the go rule Chriso proposed after watching 25 Oct 19:11 on video: show how
// concentrated a side's go is (commitment) apart from what it did to the other team (pressure), so
// a go that spent everything and moved nobody reads differently from one that killed.
//
//   COMMITMENT, per side, per second: offensive cooldowns live (each counted for LIVE seconds after
//     its press; the stored round has no offensive buff spans) + 1 while the enemy healer is locked
//     out by this side + 1 while any other enemy is.
//   PRESSURE, per enemy: health lost that second (net of healing: the stored health is per second).
//
// Usage: php tools/match-review/pressure.php "2026-10-25 19:1" [--from=0 --to=130 --bin=2 --live=10]
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$opt = fn (string $k, ?string $d) => array_reduce($args, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$plain = array_values(array_filter($args, fn ($a) => ! str_starts_with($a, '--')));
$at = $plain[0] ?? exit("Usage: pressure.php \"YYYY-MM-DD HH:MM\" [--from --to --bin --live]\n");
[$from, $to, $bin, $live] = [(int) $opt('from', '0'), (int) $opt('to', '130'), (int) $opt('bin', '2'), (float) $opt('live', '10')];
$LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];

$r = App\Models\ArenaRound::where('user_id', (int) $opt('user', '2'))->where('played_at', 'like', $at.'%')->orderBy('played_at')->first() ?? exit("No round\n");
$a = $r->payload['analysis'];
$side = $name = $healer = [];
foreach ($a['players'] as $p) {
    $side[$p['guid']] = $p['side'];
    $name[$p['guid']] = explode('-', $p['name'])[0];
    if ($p['healer']) {
        $healer[$p['side']] = $p['guid'];
    }
}
$offs = ['us' => [], 'them' => []];
foreach ($a['goes'] as $g) {
    foreach ($g['links'] as $l) {
        if ($l['cat'] === 'offensive') {
            $offs[$g['side']][] = [$g['from'] + $l['t'], $l['spell']];
        }
    }
}
$hp = $a['timeline']['hp'];

foreach (['us', 'them'] as $s) {
    $o = $s === 'us' ? 'them' : 'us';
    $enemies = array_keys(array_filter($side, fn ($x) => $x === $o));
    echo "\n".strtoupper($s)." COMMITMENT against PRESSURE on ".strtoupper($o)."   (each column {$bin}s; commitment = cooldowns live + healer locked + other locked)\n";
    printf('%-16s', 'time');
    for ($t = $from; $t < $to; $t += $bin) {
        printf('%4d', $t);
    }
    echo "\n";
    // commitment
    printf('%-16s', 'commitment');
    for ($t = $from; $t < $to; $t += $bin) {
        $best = 0;
        for ($u = $t; $u < $t + $bin; $u += 0.5) {
            $c = count(array_filter($offs[$s], fn ($x) => $u >= $x[0] && $u < $x[0] + $live));
            $hl = $ol = 0;
            foreach ($a['timeline']['control'] as $cc) {
                [$on, $by, , $kind, $f, $e] = $cc;
                if (in_array($kind, $LOCKOUT, true) && ($side[$by] ?? null) === $s && ($side[$on] ?? null) === $o && $u >= $f && $u < $e) {
                    $on === ($healer[$o] ?? null) ? $hl = 1 : $ol = 1;
                }
            }
            $best = max($best, $c + $hl + $ol);
        }
        echo str_pad($best ? str_repeat('#', $best) : '.', 4, ' ', STR_PAD_LEFT);
    }
    echo "\n";
    printf('%-16s', 'cooldowns');
    for ($t = $from; $t < $to; $t += $bin) {
        $here = array_filter($offs[$s], fn ($x) => $x[0] >= $t && $x[0] < $t + $bin);
        echo str_pad($here ? implode('', array_map(fn ($x) => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $x[1]), 0, 2)), $here)) : '', 4, ' ', STR_PAD_LEFT);
    }
    echo "\n";
    foreach ($enemies as $g) {
        printf('%-16s', 'lost: '.$name[$g]);
        $series = $hp[$g] ?? [];
        for ($t = $from; $t < $to; $t += $bin) {
            $lost = 0;
            for ($u = $t; $u < $t + $bin && $u + 1 < count($series); $u++) {
                $lost += max(0, $series[$u] - $series[$u + 1]);
            }
            echo str_pad($lost ? (string) $lost : '.', 4, ' ', STR_PAD_LEFT);
        }
        echo "\n";
        printf('%-16s', '  hp');
        for ($t = $from; $t < $to; $t += $bin) {
            echo str_pad((string) ($series[$t] ?? '.'), 4, ' ', STR_PAD_LEFT);
        }
        echo "\n";
    }
}
