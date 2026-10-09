<?php

// One row per archived round, for studies that need players' names (to join their arena history)
// beside the round's measures: each team's resource state at 1:00 and 2:00, free exchanges won,
// its goes, the first death, and every player's habits from the population file. Writes
// storage/app/population-features.json (names: gitignored, never committed). Pair with
// popexperience.php and popelite.py.
//   php -d memory_limit=2G tools/match-review/popfeatures.php
// Written 2026-10-07.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$arena = app(App\Http\Services\ArenaLogService::class);
$svc = app(App\Http\Services\ArenaMomentService::class);
$timelineFn = (new ReflectionClass($svc))->getMethod('readTimeline');
$hash = fn (string $guid) => substr(sha1('mc-pop:'.$guid), 0, 12);
$rows = [];

foreach (glob(storage_path('app/population/*.json')) as $popFile) {
    $pop = json_decode(file_get_contents($popFile), true);
    $id = $pop['id'] ?? null;
    $meta = $id ? json_decode((string) @file_get_contents($arena->metadataPath($id)), true) : null;
    $raw = $id ? @gzdecode((string) @file_get_contents($arena->rawLogPath($id))) : null;
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
    $firstDeath = $tl['deaths'][0] ?? null;
    $end = min($firstDeath['t'] ?? INF, (float) ($meta['durationInSeconds'] ?? 0));
    $commit = collect($tl['commitments'])->map(fn ($c) => $c + ['side' => $sideOf($c['who'])])->filter(fn ($c) => $c['side'] !== null);

    $state = [];
    foreach ([60, 120] as $T) {
        if ($T >= $end) {
            continue;
        }
        foreach (['us', 'them'] as $side) {
            foreach (['defensive', 'offensive'] as $kind) {
                $state[$T][$side][$kind] = $commit->filter(fn ($c) => $c['side'] === $side && $c['kind'] === $kind
                    && $c['t'] <= $T && $T < $c['t'] + $c['cooldown'])->count();
            }
        }
    }

    $goes = collect($pop['goes'])->filter(fn ($g) => isset($g['from']));
    $inGo = fn (string $side, float $t) => $goes->contains(fn ($g) => $g['side'] === $side && $t >= $g['from'] && $t <= $g['from'] + $g['len']);
    $free = ['us' => 0, 'them' => 0];
    foreach ($commit->where('kind', 'defensive') as $d) {
        $other = $d['side'] === 'us' ? 'them' : 'us';
        if ($d['t'] < $end && ! $inGo($other, $d['t'])
            && ! $commit->contains(fn ($c) => $c['side'] === $other && $c['kind'] === 'defensive' && abs($c['t'] - $d['t']) <= 15)) {
            $free[$other]++;
        }
    }

    $goStats = [];
    foreach (['us', 'them'] as $side) {
        $mine = $goes->where('side', $side);
        $locked = fn ($g) => $g['healerLocked'] >= 2 || $g['healerKicked'];
        $goStats[$side] = [
            'n' => $mine->count(),
            'killed' => $mine->filter(fn ($g) => $g['kill'] || $g['killLater'])->count(),
            'locked' => $mine->filter($locked)->count(),
            'healerCc' => $mine->sum('healerCc'),
            'joint' => $mine->where('joint', true)->count(),
            'drained' => $mine->sum('drained'),
            'firstAt' => $mine->min('from'),
            'tight' => $mine->where('good', true)->count(),
        ];
    }

    $players = [];
    foreach ($meta['units'] as $u) {
        if (! str_starts_with($u['id'], 'Player-') || ! ($p = $pop['players'][$hash($u['id'])] ?? null)) {
            continue;
        }
        $players[] = ['name' => $u['name'], 'side' => $sideOf($u['id']), 'spec' => $p['spec'], 'healer' => $p['healer']]
            + array_intersect_key($p, array_flip(['alive', 'idle', 'free', 'locked', 'damage', 'healing', 'kicks', 'kicked', 'control', 'offTarget', 'died', 'diedFirst']));
    }

    $rows[] = [
        'id' => $id, 'bracket' => $pop['bracket'], 'playedAt' => $pop['playedAt'], 'duration' => $pop['duration'],
        'mmr' => $pop['mmr'], 'usWon' => $usWon, 'state' => $state, 'free' => $free, 'goes' => $goStats,
        'firstDeath' => $firstDeath ? ['t' => $firstDeath['t'], 'side' => $sideOf($firstDeath['who'])] : null,
        'players' => $players,
    ];
}

file_put_contents(storage_path('app/population-features.json'), json_encode($rows));
echo count($rows)." rounds written\n";
