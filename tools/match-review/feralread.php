<?php

// How a Feral spends: combo points per finisher, what each proc was spent on (or let expire), whether
// bleeds went out inside Tiger's Fury, what follows Tiger's Fury, what happens inside Incarnation,
// damage per press and per energy, and the damage mix inside the team's goes against outside them.
// Written 2026-10-01 for "how does my Feral differ from Rastic's" (match-review-analysis.md).
//
//   php -d memory_limit=1G tools/match-review/feralread.php --since=2026-09-01 --with=Doubletapz \
//       "--col=Crawlordx:Crawlordx" "--col=Rastic:Rastic" "--col=Others 2100+:*:2100:9999" [--windows=Crawlordx,Rastic]
//
// Columns work as in specread.php. --windows prints every Incarnation window, cast by cast, for those players.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\ArenaMomentService;

const ARCHIVE = 'D:/MindCollector/arena-logs';
const SPEC = '103';
const CONSUMED_WITHIN = 0.35;  // a proc removed this close to one of the player's casts was spent by it
const AFTER_TF = 8.0;          // the stretch after Tiger's Fury whose presses are listed
const GO_GAP = 10.0;           // offensive casts of one team this close are one go (killread --strict)
const GO_TAIL = 15.0;
const PROCS = ['Predatory Swiftness', 'Sudden Ambush', "Apex Predator's Craving", 'Clearcasting', 'Coiled to Spring', 'Frantic Momentum'];
const FINISHERS = ['Rip', 'Ferocious Bite', 'Maim', 'Primal Wrath'];
const INCARN = 'Incarnation: Avatar of Ashamane';
// Two auras carry that name. 102543 is the form itself (about 25s); 252071 is a flag that lets you
// Prowl in combat, and Prowl removes it within a second. Keyed by name alone, every window "ended"
// at the Prowl.
const INCARN_FLAG = 252071;
const CC = ['Cyclone', 'Maim', 'Mighty Bash', 'Incapacitating Roar', 'Entangling Roots', 'Typhoon', "Ursol's Vortex", 'Skull Bash'];

$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$since = $opt('since', '2026-09-01');
$with = $opt('with');
$windowsFor = array_filter(explode(',', (string) $opt('windows', '')));
$cols = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--col=')) {
        $p = explode(':', substr($a, 6));
        $cols[] = ['label' => $p[0], 'name' => $p[1] ?? '*', 'lo' => (int) ($p[2] ?? 0), 'hi' => (int) ($p[3] ?? 99999)];
    }
}
$named = array_values(array_filter(array_column($cols, 'name'), fn ($n) => $n !== '*'));
$svc = app(ArenaMomentService::class);
$short = fn ($n) => explode('-', $n)[0];
$seconds = function (string $line): ?float {
    if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
        return null;
    }

    return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
};

