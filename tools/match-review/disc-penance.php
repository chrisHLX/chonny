<?php
// Every Penance cast on an enemy by every Disc in the archive: which of Harsh Discipline, Power of
// the Dark Side and Archangel were up, how many bolts fired, how big a bolt was, and the gross
// healing it caused (overheal included, so missing health does not cap it). Read-only.
$dir = 'D:/MindCollector/arena-logs';
const TRACK = ['Harsh Discipline', 'Power of the Dark Side', 'Archangel'];
const GRACE = 0.25;   // a buff spent in the same moment as the cast
const LINK = 0.05;    // an Atonement heal this soon after a bolt came from that bolt
const GAP = 1.2;      // a channel has ended when no bolt came for this long

function secs(string $ts): float
{
    static $cache = [];
    [$whole, $frac] = explode('.', $ts, 2);
    $cache[$whole] ??= strtotime($whole);

    return $cache[$whole] + ((int) substr($frac, 0, 3)) / 1000;
}

$rows = [];
$links = ['Harsh Discipline' => [0, 0], 'Archangel' => [0, 0], 'pods' => ['Mind Blast' => 0, 'Shadow Word: Pain' => 0, 'other' => 0]];
$crit = [0, 0];

$finish = function (array &$st, string $name) use (&$rows, &$crit) {
    $c = $st['open'] ?? null;
    $st['open'] = null;
    if (! $c || ! $c['bolts']) {
        return;
    }
    $b2 = null;
    foreach ($c['bolts'] as $i => $b) {
        $crit[1]++;
        $crit[0] += $b['crit'] ? 1 : 0;
        if ($i === 1 && ! $b['crit']) {
            $b2 = $b['pre'];
        }
    }
    sort($c['conv']);
    $rows[] = ['e' => $c['enemy'] ? 1 : 0, 'p' => $name, 'm' => $c['m'], 't' => round($c['t'], 2), 'h' => $c['h'], 'd' => $c['d'], 'a' => $c['a'], 'bolts' => count($c['bolts']),
        'b2' => $b2, 'heal' => $c['heal'], 'aton' => $c['aton'], 'damp' => $c['damp'], 'conv' => $c['conv'] ? $c['conv'][intdiv(count($c['conv']), 2)] : null,
        'src' => $c['src']];
};

