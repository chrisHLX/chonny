<?php

// Is the relative resource state (arena-structure.md Part 20) visible in the archive? Two tests over
// every archived round, both teams:
//  1. At fixed points in the round, does the team with FEWER of its own defensives on cooldown (more
//     answers up than the other team) win more? And the same for offensive cooldowns.
//  2. Open question C14: a defensive pressed while the other team was NOT in a go, with the other
//     team spending no defensive of its own within 15s, is an exchange the other team won for free
//     (by pressure, positioning, or the presser's waste). Does the team that wins more of those win?
// Reads the raw slices for the timeline (ArenaMomentService) and storage/app/population for the
// goes (wow:population). A correlation over rounds, never a cause.
//   php -d memory_limit=2G tools/match-review/popstate.php
// Written 2026-10-07.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$arena = app(App\Http\Services\ArenaLogService::class);
$svc = app(App\Http\Services\ArenaMomentService::class);
$ref = new ReflectionClass($svc);
$timelineFn = $ref->getMethod('readTimeline');
$checkpoints = [30, 60, 90, 120, 180];
const FREE_WINDOW = 15.0;

$state = [];   // checkpoint => kind => ['ahead' => [n, won], 'behind' => [n, won], 'level' => [n, won]]
$free = ['moreWon' => [0, 0], 'fewerWon' => [0, 0], 'equal' => 0];
$freeDetail = [];
$rounds = 0;

foreach (glob(storage_path('app/population/*.json')) as $popFile) {
    $pop = json_decode(file_get_contents($popFile), true);
    $id = $pop['id'] ?? null;
    if (! $id || ! is_file($arena->rawLogPath($id))) {
        continue;
    }
    $meta = json_decode((string) file_get_contents($arena->metadataPath($id)), true);
    $raw = @gzdecode((string) file_get_contents($arena->rawLogPath($id)));
    if (! $meta || ! $raw) {
        continue;
    }
    $roster = $svc->roster($meta);
    $logger = collect($meta['units'])->first(fn ($u) => (int) ($u['affiliation'] ?? 0) === 1);
    if (! $logger || ! isset($roster[$logger['id']])) {
        continue;
    }
    $usSide = $roster[$logger['id']]['side'];
    $sideOf = fn ($g) => isset($roster[$g]) ? ($roster[$g]['side'] === $usSide ? 'us' : 'them') : null;
    $usWon = collect($pop['players'])->first(fn ($p) => $p['side'] === 'us')['won'] ?? null;
    if ($usWon === null) {
        continue;
    }
    $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($raw)) ?: []));
    $tl = $timelineFn->invoke($svc, $lines, $roster);
    $rounds++;

    $firstDeath = $tl['deaths'][0]['t'] ?? INF;
    $duration = (float) ($meta['durationInSeconds'] ?? 0);
    $commit = collect($tl['commitments'])->map(fn ($c) => $c + ['side' => $sideOf($c['who'])])->filter(fn ($c) => $c['side'] !== null);

    // 1. Answers and cooldowns down at each checkpoint, while everyone is still alive.
    foreach ($checkpoints as $T) {
        if ($T >= min($firstDeath, $duration)) {
            continue;
        }
        foreach (['defensive', 'offensive'] as $kind) {
            $down = fn (string $side) => $commit->filter(fn ($c) => $c['side'] === $side && $c['kind'] === $kind
                && $c['t'] <= $T && $T < $c['t'] + $c['cooldown'])->count();
            $diff = $down('them') - $down('us');   // positive: they have more of theirs down, we hold more
            $bucket = $diff > 0 ? 'ahead' : ($diff < 0 ? 'behind' : 'level');
            $state[$T][$kind][$bucket][0] = ($state[$T][$kind][$bucket][0] ?? 0) + 1;
            $state[$T][$kind][$bucket][1] = ($state[$T][$kind][$bucket][1] ?? 0) + ($usWon ? 1 : 0);
        }
    }

    // 2. Free exchanges: a defensive pressed outside the other team's goes, the other team spending
    // none within FREE_WINDOW. Counted for the side that did NOT press it (the side that won it).
    $goes = collect($pop['goes'])->filter(fn ($g) => isset($g['from']));
    $inGo = fn (string $side, float $t) => $goes->contains(fn ($g) => $g['side'] === $side && $t >= $g['from'] && $t <= $g['from'] + $g['len']);
    $won = ['us' => 0, 'them' => 0];
    foreach ($commit->where('kind', 'defensive') as $d) {
        if ($d['t'] >= $firstDeath) {
            continue;
        }
        $other = $d['side'] === 'us' ? 'them' : 'us';
        if ($inGo($other, $d['t'])) {
            continue;
        }
        $otherSpent = $commit->contains(fn ($c) => $c['side'] === $other && $c['kind'] === 'defensive' && abs($c['t'] - $d['t']) <= FREE_WINDOW);
        if (! $otherSpent) {
            $won[$other]++;
        }
    }
    $diff = $won['us'] - $won['them'];
    $freeDetail[] = $diff;
    if ($diff === 0) {
        $free['equal']++;
    } else {
        $key = $diff > 0 ? 'moreWon' : 'fewerWon';   // from our side's view
        $free[$key][0]++;
        $free[$key][1] += $usWon ? 1 : 0;
    }
}

$rate = fn ($x) => ($x[0] ?? 0) ? sprintf('%4.1f%% (n=%d)', 100 * $x[1] / $x[0], $x[0]) : '   -';
echo "rounds read: {$rounds}\n\n== 1. Answers up at a point in the round (rounds with nobody dead yet)\n";
foreach ($state as $T => $kinds) {
    foreach ($kinds as $kind => $b) {
        printf("  %3ds %-9s more of ours up than theirs: win %s   fewer: %s   level: %s\n", $T, $kind,
            $rate($b['ahead'] ?? []), $rate($b['behind'] ?? []), $rate($b['level'] ?? []));
    }
}
echo "\n== 2. Free exchanges (C14): their defensive outside our goes with none of ours within ".FREE_WINDOW."s\n";
printf("  won more of them than the other team: win %s\n", $rate($free['moreWon']));
printf("  won fewer:                            win %s\n", $rate($free['fewerWon']));
printf("  equal: %d rounds\n", $free['equal']);
$by = collect($freeDetail)->countBy(fn ($d) => $d >= 3 ? '+3 or more' : ($d <= -3 ? '-3 or less' : (string) $d))->sortKeys();
echo '  spread (ours minus theirs): '.$by->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ')."\n";