$recs = [];
$windowLines = [];
foreach (glob(ARCHIVE.'/metadata/*.json') as $file) {
    $m = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file)), true);
    if (($m['startInfo']['bracket'] ?? null) !== '3v3' || date('Y-m-d', intdiv($m['startTime'], 1000)) < $since) {
        continue;
    }
    $ferals = array_filter($m['units'], fn ($u) => str_starts_with($u['id'], 'Player-') && (string) ($u['spec'] ?? '') === SPEC);
    if (! $ferals) {
        continue;
    }
    $lines = array_map('rtrim', gzfile(ARCHIVE."/raw/{$m['id']}.log.gz"));
    $roster = $svc->roster($m);
    $tl = $svc->readTimeline($lines, $roster);
    $clock = date('m-d H:i', intdiv($m['startTime'], 1000));
    $names = array_map(fn ($r) => $short($r['name']), $roster);

    $t0 = null;
    $team = [];
    $end = null;
    $casts = [];
    $dmg = [];
    $auras = [];   // [dst, src, name, event, t]
    foreach ($lines as $line) {
        $s = $seconds($line);
        if ($s === null) {
            continue;
        }
        $t0 ??= $s;
        $t = round($s - $t0, 2);
        $body = explode('  ', $line, 2)[1] ?? '';
        $ev = strtok($body, ',');
        if ($ev === 'COMBATANT_INFO') {
            $p = explode(',', $body, 4);
            $team[$p[1]] = $p[2];
        } elseif ($ev === 'ARENA_MATCH_END') {
            $end = explode(',', $body);
        } elseif ($ev === 'SPELL_CAST_SUCCESS') {
            $f = str_getcsv($body);
            if (! isset($roster[$f[1]])) {
                continue;
            }
            $n = count($f);
            // Power is read from the end; a finisher logs two types, "3|4" = energy|combo points.
            $cost = explode('|', (string) ($f[$n - 6] ?? '0'));
            $cur = explode('|', (string) ($f[$n - 8] ?? '0'));
            $casts[] = ['t' => $t, 'src' => $f[1], 'spell' => $f[10], 'energy' => (int) $cost[0], 'energyNow' => (int) $cur[0],
                'cp' => isset($cost[1]) ? (int) $cost[1] : null];
        } elseif (in_array($ev, ['SPELL_AURA_APPLIED', 'SPELL_AURA_REFRESH', 'SPELL_AURA_REMOVED'], true)) {
            $f = str_getcsv($body);
            if (isset($roster[$f[5]])) {
                $auras[] = ['dst' => $f[5], 'src' => $f[1], 'name' => (int) $f[9] === INCARN_FLAG ? 'Incarnation (Prowl flag)' : $f[10], 'ev' => $ev, 't' => $t, 'type' => $f[12] ?? ''];
            }
        } elseif (in_array($ev, ['SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'SWING_DAMAGE_LANDED'], true)) {
            $f = str_getcsv($body);
            $swing = $ev === 'SWING_DAMAGE_LANDED';
            $amount = $f[count($f) - ($swing ? 10 : 11)] ?? null;
            if (is_numeric($amount) && isset($roster[$f[5]]) && isset($roster[$f[1]])) {
                $dmg[] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'amount' => (int) $amount, 'spell' => $swing ? 'Melee' : $f[10]];
            }
        }
    }
    $deathAt = array_column($tl['deaths'], 't', 'who');

    foreach ($ferals as $u) {
        $g = $u['id'];
        if (! isset($roster[$g], $team[$g])) {
            continue;
        }
        $name = $short($u['name']);
        $side = $roster[$g]['side'];
        $mates = array_keys(array_filter($roster, fn ($r, $x) => $r['side'] === $side && $x !== $g, ARRAY_FILTER_USE_BOTH));
        $mmr = $end ? (int) ($end[3 + (int) $team[$g]] ?? 0) : 0;
        $alive = min($deathAt[$g] ?? (float) $m['durationInSeconds'], (float) $m['durationInSeconds']);
        $my = array_values(array_filter($casts, fn ($c) => $c['src'] === $g && $c['t'] <= $alive));
        $r = ['name' => $name, 'clock' => $clock, 'mmr' => $mmr, 'won' => $end ? $end[1] === $team[$g] : null, 'alive' => max(1, $alive),
            'mates' => array_map(fn ($x) => $names[$x], $mates), 'casts' => [], 'energy' => [], 'cp' => [], 'free' => [],
            'proc' => [], 'snap' => [], 'afterTf' => [], 'incarn' => ['sec' => 0, 'dmg' => 0, 'casts' => [], 'n' => 0],
            'dmgBy' => [], 'dmgTotal' => 0, 'goDmg' => [], 'goSec' => 0, 'goTotal' => 0, 'rakeStun' => 0];
        foreach ($my as $c) {
            $r['casts'][$c['spell']] = ($r['casts'][$c['spell']] ?? 0) + 1;
            $r['energy'][$c['spell']] = ($r['energy'][$c['spell']] ?? 0) + $c['energy'];
            if ($c['energy'] === 0) {
                $r['free'][$c['spell']] = ($r['free'][$c['spell']] ?? 0) + 1;
            }
            if (in_array($c['spell'], FINISHERS, true) && $c['cp'] !== null) {
                $r['cp'][$c['spell']][] = $c['cp'];
            }
        }

        // Own buffs on self, as intervals, and every proc's fate.
        $mine = array_values(array_filter($auras, fn ($a) => $a['dst'] === $g && $a['src'] === $g && $a['type'] === 'BUFF' && $a['t'] <= $alive));
        $up = [];
        $intervals = [];
        foreach ($mine as $a) {
            if ($a['ev'] === 'SPELL_AURA_APPLIED') {
                $up[$a['name']] = $a['t'];
                if (in_array($a['name'], PROCS, true)) {
                    $r['proc'][$a['name']]['gained'] = ($r['proc'][$a['name']]['gained'] ?? 0) + 1;
                }
            } elseif ($a['ev'] === 'SPELL_AURA_REFRESH') {
                if (in_array($a['name'], PROCS, true)) {
                    // Procced again while already up: one of the two is lost unless the buff stacks.
                    $r['proc'][$a['name']]['refreshed'] = ($r['proc'][$a['name']]['refreshed'] ?? 0) + 1;
                }
            } elseif (isset($up[$a['name']])) {
                $intervals[$a['name']][] = [$up[$a['name']], $a['t']];
                unset($up[$a['name']]);
                if (in_array($a['name'], PROCS, true)) {
                    $near = null;
                    foreach ($my as $c) {
                        if (abs($c['t'] - $a['t']) <= CONSUMED_WITHIN && ! in_array($c['spell'], ['Cat Form', 'Bear Form', 'Prowl'], true)) {
                            $near = $c['spell'];
                        }
                    }
                    $k = $near ? 'on '.$near : 'expired';
                    $r['proc'][$a['name']][$k] = ($r['proc'][$a['name']][$k] ?? 0) + 1;
                }
            }
        }
        foreach ($up as $k => $from) {
            $intervals[$k][] = [$from, $alive];
        }
        $isUp = function (string $buff, float $t) use (&$intervals) {
            foreach ($intervals[$buff] ?? [] as [$a, $b]) {
                if ($t - $a >= 0.1 && $t <= $b) {
                    return true;
                }
            }

            return false;
        };

        // Bleeds pressed inside Tiger's Fury (they keep the bonus they were applied with).
        foreach ($my as $c) {
            if (in_array($c['spell'], ['Rip', 'Rake', 'Primal Wrath', 'Moonfire'], true)) {
                $r['snap'][$c['spell']]['n'] = ($r['snap'][$c['spell']]['n'] ?? 0) + 1;
                if ($isUp("Tiger's Fury", $c['t'])) {
                    $r['snap'][$c['spell']]['tf'] = ($r['snap'][$c['spell']]['tf'] ?? 0) + 1;
                }
            }
            if ($c['spell'] === "Tiger's Fury") {
                foreach ($my as $x) {
                    if ($x['t'] > $c['t'] && $x['t'] <= $c['t'] + AFTER_TF && ! in_array($x['spell'], ['Cat Form', "Tiger's Fury"], true)) {
                        $r['afterTf'][$x['spell']] = ($r['afterTf'][$x['spell']] ?? 0) + 1;
                    }
                }
                $r['afterTfN'] = ($r['afterTfN'] ?? 0) + 1;
            }
        }
        // Rake stuns this player landed (the stun is its own aura).
        $r['rakeStun'] = count(array_filter($tl['control'], fn ($c) => $c['by'] === $g && $c['spell'] === 'Rake' && $c['from'] <= $alive));

        // Damage by ability, inside and outside the team's goes (offensive casts only, killread --strict).
        $offs = array_values(array_filter($tl['commitments'], fn ($c) => in_array($c['kind'], ['offensive', 'mixed'], true)
            && ($roster[$c['who']]['side'] ?? null) === $side));
        $goes = [];
        foreach ($offs as $c) {
            if ($goes && $c['t'] - $goes[count($goes) - 1][2] <= GO_GAP) {
                $goes[count($goes) - 1][2] = $c['t'];
                $goes[count($goes) - 1][1] = $c['t'] + GO_TAIL;
            } else {
                $goes[] = [$c['t'], $c['t'] + GO_TAIL, $c['t']];
            }
        }
        $inGo = function (float $t) use ($goes) {
            foreach ($goes as [$a, $b]) {
                if ($t >= $a && $t <= $b) {
                    return true;
                }
            }

            return false;
        };
        foreach ($goes as [$a, $b]) {
            $r['goSec'] += max(0, min($b, $alive) - $a);
        }
        foreach ($dmg as $x) {
            if ($x['src'] !== $g || $x['t'] > $alive || ($roster[$x['dst']]['side'] ?? null) === $side) {
                continue;
            }
            $r['dmgBy'][$x['spell']] = ($r['dmgBy'][$x['spell']] ?? 0) + $x['amount'];
            $r['dmgTotal'] += $x['amount'];
            if ($inGo($x['t'])) {
                $r['goDmg'][$x['spell']] = ($r['goDmg'][$x['spell']] ?? 0) + $x['amount'];
                $r['goTotal'] += $x['amount'];
            }
            if ($isUp(INCARN, $x['t'])) {
                $r['incarn']['dmg'] += $x['amount'];
            }
        }
        foreach ($intervals[INCARN] ?? [] as [$a, $b]) {
            $r['incarn']['sec'] += $b - $a;
            $r['incarn']['n']++;
            $seq = [];
            foreach ($my as $c) {
                if ($c['t'] >= $a - 0.05 && $c['t'] <= $b) {
                    $r['incarn']['casts'][$c['spell']] = ($r['incarn']['casts'][$c['spell']] ?? 0) + 1;
                    $seq[] = $c['spell'].($c['cp'] !== null ? '('.$c['cp'].')' : '');
                }
            }
            if (in_array($name, $windowsFor, true)) {
                $windowLines[$name][] = sprintf('%s %s %5.1fs, %4.1fs: %s', $clock, $r['won'] ? 'W' : 'L', $a, $b - $a, implode(' > ', $seq));
            }
        }
        $recs[] = $r;
    }
}

