<?php
// Dispels against the chance to dispel (2026-10-02). "Skylake Purifies a third as often as other
// Disc Priests" is meaningless until it is divided by what there was to Purify: a team facing melee
// cleave has little on it. Chriso's hypothesis: he dispels less because of the pressure on his team
// and on him (locked out, or busy fearing). This measures, per game and per Disc Priest:
//
//   opportunity  seconds his team carried an enemy debuff that Purify has been SEEN removing somewhere
//                in the archive (observed, not assumed: the spell data has no dispel type)
//   removed      his Purify / Mass Dispel removals of debuffs on his team
//   pressure     his team's damage taken per minute (compare within the tool only: the absolute
//                reads high), his own lockout share, his Psychic Screams
//   cc chances   a teammate in purifiable lockout or root for 2s+ with the priest free as it landed:
//                did he take it off, and how quickly
//
//   php -d memory_limit=2G tools/match-review/dispelread.php [--me=Skylake] [--since=2026-09-20]
//
// Output names other players: not committed, like the rest of this folder's output.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\ArenaMomentService;

const ARCHIVE = 'D:/MindCollector/arena-logs';
const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];
const PRIEST_DISPELS = ['Purify', 'Mass Dispel'];

$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$meName = $opt('me', 'Skylake');
$since = $opt('since', '2026-09-20');
$svc = app(ArenaMomentService::class);
$cc = $svc->crowdControl();   // external spell_id => dr_category

$seconds = function (string $line): ?float {
    if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
        return null;
    }

    return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
};

// Pass 1: every game since $since, read once and kept small.
$games = [];
$purifiable = [];   // debuff name => times a Purify / Mass Dispel was seen removing it
foreach (glob(ARCHIVE.'/metadata/*.json') as $file) {
    $m = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file)), true);
    if (($m['startInfo']['bracket'] ?? null) !== '3v3' || date('Y-m-d', intdiv($m['startTime'], 1000)) < $since || ! is_file(ARCHIVE."/raw/{$m['id']}.log.gz")) {
        continue;
    }
    $priests = array_values(array_filter($m['units'], fn ($u) => str_starts_with($u['id'], 'Player-') && (string) ($u['spec'] ?? '') === '256'));
    $t0 = null;
    $team = [];
    $names = [];
    $dispels = [];
    $auras = [];
    $open = [];
    $dmgTaken = [];
    $casts = [];
    $end = 0;
    foreach (gzfile(ARCHIVE."/raw/{$m['id']}.log.gz") as $line) {
        $s = $seconds($line);
        if ($s === null) {
            continue;
        }
        $t0 ??= $s;
        $t = $s - $t0;
        $end = $t;
        $body = rtrim(explode('  ', $line, 2)[1] ?? '');
        $event = strtok($body, ',');
        if ($event === 'COMBATANT_INFO') {
            $p = explode(',', $body, 4);
            $team[$p[1]] = $p[2];
        } elseif ($event === 'SPELL_DISPEL') {
            $f = str_getcsv($body);
            $dispels[] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'spell' => $f[10], 'removed' => $f[13], 'type' => $f[15] ?? ''];
            // Purify alone decides what is purifiable: Mass Dispel removes Cyclone (seen 4 times),
            // which is a long cooldown, not a chance a priest is expected to take.
            if ($f[10] === 'Purify' && ($f[15] ?? '') === 'DEBUFF') {
                $purifiable[$f[13]] = ($purifiable[$f[13]] ?? 0) + 1;
            }
        } elseif ($event === 'SPELL_AURA_APPLIED' || $event === 'SPELL_AURA_REMOVED') {
            $f = str_getcsv($body);
            if (! str_starts_with($f[5], 'Player-') || ($f[12] ?? '') !== 'DEBUFF') {
                continue;
            }
            $names[$f[5]] = $f[6];
            $k = $f[5].'|'.$f[10].'|'.$f[1];
            if ($event === 'SPELL_AURA_APPLIED') {
                $open[$k] ??= ['dst' => $f[5], 'src' => $f[1], 'name' => $f[10], 'id' => (int) $f[9], 'from' => $t];
            } elseif (isset($open[$k])) {
                $auras[] = $open[$k] + ['to' => $t];
                unset($open[$k]);
            }
        } elseif (str_ends_with($event, '_DAMAGE') && $event !== 'ENVIRONMENTAL_DAMAGE') {
            $f = str_getcsv($body);
            if (str_starts_with($f[5], 'Player-')) {
                $n = count($f);
                // amount: 11 from the end of a spell damage line, 10 for a swing (CombatantThroughputService)
                $dmgTaken[$f[5]] = ($dmgTaken[$f[5]] ?? 0) + max(0, (int) ($f[$n - (str_starts_with($event, 'SWING') ? 10 : 11)] ?? 0));
            }
        } elseif ($event === 'SPELL_CAST_SUCCESS') {
            $f = str_getcsv($body);
            if (str_starts_with($f[1], 'Player-') && in_array($f[10], ['Purify', 'Mass Dispel', 'Psychic Scream', 'Dispel Magic'], true)) {
                $casts[] = ['t' => $t, 'src' => $f[1], 'spell' => $f[10]];
            }
        }
    }
    foreach ($open as $o) {
        $auras[] = $o + ['to' => $end];
    }
    $games[] = ['id' => $m['id'], 'when' => date('m-d H:i', intdiv($m['startTime'], 1000)), 'won' => $m['result'] ?? null, 'priests' => $priests, 'team' => $team,
        'dispels' => $dispels, 'auras' => $auras, 'dmgTaken' => $dmgTaken, 'casts' => $casts, 'mins' => max(0.1, $end / 60), 'units' => $m['units']];
}
arsort($purifiable);
echo "3v3 games read: ".count($games)."\n";
echo "Debuffs a Purify or Mass Dispel was seen removing (top 25 of ".count($purifiable)."):\n  ";
echo implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys(array_slice($purifiable, 0, 25, true)), array_slice($purifiable, 0, 25, true)))."\n";