foreach (glob($dir.'/metadata/*.json') as $mf) {
    $meta = json_decode(file_get_contents($mf), true);
    $discs = [];
    foreach ($meta['units'] ?? [] as $u) {
        if ((int) ($u['spec'] ?? 0) === 256 && str_starts_with($u['id'] ?? '', 'Player-')) {
            $discs[$u['id']] = $u['name'];
        }
    }
    $rawPath = $dir.'/raw/'.$meta['id'].'.log.gz';
    if (! $discs || ! is_file($rawPath)) {
        continue;
    }
    $raw = @gzdecode(file_get_contents($rawPath));
    if ($raw === false) {
        continue;
    }
    $teams = [];
    $t0 = null;
    $state = [];
    foreach ($discs as $g => $_) {
        $state[$g] = ['stacks' => [], 'before' => [], 'gone' => [], 'made' => [], 'press' => [], 'aton' => [], 'damp' => 0, 'open' => null];
    }
    foreach (explode("\n", $raw) as $line) {
        $p = strpos($line, '  ');
        if ($p === false) {
            continue;
        }
        $body = rtrim(substr($line, $p + 2));
        if (str_starts_with($body, 'ARENA_MATCH_START')) {
            $t0 ??= secs(substr($line, 0, $p));

            continue;
        }
        if (str_starts_with($body, 'COMBATANT_INFO,')) {
            $x = explode(',', $body, 4);
            $teams[$x[1]] = $x[2];
            $t0 ??= secs(substr($line, 0, $p));

            continue;
        }
        if (! str_starts_with($body, 'SPELL_')) {
            continue;
        }
        $mine = false;
        foreach ($discs as $g => $_) {
            if (str_contains($body, $g)) {
                $mine = true;
                break;
            }
        }
        if (! $mine) {
            continue;
        }
        $f = str_getcsv($body, ',', '"', '');
        $ev = $f[0];
        $n = count($f);
        $src = $f[1] ?? '';
        $dst = $f[5] ?? '';
        $name = $f[10] ?? '';
        $t = secs(substr($line, 0, $p));

        foreach ($state as $g => &$st) {
            if ($st['open'] && $t - max($st['open']['t'], $st['open']['last']) > GAP) {
                $finish($st, $discs[$g]);
            }
        }
        unset($st);

        if (str_starts_with($ev, 'SPELL_AURA_')) {
            if ($name === 'Atonement' && isset($state[$src]) && str_starts_with($dst, 'Player-')) {
                if ($ev === 'SPELL_AURA_APPLIED') {
                    $state[$src]['aton'][$dst] = true;
                } elseif ($ev === 'SPELL_AURA_REMOVED') {
                    unset($state[$src]['aton'][$dst]);
                }
            }
            if ($name === 'Dampening' && isset($state[$dst])) {
                $state[$dst]['damp'] = str_contains($ev, 'DOSE') ? (int) end($f) : max(1, $state[$dst]['damp']);
            }
            if (in_array($name, TRACK, true) && isset($state[$dst])) {
                $st = &$state[$dst];
                $now = $st['stacks'][$name] ?? 0;
                if ($ev === 'SPELL_AURA_APPLIED' || $ev === 'SPELL_AURA_APPLIED_DOSE') {
                    $st['stacks'][$name] = $ev === 'SPELL_AURA_APPLIED' ? 1 : (int) end($f);
                    $st['made'][$name] = $t;
                    $st['open_link'][$name] = true;
                    if ($name === 'Power of the Dark Side') {
                        $links['pods']['total'] = ($links['pods']['total'] ?? 0) + 1;
                        $st['podsFrom'] = null;
                        if ($t - ($st['press']['Mind Blast'] ?? -99) <= 0.5) {
                            $links['pods']['Mind Blast']++;
                            $st['podsFrom'] = 'Mind Blast';
                            $st['open_link'][$name] = false;
                        } elseif ($t - ($st['tick'] ?? -99) <= 0.1) {
                            $links['pods']['Shadow Word: Pain']++;
                            $st['podsFrom'] = 'Shadow Word: Pain';
                            $st['open_link'][$name] = false;
                        }
                    } else {
                        $links[$name][1]++;
                        $press = $name === 'Archangel' ? 'Evangelism' : 'Power Word: Radiance';
                        if ($t - ($st['press'][$press] ?? -99) <= 0.5) {
                            $links[$name][0]++;
                            $st['open_link'][$name] = false;
                        }
                    }
                } elseif ($ev === 'SPELL_AURA_REMOVED_DOSE') {
                    $st['before'][$name] = $now;
                    $st['gone'][$name] = $t;
                    $st['stacks'][$name] = (int) end($f);
                } elseif ($ev === 'SPELL_AURA_REMOVED') {
                    $st['before'][$name] = max(1, $now);
                    $st['gone'][$name] = $t;
                    $st['stacks'][$name] = 0;
                }
                unset($st);
            }

            continue;
        }
        if (! isset($state[$src])) {
            continue;
        }
        $st = &$state[$src];
        if ($ev === 'SPELL_PERIODIC_DAMAGE' && $name === 'Shadow Word: Pain') {
            $st['tick'] = $t;
            if (($st['open_link']['Power of the Dark Side'] ?? false) && $t - ($st['made']['Power of the Dark Side'] ?? -99) <= 0.1) {
                $links['pods']['Shadow Word: Pain']++;
                $st['podsFrom'] = 'Shadow Word: Pain';
                $st['open_link']['Power of the Dark Side'] = false;
            }
        }
        if ($ev === 'SPELL_CAST_SUCCESS') {
            $st['press'][$name] = $t;
            foreach (['Power Word: Radiance' => 'Harsh Discipline', 'Evangelism' => 'Archangel', 'Mind Blast' => 'Power of the Dark Side'] as $press => $buff) {
                if ($name === $press && ($st['open_link'][$buff] ?? false) && $t - ($st['made'][$buff] ?? -99) <= 0.5) {
                    $st['open_link'][$buff] = false;
                    if ($buff === 'Power of the Dark Side') {
                        $links['pods']['Mind Blast']++;
                        $st['podsFrom'] = 'Mind Blast';
                    } else {
                        $links[$buff][0]++;
                    }
                }
            }
            if ($name === 'Penance') {
                $finish($st, $discs[$src]);
                $up = function ($b) use ($st, $t) {
                    $s = $st['stacks'][$b] ?? 0;
                    if ($s > 0) {
                        return $s;
                    }

                    return $t - ($st['gone'][$b] ?? -99) <= GRACE ? ($st['before'][$b] ?? 1) : 0;
                };
                $ally = isset($teams[$dst]) && $teams[$dst] === ($teams[$src] ?? null);
                $st['open'] = ['m' => $meta['id'], 't' => $t - $t0, 'abs' => $t, 'last' => $t, 'enemy' => ! $ally, 'h' => $up('Harsh Discipline'), 'd' => $up('Power of the Dark Side') ? 1 : 0, 'a' => $up('Archangel') ? 1 : 0,
                    'bolts' => [], 'heal' => 0, 'conv' => [], 'aton' => count($st['aton']), 'damp' => $st['damp'],
                    'src' => ['h' => round($t - ($st['press']['Power Word: Radiance'] ?? -99), 1), 'd' => round($t - ($st['made']['Power of the Dark Side'] ?? -99), 1), 'df' => $st['podsFrom'] ?? null, 'a' => round($t - ($st['press']['Evangelism'] ?? -99), 1)]];
                $st['boltT'] = null;
            }
            unset($st);

            continue;
        }
        if ($st['open']) {
            if ($ev === 'SPELL_DAMAGE' && $f[9] === '47666') {
                if ($st['open']['enemy']) {
                    $st['open']['bolts'][] = ['pre' => (int) $f[$n - 10], 'crit' => $f[$n - 4] === '1'];
                }
                $st['open']['last'] = $t;
                $st['boltT'] = $t;
                $st['boltDmg'] = (int) $f[$n - 11] + (int) $f[$n - 5];
            } elseif ($ev === 'SPELL_MISSED' && $f[9] === '47666') {
                if ($st['open']['enemy']) {
                    $st['open']['bolts'][] = ['pre' => 0, 'crit' => true];
                }
                $st['open']['last'] = $t;
                $st['boltT'] = $t;
                $st['boltDmg'] = 0;
            } elseif ($ev === 'SPELL_HEAL' && $name === 'Penance' && str_starts_with($dst, 'Player-')) {
                $st['open']['heal'] += (int) $f[$n - 5];
                if ($f[9] === '47750' && ! $st['open']['enemy']) {
                    $st['open']['bolts'][] = ['pre' => 0, 'crit' => true];
                    $st['open']['last'] = $t;
                }
            } elseif ($ev === 'SPELL_HEAL' && $name === 'Atonement' && str_starts_with($dst, 'Player-') && $st['boltT'] !== null && $t - $st['boltT'] <= LINK) {
                $amt = (int) $f[$n - 5];
                $st['open']['heal'] += $amt;
                if ($f[$n - 1] !== '1' && $st['boltDmg'] > 0) {
                    $st['open']['conv'][] = $amt / $st['boltDmg'] / max(0.05, 1 - $st['damp'] / 100);
                }
            }
        }
        unset($st);
    }
    foreach ($state as $g => &$st) {
        $finish($st, $discs[$g]);
    }
    unset($st, $raw);
}
file_put_contents(($argv[1] ?? sys_get_temp_dir()).'/disc-penance.json', json_encode(['rows' => $rows, 'links' => $links], JSON_UNESCAPED_UNICODE));
$med = function ($a) {
    sort($a);

    return $a ? $a[intdiv(count($a), 2)] : null;
};
echo count($rows)." enemy Penance casts; crit rate ".round(100 * $crit[0] / max(1, $crit[1]))."%\n";
print_r($links);