// ---- columns
$sets = [];
foreach ($cols as $i => $col) {
    $sets[$col['label']] = array_values(array_filter($recs, function ($r) use ($col, $named, $with, $cols) {
        if ($col['name'] === '*' ? in_array($r['name'], $named, true) : $r['name'] !== $col['name']) {
            return false;
        }
        if ($r['mmr'] < $col['lo'] || $r['mmr'] > $col['hi']) {
            return false;
        }

        return ! ($col['name'] === $cols[0]['name'] && $with !== null && ! in_array($with, $r['mates'], true));
    }));
}
$min = fn ($s) => max(0.01, array_sum(array_column($s, 'alive')) / 60);
$sumMap = function (array $s, string $k) {
    $o = [];
    foreach ($s as $r) {
        foreach ($r[$k] as $n => $v) {
            $o[$n] = ($o[$n] ?? 0) + $v;
        }
    }

    return $o;
};
$w = 18;
$head = function (string $t) use ($sets, $w) {
    echo "\n=== {$t}\n".sprintf('   %-36s', '');
    foreach (array_keys($sets) as $l) {
        printf(" %{$w}s", mb_substr($l, 0, $w));
    }
    echo "\n";
};
$row = function (string $label, callable $fn) use ($sets, $w) {
    printf('   %-36s', mb_substr($label, 0, 36));
    foreach ($sets as $s) {
        $v = $s ? $fn($s) : '-';
        printf(" %{$w}s", $v);
    }
    echo "\n";
};

