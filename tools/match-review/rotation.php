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
        $talentSets[$m['clock']] = array_values(array_unique(array_column($t['talents'] ?? [], 'name')));
        $pvpSets[$m['clock']] = array_values(array_unique(array_column($t['pvpTalents'] ?? [], 'name')));
    }

    $pets = [];
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
        if ($event === 'SPELL_CAST_SUCCESS') {
            $f = str_getcsv($body);
            if ($f[1] !== $guid && ! str_starts_with($f[1], 'Player-') && ($f[13] ?? '') === $guid) {
                $pets[$f[1]] = true;
            }
            if ($f[1] === $guid) {
                $casts[] = ['t' => round($s - $t0, 2), 'spell' => $f[10]];
                $castCount[$f[10]] = ($castCount[$f[10]] ?? 0) + 1;
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
