<?php

// How one player plays their spec, read from their own games: the talents they actually took,
// what they press and how often, where their damage comes from, and the exact order of their
// casts around each major cooldown. The evidence for a class guide (guides-from-play.md).
//   php tools/match-review/rotation.php Hozzaarr [--only=19:26,...]
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\ArenaLogService;
use App\Models\Patch;
use App\Models\Spell;

const ARCHIVE = 'D:/MindCollector/arena-logs';
const BEFORE = 3.0;   // seconds of casts shown before a major cooldown
const AFTER = 10.0;   // and after it
const MAJOR = 45;     // a cooldown this long or longer is a burst anchor
const POWER = [0 => 'mana', 1 => 'rage', 2 => 'focus', 3 => 'energy', 4 => 'combo points', 5 => 'runes', 6 => 'runic power', 12 => 'chi'];

$player = $argv[1] ?? 'Hozzaarr';
$only = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = explode(',', substr($a, 7));
    }
}

$arena = app(ArenaLogService::class);
$cooldowns = Spell::where('patch_id', Patch::where('is_current', true)->value('id'))
    ->where('cooldown_seconds', '>=', MAJOR)->pluck('cooldown_seconds', 'name')->all();

$games = [];
foreach (glob(ARCHIVE.'/metadata/*.json') as $file) {
    $m = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file)), true);
    if (($m['startInfo']['bracket'] ?? null) !== '3v3') {
        continue;
    }
    $names = array_column($m['units'], 'name');
    if (! preg_grep('/^Hozzaarr-/', $names) || ! preg_grep('/^Skylake-/', $names)) {
        continue;
    }
    $clock = date('H:i', intdiv($m['startTime'], 1000));
    if ($only !== null && ! in_array($clock, $only, true)) {
        continue;
    }
    $games[] = $m + ['clock' => $clock];
}
usort($games, fn ($a, $b) => $a['startTime'] <=> $b['startTime']);

$seconds = function (string $line): ?float {
    if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
        return null;
    }

    return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
};

$talentSets = [];
$pvpSets = [];
$castCount = [];
$damage = [];
$minutes = 0;
$anchors = [];
$pairs = [];
$specName = '?';
$buildOf = [];
$resource = [];
$withBuff = [];
$buffCasts = [];
$buffAll = [];
$allCasts = 0;