$rows = array_values(array_filter($rows, fn ($r) => $r['e']));
// Per priest and game: plain baseline, then each cast against it.
$plain = [];
$noDark = [];
foreach ($rows as $r) {
    $k = $r['p'].'|'.$r['m'];
    if ($r['aton'] < 1) {
        continue;
    }
    $g = $r['heal'] / $r['aton'] / max(0.05, 1 - $r['damp'] / 100);
    if (! $r['h'] && ! $r['d'] && ! $r['a']) {
        $plain[$k][] = $g;
    }
    if (! $r['d'] && $r['b2']) {
        $noDark[$k][] = $r['b2'];
    }
}
$cells = [];
$boltsByH = [];
$size = ['dark' => [], 'harsh' => [], 'arch' => []];
$conv = [0 => [], 1 => []];
foreach ($rows as $r) {
    $k = $r['p'].'|'.$r['m'];
    if ($r['bolts'] >= 2) {
        $boltsByH[min(2, $r['h'])][] = $r['bolts'];
    }
    if ($r['b2'] && count($noDark[$k] ?? []) >= 2) {
        $base = $med($noDark[$k]);
        if ($r['d'] && ! $r['a']) {
            $size['dark'][] = $r['b2'] / $base;
        }
        if (! $r['d'] && $r['h'] && ! $r['a']) {
            $size['harsh'][] = $r['b2'] / $base;
        }
        if (! $r['d'] && ! $r['h'] && $r['a']) {
            $size['arch'][] = $r['b2'] / $base;
        }
    }
    if ($r['conv'] !== null) {
        $conv[$r['a']][] = $r['conv'];
    }
    if ($r['aton'] >= 1 && count($plain[$k] ?? []) >= 2 && $r['bolts'] >= 2) {
        $g = $r['heal'] / $r['aton'] / max(0.05, 1 - $r['damp'] / 100);
        $cells[($r['h'] ? 'H' : '-').($r['d'] ? 'D' : '-').($r['a'] ? 'A' : '-')][] = $g / $med($plain[$k]);
    }
}
ksort($boltsByH);
foreach ($boltsByH as $h => $a) {
    echo "harsh charges $h: bolts median ".$med($a).' n='.count($a).'  spread '.json_encode(array_count_values($a))."\n";
}
foreach ($size as $k => $a) {
    echo "bolt 2 size with $k only: x".round($med($a) ?? 0, 3).' n='.count($a)."\n";
}
echo 'atonement per damage: no archangel '.round($med($conv[0]), 3).' n='.count($conv[0]).'; archangel '.round($med($conv[1]), 3).' n='.count($conv[1])."\n";
ksort($cells);
foreach ($cells as $k => $a) {
    echo "$k x".round($med($a), 2).' n='.count($a)."\n";
}