echo "FERAL READ — 3v3 since {$since}\n";
foreach ($sets as $l => $s) {
    printf("   %-16s %2d games, %.1f minutes alive (%s)\n", $l, count($s), $min($s), implode(', ', array_unique(array_column($s, 'name'))));
}

$head('COMBO POINTS SPENT PER FINISHER (presses | share at 5 | median) and free presses');
foreach (FINISHERS as $f) {
    $row($f, function ($s) use ($f) {
        $v = array_merge(...array_map(fn ($r) => $r['cp'][$f] ?? [], $s) ?: [[]]);
        if (! $v) {
            return '0';
        }
        sort($v);

        return sprintf('%d | %d%% | %d', count($v), 100 * count(array_filter($v, fn ($x) => $x >= 5)) / count($v), $v[intdiv(count($v), 2)]);
    });
}
$row('Ferocious Bite pressed free', fn ($s) => sprintf('%d of %d', array_sum(array_map(fn ($r) => $r['free']['Ferocious Bite'] ?? 0, $s)), array_sum(array_map(fn ($r) => $r['casts']['Ferocious Bite'] ?? 0, $s))));
$row('Shred pressed free', fn ($s) => sprintf('%d of %d', array_sum(array_map(fn ($r) => $r['free']['Shred'] ?? 0, $s)), array_sum(array_map(fn ($r) => $r['casts']['Shred'] ?? 0, $s))));

