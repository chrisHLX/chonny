<?php
// Look up every player in games.json: highest 3v3 rating, Gladiator / Rank 1 seasons, current 3v3.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\BattlenetCharacterSyncService;
use App\Http\Services\BattlenetClient;

$dir = __DIR__;
$games = json_decode(file_get_contents("$dir/games.json"), true);
$client = app(BattlenetClient::class);
$sync = app(BattlenetCharacterSyncService::class);

$names = [];
foreach ($games as $g) {
    foreach ($g['players'] as $p) {
        $names[$p['name']] = true;
    }
}

$out = [];
foreach (array_keys($names) as $full) {
    [$name, $realm, $region] = explode('-', $full, 3);
    // Realm slug: lowercase, apostrophes dropped, spaces to hyphens; CamelCase realms (Area52,
    // BleedingHollow) get a hyphen at each word boundary.
    // Camel split first, then drop an apostrophe and the hyphen it caused: Jubei'Thos -> jubeithos.
    $slug = strtolower(preg_replace(['/(?<=[a-z])(?=[A-Z0-9])/', '/\s+/', "/'-?/"], ['-', '-', ''], $realm));
    $region = strtolower($region);
    try {
        $r = $client->characterMany($region, $slug, $name, [
            'statistics' => '/achievements/statistics',
            'achievements' => '/achievements',
            'b3' => '/pvp-bracket/3v3',
        ]);
    } catch (Throwable $e) {
        $out[$full] = ['error' => $e->getMessage()];
        echo "$full ERROR {$e->getMessage()}\n";
        continue;
    }
    if ($r['statistics'] === null) {
        $out[$full] = ['error' => "no profile ($slug)"];
        echo "$full no profile ($slug)\n";
        continue;
    }
    $s = $sync->parseStatistics($r['statistics']);
    $t = $sync->parseArenaTitles($r['achievements'] ?? []);
    $rank = $sync->parseRankTitle($r['achievements'] ?? []);
    $out[$full] = [
        'exp_3v3' => $s['exp_3v3'],
        'glad_seasons' => $t['3v3']['seasons'] ?? 0,
        'r1_seasons' => $t['3v3']['rank_one_seasons'] ?? 0,
        'legend_seasons' => $t['shuffle']['seasons'] ?? 0,
        'best_rank' => $rank['title'] ?? null,
        'current_3v3' => $r['b3']['rating'] ?? null,
    ];
    echo $full.' '.json_encode($out[$full], JSON_UNESCAPED_UNICODE)."\n";
}
file_put_contents("$dir/experience.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
