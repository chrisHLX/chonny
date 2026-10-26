<?php

// One stored round's goes against what happened, for checking the go model against a video
// (2026-10-26, the app's Videos tab). Prints each go's links with their round time, then, for each
// stretch asked for, every lockout span (who, by whom, kind, from-to), each player's health a second
// at a time, and every cast. Reads arena_rounds (version 16+, which stores the timeline); no raw log.
//
//   php tools/match-review/goread.php "2026-10-25 19:1"                    the goes only
//   php tools/match-review/goread.php "2026-10-25 19:1" 0-40 50-80          plus those stretches
//   --user=2 (default), --hp=Doubletapz,Noitabretsam,Netsirk (default: everyone)
//
// Written for the 19:11 RMD game, where the video showed a go starting 16s before its damage and one
// "go" running 105s; see match-review-analysis.md, 2026-10-26.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$opt = fn (string $k, ?string $d) => array_reduce($args, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$plain = array_values(array_filter($args, fn ($a) => ! str_starts_with($a, '--')));
$at = $plain[0] ?? exit("Usage: goread.php \"YYYY-MM-DD HH:MM\" [from-to ...]\n");
$ranges = array_map(fn ($r) => array_map('floatval', explode('-', $r)), array_slice($plain, 1));

$r = App\Models\ArenaRound::where('user_id', (int) $opt('user', '2'))->where('played_at', 'like', $at.'%')->orderBy('played_at')->first()
    ?? exit("No round at {$at}\n");
$a = $r->payload['analysis'];
$n = [];
foreach ($a['players'] as $p) {
    $n[$p['guid']] = explode('-', $p['name'])[0];
}
$who = fn ($g) => $n[$g] ?? ($g ?? '-');

echo "{$r->played_at}  {$r->bracket}  ".($a['won'] ? 'WON' : 'LOST')."  lobby {$r->lobby_id}\n";
foreach ($a['players'] as $p) {
    printf("  %-14s %-26s %s\n", $who($p['guid']), $p['spec'], $p['side']);
}

echo "\nGOES (window from-to; each link +seconds into the go, and its round time)\n";
foreach ($a['goes'] as $i => $g) {
    printf("#%d %-4s %6.1f-%6.1f (%3ds)  target %-13s good=%s kill=%s\n", $i, $g['side'], $g['from'], $g['to'], $g['to'] - $g['from'],
        $who($g['target'] ?? null), json_encode($g['good'] ?? null), isset($g['kill']) && $g['kill'] ? $who($g['kill']) : '-');
    foreach ($g['links'] as $l) {
        printf("     +%5.1f  t=%6.1f  %-9s %-32s %-13s > %-13s %s\n", $l['t'], $g['from'] + $l['t'], $l['cat'], $l['spell'], $who($l['by'] ?? null),
            $who($l['on'] ?? null), $l['role'] ? "[{$l['role']}]" : '');
    }
}

$tl = $a['timeline'] ?? exit("\nThis round has no timeline (measured before version 16).\n");
$lockout = ['Stun', 'Incapacitate', 'Disorient', 'Silence', 'Fear', 'Horror', 'Sleep'];
$hpFor = $opt('hp', null) ? explode(',', $opt('hp', '')) : array_values($n);
foreach ($ranges as [$f, $t]) {
    printf("\n===== %ds to %ds =====\nLOCKOUT (on <- by, spell, kind, from-to)\n", $f, $t);
    foreach ($tl['control'] as $c) {
        if (in_array($c[3], $lockout, true) && $c[5] >= $f && $c[4] <= $t) {
            printf("  %-13s <- %-13s %-22s %-12s %5.1f-%5.1f\n", $who($c[0]), $who($c[1]), $c[2], $c[3], $c[4], $c[5]);
        }
    }
    foreach ($hpFor as $name) {
        $g = array_search($name, $n, true);
        $hp = $g !== false ? ($tl['hp'][$g] ?? []) : [];
        printf("HP %-13s ", $name);
        for ($s = (int) $f; $s <= $t; $s++) {
            echo str_pad((string) ($hp[$s] ?? '.'), 4, ' ', STR_PAD_LEFT);
        }
        echo "\n";
    }
    echo "CASTS (every cast, and whom it went on)\n";
    $rows = [];
    foreach ($tl['casts'] as $g => $casts) {
        foreach ($casts as $c) {
            if ($c[0] >= $f && $c[0] <= $t) {
                $rows[] = [$c[0], $who($g), $c[1], $who($c[2] ?? null)];
            }
        }
    }
    usort($rows, fn ($x, $y) => $x[0] <=> $y[0]);
    foreach ($rows as $row) {
        printf("  %6.1f %-13s %-30s -> %s\n", ...$row);
    }
}
