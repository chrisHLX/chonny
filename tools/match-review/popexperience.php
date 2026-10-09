<?php

// Every player in the archive with their arena history (Blizzard profile: Gladiator seasons, best
// 3v3, best rank), so any game can be tiered by how experienced its players are, Solo Shuffle
// included (a shuffle round carries no team rating). Reads what is cached or remembered first and
// looks up only the rest, about a second each. Writes storage/app/population-experience.json
// (names: gitignored, never committed).
//   php tools/match-review/popexperience.php
// Written 2026-10-07.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$arena = app(App\Http\Services\ArenaLogService::class);
$xp = app(App\Http\Services\PlayerExperienceService::class);
$out = storage_path('app/population-experience.json');
$known = is_file($out) ? json_decode(file_get_contents($out), true) : [];

// What the desktop app remembers (index.json keeps lookups past the cache's 7 days).
$index = getenv('APPDATA').'/MindCollector/cards/index.json';
if (is_file($index)) {
    foreach ((json_decode(file_get_contents($index), true)['experience'] ?? []) as $name => $hit) {
        $known[$name] ??= $hit;
    }
}

$names = [];
foreach (glob(dirname($arena->metadataPath('x')).'/*.json') as $f) {
    foreach (json_decode(file_get_contents($f), true)['units'] ?? [] as $u) {
        if (str_starts_with($u['id'], 'Player-') && substr_count($u['name'], '-') >= 2) {
            $names[$u['name']] = true;
        }
    }
}
$names = array_keys($names);
foreach ($xp->cachedMany($names) as $name => $hit) {
    if ($hit !== null) {
        $known[$name] ??= $hit;
    }
}

$todo = array_values(array_filter($names, fn ($n) => ! isset($known[$n])));
echo count($names).' players, '.(count($names) - count($todo)).' on file, looking up '.count($todo)."\n";
foreach ($todo as $i => $name) {
    try {
        $known[$name] = $xp->lookup($name);
    } catch (Throwable $e) {
        $known[$name] = ['found' => false, 'error' => substr($e->getMessage(), 0, 80)];
    }
    if ($i % 50 === 49) {
        file_put_contents($out, json_encode($known));
        echo '  '.($i + 1)." looked up\n";
    }
}
file_put_contents($out, json_encode($known));
$found = collect($known)->where('found', true);
echo 'done: '.$found->count().' found, '.$found->where('gladSeasons', '>', 0)->count()." with a Gladiator season\n";