$head('PROCS: gained per minute | what spent them (share) | expired | procced again while up');
foreach (PROCS as $p) {
    $row($p, function ($s) use ($p, $min) {
        $agg = [];
        foreach ($s as $r) {
            foreach ($r['proc'][$p] ?? [] as $k => $v) {
                $agg[$k] = ($agg[$k] ?? 0) + $v;
            }
        }
        $g = $agg['gained'] ?? 0;
        if (! $g) {
            return '0';
        }

        return sprintf('%.1f/min', $g / $min($s));
    });
    $row('  spent / expired', function ($s) use ($p) {
        $agg = [];
        foreach ($s as $r) {
            foreach ($r['proc'][$p] ?? [] as $k => $v) {
                $agg[$k] = ($agg[$k] ?? 0) + $v;
            }
        }
        $ended = array_sum(array_filter($agg, fn ($v, $k) => str_starts_with($k, 'on ') || $k === 'expired', ARRAY_FILTER_USE_BOTH));
        if (! $ended) {
            return '-';
        }

        return sprintf('%d%% / %d%%', 100 * ($ended - ($agg['expired'] ?? 0)) / $ended, 100 * ($agg['expired'] ?? 0) / $ended);
    });
    $row('  spent on (top 2)', function ($s) use ($p) {
        $agg = [];
        foreach ($s as $r) {
            foreach ($r['proc'][$p] ?? [] as $k => $v) {
                if (str_starts_with($k, 'on ')) {
                    $agg[substr($k, 3)] = ($agg[substr($k, 3)] ?? 0) + $v;
                }
            }
        }
        arsort($agg);
        $t = max(1, array_sum($agg));

        return implode(' ', array_map(fn ($k, $v) => mb_substr($k, 0, 6).' '.round(100 * $v / $t).'%', array_slice(array_keys($agg), 0, 2), array_slice($agg, 0, 2)));
    });
}

$head("BLEEDS PRESSED INSIDE TIGER'S FURY (share of presses)");
foreach (['Rip', 'Rake', 'Primal Wrath', 'Moonfire'] as $b) {
    $row($b, function ($s) use ($b) {
        $n = array_sum(array_map(fn ($r) => $r['snap'][$b]['n'] ?? 0, $s));
        $tf = array_sum(array_map(fn ($r) => $r['snap'][$b]['tf'] ?? 0, $s));

        return $n ? sprintf('%d%% of %d', 100 * $tf / $n, $n) : '0';
    });
}

$head('THE '.AFTER_TF."s AFTER TIGER'S FURY: presses per Tiger's Fury");
$afterAll = [];
foreach ($sets as $s) {
    foreach ($sumMap($s, 'afterTf') as $k => $v) {
        $afterAll[$k] = ($afterAll[$k] ?? 0) + $v;
    }
}
arsort($afterAll);
foreach (array_slice(array_keys($afterAll), 0, 14) as $k) {
    $row($k, fn ($s) => sprintf('%.2f', ($sumMap($s, 'afterTf')[$k] ?? 0) / max(1, array_sum(array_column($s, 'afterTfN')))));
}
$row('  control of any kind', fn ($s) => sprintf('%.2f', array_sum(array_intersect_key($sumMap($s, 'afterTf'), array_flip(CC))) / max(1, array_sum(array_column($s, 'afterTfN')))));

$head('RAKE STUNS: landed per Rake pressed');
$row('Rake stuns / Rakes', fn ($s) => sprintf('%d / %d (%d%%)', array_sum(array_column($s, 'rakeStun')), $n = array_sum(array_map(fn ($r) => $r['casts']['Rake'] ?? 0, $s)), 100 * array_sum(array_column($s, 'rakeStun')) / max(1, $n)));

$head('DAMAGE PER PRESS | PER 100 ENERGY SPENT (bleeds include every tick of the bleed)');
foreach (['Rake', 'Rip', 'Ferocious Bite', 'Shred', 'Moonfire', 'Feral Frenzy', 'Primal Wrath'] as $a) {
    $row($a, function ($s) use ($a, $sumMap) {
        $d = $sumMap($s, 'dmgBy')[$a] ?? 0;
        $n = $sumMap($s, 'casts')[$a] ?? 0;
        $e = $sumMap($s, 'energy')[$a] ?? 0;

        return $n ? sprintf('%dk | %s', $d / $n / 1000, $e ? round($d / $e / 10).'k' : 'free') : '0';
    });
}

