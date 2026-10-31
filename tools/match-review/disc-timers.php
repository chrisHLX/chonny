<?php
// How long Discs hold each empowering buff, and how often it runs out unspent, by rating and hero
// tree. Spent means the paying press landed within 0.3s of the buff going. Read-only.
$dir = 'D:/MindCollector/arena-logs';
$people = json_decode(file_get_contents($argv[1]), true);   // rated players: name, rating, talents
$rating = [];
$hero = [];
foreach ($people as $r) {
    $rating[$r['name']] = $r['rating'];
    $names = array_column($r['talents'], 'name');
    $hero[$r['name']] = in_array('Entropic Rift', $names, true) ? 'Voidweaver' : (in_array('Preventive Measures', $names, true) ? 'Oracle' : 'other');
}
$band = fn ($n) => ($rating[$n] ?? 0) >= 2100 ? '2100+' : (($rating[$n] ?? 0) >= 1800 ? '1800-2099' : 'under 1800');
const BUFFS = ['Harsh Discipline' => 'Penance', 'Power of the Dark Side' => 'Penance', 'Weal and Woe' => 'Power Word: Shield'];

function secs(string $ts): float
{
    static $cache = [];
    [$whole, $frac] = explode('.', $ts, 2);
    $cache[$whole] ??= strtotime($whole);

    return $cache[$whole] + ((int) substr($frac, 0, 3)) / 1000;
}

$out = [];
$heroCount = [];
foreach (glob($dir.'/metadata/*.json') as $mf) {
    $meta = json_decode(file_get_contents($mf), true);
    $ids = [];
    foreach ($meta['units'] ?? [] as $u) {
        if ((int) ($u['spec'] ?? 0) === 256 && str_starts_with($u['id'] ?? '', 'Player-')) {
            $ids[$u['id']] = $u['name'];
        }
    }
    $rawPath = $dir.'/raw/'.$meta['id'].'.log.gz';
    if (! $ids || ! is_file($rawPath)) {
        continue;
    }
    $raw = @gzdecode(file_get_contents($rawPath));
    if ($raw === false) {
        continue;
    }
    $press = [];
    $gone = [];
    $made = [];
    $end = 0;
    foreach (explode("\n", $raw) as $line) {
        $p = strpos($line, '  ');
        if ($p === false) {
            continue;
        }
        $t = secs(substr($line, 0, $p));
        $end = max($end, $t);
        if (! str_contains($line, 'SPELL_')) {
            continue;
        }
        $isCast = str_contains($line, 'SPELL_CAST_SUCCESS');
        $isAura = str_contains($line, 'SPELL_AURA_');
        if (! $isCast && ! $isAura) {
            continue;
        }
        $f = str_getcsv(rtrim(substr($line, $p + 2)), ',', '"', '');
        $name = $f[10] ?? '';
        if ($isCast && isset($ids[$f[1]]) && in_array($name, ['Penance', 'Power Word: Shield', 'Void Shield'], true)) {
            $press[$f[1]][$name === 'Void Shield' ? 'Power Word: Shield' : $name][] = $t;
        } elseif ($isAura && isset(BUFFS[$name]) && isset($ids[$f[5]])) {
            if ($f[0] === 'SPELL_AURA_APPLIED' || $f[0] === 'SPELL_AURA_APPLIED_DOSE' || $f[0] === 'SPELL_AURA_REFRESH') {
                $made[$f[5]][$name] = $t;
            } elseif ($f[0] === 'SPELL_AURA_REMOVED' || ($f[0] === 'SPELL_AURA_REMOVED_DOSE' && $name === 'Harsh Discipline')) {
                $gone[$f[5]][] = [$name, $t, $t - ($made[$f[5]][$name] ?? $t)];
            }
        }
    }
    foreach ($gone as $g => $list) {
        $n = $ids[$g];
        foreach ($list as &$row) {
            $row[3] = $row[2];
        }
        unset($row);
        foreach ($list as [$b, $t, $held]) {
            if ($end - $t < 2) {
                continue;   // the round ended, or the player died
            }
            // A channelled press keeps the buff until the channel ends, so look back across it.
            $spent = false;
            $back = BUFFS[$b] === 'Penance' ? 6.0 : 0.3;
            foreach ($press[$g][BUFFS[$b]] ?? [] as $pt) {
                if ($pt >= $t - $back && $pt <= $t + 0.3 && $pt >= $t - $held - 0.3) {
                    $spent = true;
                    $held = max(0, $held - ($t - $pt));
                    break;
                }
            }
            if (! isset($rating[$n])) {
                continue;
            }
            foreach ([$band($n), 'hero:'.$hero[$n]] as $k) {
                $out[$b][$k]['n'] = ($out[$b][$k]['n'] ?? 0) + 1;
                $out[$b][$k]['lost'] = ($out[$b][$k]['lost'] ?? 0) + ($spent ? 0 : 1);
                if ($spent) {
                    $out[$b][$k]['held'][] = $held;
                } else {
                    $out[$b][$k]['ran'][] = $held;
                }
            }
        }
    }
    unset($raw);
}
$med = function ($a) {
    sort($a);

    return $a ? round($a[intdiv(count($a), 2)], 1) : null;
};
foreach ($out as $b => $set) {
    echo "\n$b\n";
    ksort($set);
    foreach ($set as $k => $v) {
        printf("  %-18s %5d  ran out %3d%%   held when spent %5ss   length when it ran out %5ss\n", $k, $v['n'], 100 * $v['lost'] / $v['n'], $med($v['held'] ?? []), $med($v['ran'] ?? []));
    }
}
echo "\nDiscs by hero tree: ".json_encode(array_count_values($hero))."\n";
$trees = [];
foreach ($people as $r) {
    foreach ($r['talents'] as $t) {
        $trees[$t['name']][$t['treeType'] ?? '?'] = ($trees[$t['name']][$t['treeType'] ?? '?'] ?? 0) + 1;
    }
}
foreach (['Harsh Discipline', 'Power of the Dark Side', 'Dark Indulgence', 'Evangelism', 'Archangel', 'Holy Ray', 'Castigation', 'Weal and Woe', 'Prompt Prognosis', 'Twinsight', 'Preventive Measures', "Prophet's Insight", 'Assured Safety', 'Piety', 'Unfolding Vision', 'Master the Darkness', 'Entropic Rift', 'Voidheart', 'Void Infusion', 'Overwhelming Shadows'] as $n) {
    echo $n.' '.json_encode($trees[$n] ?? null)."\n";
}