// Pass 2: one row per (game, Disc Priest).
$rows = [];
foreach ($games as $g) {
    foreach ($g['priests'] as $p) {
        $guid = $p['id'];
        $side = $g['team'][$guid] ?? null;
        if ($side === null) {
            continue;
        }
        $mates = array_keys(array_filter($g['team'], fn ($s) => $s === $side));
        $enemies = array_keys(array_filter($g['team'], fn ($s) => $s !== $side));
        // opportunity: enemy-applied purifiable debuffs on his team, merged per player
        $opp = 0.0;
        foreach ($mates as $mate) {
            $iv = [];
            foreach ($g['auras'] as $a) {
                if ($a['dst'] === $mate && isset($purifiable[$a['name']]) && in_array($a['src'], $enemies, true)) {
                    $iv[] = [$a['from'], $a['to']];
                }
            }
            usort($iv, fn ($a, $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($iv as [$a, $b]) {
                if ($merged && $a <= $merged[count($merged) - 1][1]) {
                    $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $b);
                } else {
                    $merged[] = [$a, $b];
                }
            }
            $opp += array_sum(array_map(fn ($x) => $x[1] - $x[0], $merged));
        }
        $removed = count(array_filter($g['dispels'], fn ($d) => $d['src'] === $guid && in_array($d['spell'], PRIEST_DISPELS, true) && $d['type'] === 'DEBUFF' && in_array($d['dst'], $mates, true)));
        $purifyCasts = count(array_filter($g['casts'], fn ($c) => $c['src'] === $guid && $c['spell'] === 'Purify'));
        $screams = count(array_filter($g['casts'], fn ($c) => $c['src'] === $guid && $c['spell'] === 'Psychic Scream'));
        // his own lockout, merged
        $iv = [];
        foreach ($g['auras'] as $a) {
            if ($a['dst'] === $guid && in_array($cc[$a['id']] ?? null, LOCKOUT, true) && $a['name'] !== 'Garrote') {
                $iv[] = [$a['from'], $a['to']];
            }
        }
        usort($iv, fn ($a, $b) => $a[0] <=> $b[0]);
        $lock = 0.0;
        $endSeen = -1;
        foreach ($iv as [$a, $b]) {
            if ($b > $endSeen) {
                $lock += $b - max($a, $endSeen);
                $endSeen = $b;
            }
        }
        // The coachable case: a teammate in purifiable CROWD CONTROL (a lockout or a root) for 2s+,
        // while this priest was free. Did he take it off, and how long did it sit first?
        $myLock = $iv;
        $freeAt = function (float $t) use ($myLock) {
            foreach ($myLock as [$a, $b]) {
                if ($t >= $a && $t < $b) {
                    return false;
                }
            }

            return true;
        };
        $ccChances = [];
        foreach ($g['auras'] as $a) {
            $cat = $cc[$a['id']] ?? null;
            if ($a['dst'] === $guid || ! in_array($a['dst'], $mates, true) || ! in_array($a['src'], $enemies, true) || ! isset($purifiable[$a['name']])
                || ! in_array($cat, ['Stun', 'Silence', 'Disorient', 'Incapacitate', 'Root'], true) || $a['to'] - $a['from'] < 2 || ! $freeAt($a['from'] + 0.5)) {
                continue;
            }
            $hit = array_values(array_filter($g['dispels'], fn ($d) => $d['src'] === $guid && $d['dst'] === $a['dst'] && $d['removed'] === $a['name'] && $d['t'] >= $a['from'] && $d['t'] <= $a['to'] + 0.2));
            $ccChances[] = ['name' => $a['name'], 'cat' => $cat, 'removed' => (bool) $hit, 'after' => $hit ? $hit[0]['t'] - $a['from'] : null, 'len' => $a['to'] - $a['from']];
        }
        $teamTaken = array_sum(array_map(fn ($x) => $g['dmgTaken'][$x] ?? 0, $mates));
        $name = explode('-', $p['name'])[0];
        $rows[] = ['name' => $name, 'when' => $g['when'], 'mins' => $g['mins'], 'opp' => $opp, 'removed' => $removed, 'purify' => $purifyCasts,
            'screams' => $screams, 'ccChances' => $ccChances, 'lockShare' => $lock / ($g['mins'] * 60), 'teamTakenPm' => $teamTaken / $g['mins'] / 1000,
            'enemy' => implode(' / ', array_map(fn ($u) => $u['specName'] ?? ($u['spec'] ?? '?'), array_filter($g['units'], fn ($u) => in_array($u['id'], $enemies, true))))];
    }
}

$sum = fn ($rs, $k) => array_sum(array_column($rs, $k));
$line = function (string $label, array $rs) use ($sum) {
    if (! $rs) {
        printf("  %-38s -\n", $label);

        return;
    }
    $mins = $sum($rs, 'mins');
    $opp = $sum($rs, 'opp');
    printf("  %-38s %3d games | to-dispel %5.1fs/min | removed %4.2f/min | removed per minute OF opportunity %4.2f | Purify casts %4.2f/min | locked %2.0f%% | Screams %4.2f/min | team dmg taken %4.0fk/min\n",
        $label, count($rs), $opp / $mins, $sum($rs, 'removed') / $mins, $opp > 0 ? $sum($rs, 'removed') / ($opp / 60) : 0,
        $sum($rs, 'purify') / $mins, 100 * array_sum(array_map(fn ($r) => $r['lockShare'] * $r['mins'], $rs)) / $mins, $sum($rs, 'screams') / $mins, array_sum(array_map(fn ($r) => $r['teamTakenPm'] * $r['mins'], $rs)) / $mins);
};

$mine = array_values(array_filter($rows, fn ($r) => $r['name'] === $meName));
$others = array_values(array_filter($rows, fn ($r) => $r['name'] !== $meName));
echo "\n=== Every Disc Priest, against the same measure of opportunity\n";
$line($meName, $mine);
$byName = [];
foreach ($others as $r) {
    $byName[$r['name']][] = $r;
}
uasort($byName, fn ($a, $b) => count($b) <=> count($a));
foreach (array_slice($byName, 0, 3, true) as $n => $rs) {
    $line($n, $rs);
}
$line('all other Disc Priests', $others);

// Chriso's hypothesis: is his dispel rate (per minute of opportunity) lower when the pressure is higher?
$median = function (array $v) {
    sort($v);

    return $v[intdiv(count($v), 2)] ?? 0;
};
echo "\n=== {$meName}: the same split by pressure (each split at his median game)\n";
foreach (['teamTakenPm' => 'team damage taken a minute', 'lockShare' => 'his own lockout share', 'screams' => 'his Psychic Screams'] as $k => $lab) {
    $mid = $median(array_column($mine, $k));
    $line("{$lab}: low", array_values(array_filter($mine, fn ($r) => $r[$k] < $mid)));
    $line("{$lab}: high", array_values(array_filter($mine, fn ($r) => $r[$k] >= $mid)));
}
echo "\n=== {$meName}: by how much there was to dispel\n";
$mid = $median(array_column(array_map(fn ($r) => ['x' => $r['opp'] / $r['mins']], $mine), 'x'));
$line('little to dispel', array_values(array_filter($mine, fn ($r) => $r['opp'] / $r['mins'] < $mid)));
$line('a lot to dispel', array_values(array_filter($mine, fn ($r) => $r['opp'] / $r['mins'] >= $mid)));

echo "\n=== Teammate in purifiable crowd control (lockout or root) for 2s+, with the priest free as it landed\n";
$ccLine = function (string $label, array $rs) {
    $ch = array_merge(...array_map(fn ($r) => $r['ccChances'], $rs ?: [['ccChances' => []]]));
    if (! $ch) {
        printf("  %-24s -\n", $label);

        return;
    }
    $rem = array_filter($ch, fn ($c) => $c['removed']);
    $after = array_column($rem, 'after');
    sort($after);
    printf("  %-24s %4d chances | removed %3d (%2d%%) | median %.1fs before removing it | left in it: %.0fs in all\n", $label, count($ch), count($rem), round(100 * count($rem) / count($ch)),
        $after[intdiv(count($after), 2)] ?? 0, array_sum(array_map(fn ($c) => $c['len'], array_filter($ch, fn ($c) => ! $c['removed']))));
};
$ccLine($meName, $mine);
foreach (array_slice($byName, 0, 3, true) as $n => $rs) {
    $ccLine($n, $rs);
}
$ccLine('all other Disc Priests', $others);
echo "  {$meName}, what was left on, most common:\n";
$left = [];
foreach ($mine as $r) {
    foreach ($r['ccChances'] as $c) {
        if (! $c['removed']) {
            $left[$c['name'].' ('.$c['cat'].')'] = ($left[$c['name'].' ('.$c['cat'].')'] ?? 0) + 1;
        }
    }
}
arsort($left);
foreach (array_slice($left, 0, 10, true) as $k => $v) {
    echo "    {$k}: {$v}\n";
}