$head('INCARNATION: per window');
$row('windows, average length', fn ($s) => sprintf('%d, %.1fs', $n = array_sum(array_map(fn ($r) => $r['incarn']['n'], $s)), array_sum(array_map(fn ($r) => $r['incarn']['sec'], $s)) / max(1, $n)));
$row('own damage per second inside', fn ($s) => sprintf('%dk', array_sum(array_map(fn ($r) => $r['incarn']['dmg'], $s)) / max(1, array_sum(array_map(fn ($r) => $r['incarn']['sec'], $s))) / 1000));
$row('own damage per second outside', fn ($s) => sprintf('%dk', (array_sum(array_column($s, 'dmgTotal')) - array_sum(array_map(fn ($r) => $r['incarn']['dmg'], $s))) / max(1, array_sum(array_column($s, 'alive')) - array_sum(array_map(fn ($r) => $r['incarn']['sec'], $s))) / 1000));
$incAll = [];
foreach ($sets as $s) {
    foreach ($s as $r) {
        foreach ($r['incarn']['casts'] as $k => $v) {
            $incAll[$k] = ($incAll[$k] ?? 0) + $v;
        }
    }
}
arsort($incAll);
foreach (array_slice(array_keys($incAll), 0, 14) as $k) {
    $row('  '.$k.' per window', fn ($s) => sprintf('%.1f', array_sum(array_map(fn ($r) => $r['incarn']['casts'][$k] ?? 0, $s)) / max(1, array_sum(array_map(fn ($r) => $r['incarn']['n'], $s)))));
}

$head("DAMAGE INSIDE THE TEAM'S GOES (share of own damage; ability mix inside goes)");
$row('share of alive time inside goes', fn ($s) => sprintf('%d%%', 100 * array_sum(array_column($s, 'goSec')) / max(1, array_sum(array_column($s, 'alive')))));
$row('share of own damage inside goes', fn ($s) => sprintf('%d%%', 100 * array_sum(array_column($s, 'goTotal')) / max(1, array_sum(array_column($s, 'dmgTotal')))));
$row('damage per second inside goes', fn ($s) => sprintf('%dk', array_sum(array_column($s, 'goTotal')) / max(1, array_sum(array_column($s, 'goSec'))) / 1000));
$goAll = [];
foreach ($sets as $s) {
    foreach ($sumMap($s, 'goDmg') as $k => $v) {
        $goAll[$k] = ($goAll[$k] ?? 0) + $v;
    }
}
arsort($goAll);
foreach (array_slice(array_keys($goAll), 0, 12) as $k) {
    $row('  '.$k, fn ($s) => sprintf('%.1f%%', 100 * ($sumMap($s, 'goDmg')[$k] ?? 0) / max(1, array_sum(array_column($s, 'goTotal')))));
}

echo "\n=== PER PLAYER, the non-named column one by one (damage per second inside goes | Shred, Moonfire, Rake, Bite, Rip share inside goes)\n";
foreach ($sets as $l => $s) {
    foreach ($s as $r) {
        if (in_array($r['name'], $named, true)) {
            continue;
        }
        $t = max(1, $r['goTotal']);
        printf("   %-14s %s MMR %d %s | %4dk/s in goes | Shred %2d%% Moonfire %2d%% Rake %2d%% Bite %2d%% Rip %2d%% | presses a minute: Shred %.1f Moonfire %.1f Rake %.1f Bite %.1f\n",
            $r['name'], $r['clock'], $r['mmr'], $r['won'] ? 'W' : 'L', $r['goTotal'] / max(1, $r['goSec']) / 1000,
            100 * ($r['goDmg']['Shred'] ?? 0) / $t, 100 * ($r['goDmg']['Moonfire'] ?? 0) / $t, 100 * ($r['goDmg']['Rake'] ?? 0) / $t,
            100 * ($r['goDmg']['Ferocious Bite'] ?? 0) / $t, 100 * ($r['goDmg']['Rip'] ?? 0) / $t,
            ($r['casts']['Shred'] ?? 0) / ($r['alive'] / 60), ($r['casts']['Moonfire'] ?? 0) / ($r['alive'] / 60),
            ($r['casts']['Rake'] ?? 0) / ($r['alive'] / 60), ($r['casts']['Ferocious Bite'] ?? 0) / ($r['alive'] / 60));
    }
}

foreach ($windowLines as $who => $ls) {
    echo "\n=== EVERY INCARNATION WINDOW: {$who} (finishers show the combo points spent)\n   ".implode("\n   ", $ls)."\n";
}