foreach ($games as $m) {
    $unit = null;
    foreach ($m['units'] as $u) {
        if (str_starts_with($u['name'], $player.'-')) {
            $unit = $u;
        }
    }
    if (! $unit) {
        continue;
    }
    $guid = $unit['id'];
    $raw = implode('', gzfile(ARCHIVE."/raw/{$m['id']}.log.gz"));
    $spec = $arena->specForExternalId((string) $unit['spec']);
    $specName = $spec?->name.' '.$spec?->gameClass?->name;

    // Talents, exactly as the log's COMBATANT_INFO recorded them for this game.
    if ($spec && ($info = $arena->extractCombatantInfoFromLog($raw, $guid))) {
        $t = $arena->resolveCombatantTalents($info, $spec->id);
        $externalNode = App\Models\TalentNode::whereIn('id', array_column($t['talents'] ?? [], 'nodeId'))->pluck('external_node_id', 'id')->all();
        $talentSets[$m['clock']] = array_values(array_unique(array_column($t['talents'] ?? [], 'name')));
        // The build as a guide draft names it: talents by name (":rank" when above 1), PvP by name.
        $buildOf[$m['clock']] = [
            // Blizzard's node id on every talent: a name can sit on several nodes (Dance of the Wind is
            // on three), and the node id is what the log records and survives a patch.
            'talents' => array_values(array_unique(array_map(fn ($x) => $x['name'].(($x['rank'] ?? 1) > 1 ? ':'.$x['rank'] : '').'#'.($externalNode[$x['nodeId']] ?? ''), $t['talents'] ?? []))),
            'pvp' => array_values(array_unique(array_column($t['pvpTalents'] ?? [], 'name'))),
        ];
        $pvpSets[$m['clock']] = array_values(array_unique(array_column($t['pvpTalents'] ?? [], 'name')));
    }

    $pets = [];
    $active = [];
    $t0 = null;
    $casts = [];
    foreach (explode("\n", $raw) as $line) {
        $s = $seconds($line);
        if ($s === null) {
            continue;
        }
        $t0 ??= $s;
        $body = explode('  ', $line, 2)[1] ?? '';
        $event = strtok($body, ',');
        if ($event === 'SPELL_SUMMON') {
            $f = str_getcsv($body);
            if ($f[1] === $guid) {
                $pets[$f[5]] = true;
            }

            continue;
        }
        // Buffs on the player (their own procs and cooldowns, and anything a teammate put on them).
        if (in_array($event, ['SPELL_AURA_APPLIED', 'SPELL_AURA_REMOVED', 'SPELL_AURA_APPLIED_DOSE', 'SPELL_AURA_REFRESH'], true) && str_contains($body, ',BUFF')) {
            $f = str_getcsv($body);
            if ($f[5] === $guid) {
                if ($event === 'SPELL_AURA_REMOVED') {
                    unset($active[$f[10]]);
                } elseif (! isset($active[$f[10]])) {
                    $active[$f[10]] = $s; // when it went up: a buff the cast itself creates is logged just before it
                }
            }

            continue;
        }
        if ($event === 'SPELL_CAST_SUCCESS') {
            $f = str_getcsv($body);
            if ($f[1] !== $guid && ! str_starts_with($f[1], 'Player-') && ($f[13] ?? '') === $guid) {
                $pets[$f[1]] = true;
            }
            if ($f[1] === $guid) {
                $casts[] = ['t' => round($s - $t0, 2), 'spell' => $f[10]];
                $castCount[$f[10]] = ($castCount[$f[10]] ?? 0) + 1;
                // THE WHY, part 1: the resource the cast was paid from, read from the END of the
                // line (powerType, current, max, cost sit just before posX, posY, map, facing, level).
                $n = count($f);
                $type = (int) ($f[$n - 9] ?? -1);
                if (isset(POWER[$type])) {
                    $scale = $type === 6 ? 10 : 1; // runic power is logged x10
                    $resource[$f[10]][] = ['type' => POWER[$type], 'cur' => (int) $f[$n - 8] / $scale, 'max' => (int) $f[$n - 7] / $scale, 'cost' => (int) $f[$n - 6] / $scale];
                }
                // THE WHY, part 2: which buffs were up when it was pressed.
                $buffCasts[$f[10]] = ($buffCasts[$f[10]] ?? 0) + 1;
                $allCasts++;
                // Only buffs that were up BEFORE the press: the log writes the aura a cast creates on
                // the line just before the cast, so without this every self-buff reads as 100%.
                foreach (array_keys(array_filter($active, fn ($since) => $s - $since >= 0.1)) as $b) {
                    $withBuff[$f[10]][$b] = ($withBuff[$f[10]][$b] ?? 0) + 1;
                    $buffAll[$b] = ($buffAll[$b] ?? 0) + 1;
                }
            }

            continue;
        }
        if (in_array($event, ['SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'RANGE_DAMAGE', 'SWING_DAMAGE_LANDED'], true)) {
            $f = str_getcsv($body);
            if (($f[1] === $guid || isset($pets[$f[1]])) && str_starts_with($f[5], 'Player-') && $f[5] !== $guid) {
                $swing = str_starts_with($event, 'SWING');
                $amount = $f[count($f) - ($swing ? 10 : 11)] ?? null;
                if (is_numeric($amount)) {
                    $k = ($f[1] === $guid ? '' : 'pet: ').($swing ? 'Melee' : $f[10]);
                    $damage[$k] = ($damage[$k] ?? 0) + (int) $amount;
                }
            }
        }
    }
    $minutes += $m['durationInSeconds'] / 60;

    // Around each major cooldown: every cast from BEFORE to AFTER, in order, with offsets.
    foreach ($casts as $c) {
        if (! isset($cooldowns[$c['spell']])) {
            continue;
        }
        $window = array_values(array_filter($casts, fn ($x) => $x['t'] >= $c['t'] - BEFORE && $x['t'] <= $c['t'] + AFTER));
        $anchors[$c['spell']][] = [
            'game' => $m['clock'], 't' => $c['t'],
            'seq' => implode(' > ', array_map(fn ($x) => sprintf('%s%s', $x['spell'], ($d = round($x['t'] - $c['t'], 1)) != 0 ? " ({$d})" : ''), $window)),
        ];
        // Which other major cooldowns go out with it, and how far apart.
        foreach ($window as $x) {
            if ($x !== $c && isset($cooldowns[$x['spell']]) && $x['t'] >= $c['t']) {
                $pairs[$c['spell'].' -> '.$x['spell']][] = round($x['t'] - $c['t'], 1);
            }
        }
    }
}

echo "ROTATION — {$player} ({$specName}), ".count($games).' games, '.round($minutes, 1)." minutes of arena\n\n";

echo "=== TALENTS (as the log recorded them)\n";
$all = array_merge(...array_values($talentSets ?: [[]]));
$counts = array_count_values($all);
arsort($counts);
$n = count($talentSets);
echo "   every game ({$n}): ".implode(', ', array_keys(array_filter($counts, fn ($c) => $c === $n))).PHP_EOL;
$some = array_filter($counts, fn ($c) => $c < $n);
if ($some) {
    echo '   some games: '.implode(', ', array_map(fn ($k, $c) => "{$k} ({$c})", array_keys($some), $some)).PHP_EOL;
}
$pc = array_count_values(array_merge(...array_values($pvpSets ?: [[]])));
arsort($pc);
echo '   PvP talents: '.implode(', ', array_map(fn ($k, $c) => "{$k} ({$c}/{$n})", array_keys($pc), $pc)).PHP_EOL;

echo "\n=== CASTS PER MINUTE (top 30)\n";
arsort($castCount);
foreach (array_slice($castCount, 0, 30, true) as $k => $c) {
    printf("   %-34s %5d  %5.1f/min%s\n", $k, $c, $c / max(1, $minutes), isset($cooldowns[$k]) ? '  [cd '.(int) $cooldowns[$k].'s]' : '');
}

echo "\n=== DAMAGE BY ABILITY (onto enemy players)\n";
arsort($damage);
$total = max(1, array_sum($damage));
foreach (array_slice($damage, 0, 20, true) as $k => $v) {
    printf("   %-34s %12s  %4.1f%%\n", $k, number_format($v), 100 * $v / $total);
}

echo "\n=== MAJOR COOLDOWNS PRESSED TOGETHER (within ".AFTER."s after)\n";
uksort($pairs, fn ($a, $b) => count($pairs[$b]) <=> count($pairs[$a]));
foreach ($pairs as $k => $ds) {
    if (count($ds) < 2) {
        continue;
    }
    sort($ds);
    printf("   %-52s %2d times, median +%.1fs\n", $k, count($ds), $ds[intdiv(count($ds), 2)]);
}

echo "\n=== EVERY CAST AROUND EACH MAJOR COOLDOWN (".BEFORE.'s before to '.AFTER."s after; offsets in seconds)\n";
uksort($anchors, fn ($a, $b) => count($anchors[$b]) <=> count($anchors[$a]));
foreach ($anchors as $cd => $list) {
    echo "   --- {$cd} (".count($list)." casts)\n";
    foreach ($list as $a) {
        printf("      %s %6.1fs  %s\n", $a['game'], $a['t'], $a['seq']);
    }
}

echo "\n=== THE WHY 1: THE RESOURCE EACH ABILITY WAS PRESSED WITH (median at the moment of the cast)\n";
foreach (array_slice($castCount, 0, 22, true) as $k => $c) {
    if (! isset($resource[$k])) {
        continue;
    }
    $r = $resource[$k];
    $cur = array_column($r, 'cur');
    sort($cur);
    $cost = array_column($r, 'cost');
    sort($cost);
    $nearMax = count(array_filter($r, fn ($x) => $x['max'] > 0 && $x['cur'] >= 0.9 * $x['max']));
    $free = count(array_filter($r, fn ($x) => $x['cost'] == 0));
    printf("   %-28s %-12s median %5s of %-5s cost %-4s | within 10%% of max %3d%% | cost 0 %3d%%\n", $k, $r[0]['type'],
        $cur[intdiv(count($cur), 2)], $r[0]['max'], $cost[intdiv(count($cost), 2)], round(100 * $nearMax / count($r)), round(100 * $free / count($r)));
}

echo "\n=== THE WHY 2: BUFFS UP WHEN EACH ABILITY WAS PRESSED (share of its casts, against all of this player's casts)\n";
echo "   only buffs up for 30%+ of an ability's casts and at least 1.5x more often than across all casts\n";
foreach (array_slice($castCount, 0, 22, true) as $k => $c) {
    $rows = [];
    foreach ($withBuff[$k] ?? [] as $b => $n) {
        $share = $n / max(1, $buffCasts[$k]);
        $base = ($buffAll[$b] ?? 0) / max(1, $allCasts);
        if ($share >= 0.3 && $base > 0 && $share / $base >= 1.5) {
            $rows[$b] = [$share, $base];
        }
    }
    uasort($rows, fn ($a, $b) => ($b[0] / $b[1]) <=> ($a[0] / $a[1]));
    if ($rows) {
        printf("   %-28s %s\n", $k, implode(', ', array_map(fn ($b, $v) => sprintf('%s %d%% (vs %d%%)', $b, 100 * $v[0], 100 * $v[1]), array_keys($rows), $rows)));
    }
}

echo "\n=== THE BUILD (for a guide draft's \"build\"; the most common across these games)\n";
$counted = array_count_values(array_map('json_encode', $buildOf));
arsort($counted);
$top = json_decode(array_key_first($counted), true);
printf("   used in %d of %d games\n", reset($counted), count($buildOf));
echo json_encode($top, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
