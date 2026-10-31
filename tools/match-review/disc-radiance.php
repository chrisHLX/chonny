<?php
// Evangelism -> Radiance -> Harsh Discipline: how much of Harsh Discipline comes through Evangelism,
// and whether those Radiances really were instant. Read-only.
$dir = 'D:/MindCollector/arena-logs';

function secs(string $ts): float
{
    static $cache = [];
    [$whole, $frac] = explode('.', $ts, 2);
    $cache[$whole] ??= strtotime($whole);

    return $cache[$whole] + ((int) substr($frac, 0, 3)) / 1000;
}

$c = ['evPress' => 0, 'rad' => 0, 'radFree' => 0, 'radFreeNoStart' => 0, 'radHard' => 0, 'radHardNoStart' => 0, 'radAuto' => 0, 'harsh' => 0, 'harshAuto' => 0, 'harshFree' => 0, 'harshHard' => 0, 'evThenPenance' => 0];
$radsPerEv = [];
foreach (glob($dir.'/metadata/*.json') as $mf) {
    $meta = json_decode(file_get_contents($mf), true);
    $ids = [];
    foreach ($meta['units'] ?? [] as $u) {
        if ((int) ($u['spec'] ?? 0) === 256 && str_starts_with($u['id'] ?? '', 'Player-')) {
            $ids[$u['id']] = true;
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
    $st = [];
    foreach ($ids as $g => $_) {
        $st[$g] = ['evT' => -99, 'free' => false, 'start' => -99, 'radT' => -99, 'radKind' => null, 'evGone' => -99, 'harshPending' => [], 'evOpen' => false, 'count' => 0];
    }
    foreach (explode("\n", $raw) as $line) {
        $p = strpos($line, '  ');
        if ($p === false || ! str_contains($line, 'SPELL_')) {
            continue;
        }
        if (! str_contains($line, '"Power Word: Radiance"') && ! str_contains($line, '"Evangelism"') && ! str_contains($line, '"Harsh Discipline"') && ! str_contains($line, '"Penance"')) {
            continue;
        }
        $f = str_getcsv(rtrim(substr($line, $p + 2)), ',', '"', '');
        $ev = $f[0];
        $src = $f[1] ?? '';
        $dst = $f[5] ?? '';
        $name = $f[10] ?? '';
        $t = secs(substr($line, 0, $p));
        if ($name === 'Evangelism' && isset($st[$dst]) && str_starts_with($ev, 'SPELL_AURA_') && in_array('BUFF', array_slice($f, -2), true)) {
            if ($ev === 'SPELL_AURA_APPLIED') {
                $st[$dst]['free'] = true;
            } elseif ($ev === 'SPELL_AURA_REMOVED') {
                $st[$dst]['free'] = false;
                $st[$dst]['evGone'] = $t;
            }
        }
        if (! isset($st[$src]) && ! ($name === 'Harsh Discipline' && isset($st[$dst]))) {
            continue;
        }
        if ($name === 'Harsh Discipline' && isset($st[$dst]) && ($ev === 'SPELL_AURA_APPLIED' || $ev === 'SPELL_AURA_APPLIED_DOSE')) {
            $s = &$st[$dst];
            $c['harsh']++;
            if (abs($t - $s['evT']) <= 0.5) {
                $c['harshAuto']++;
            } elseif ($s['free'] || $t - $s['evGone'] <= 0.3) {
                $c['harshFree']++;
            } else {
                $c['harshHard']++;
            }
            unset($s);

            continue;
        }
        if (! isset($st[$src])) {
            continue;
        }
        $s = &$st[$src];
        if ($ev === 'SPELL_CAST_START' && $name === 'Power Word: Radiance') {
            $s['start'] = $t;
        } elseif ($ev === 'SPELL_CAST_SUCCESS' && $name === 'Evangelism') {
            $c['evPress']++;
            $s['evT'] = $t;
            $s['evOpen'] = true;
        } elseif ($ev === 'SPELL_CAST_SUCCESS' && $name === 'Penance' && $s['evOpen']) {
            if ($t - $s['evT'] <= 8) {
                $c['evThenPenance']++;
            }
            $s['evOpen'] = false;
        } elseif ($ev === 'SPELL_CAST_SUCCESS' && $name === 'Power Word: Radiance') {
            $c['rad']++;
            $hadStart = $t - $s['start'] <= 3;
            if (abs($t - $s['evT']) <= 0.2) {
                $c['radAuto']++;
            } elseif ($s['free'] || $t - $s['evGone'] <= 0.3) {
                $c['radFree']++;
                $c['radFreeNoStart'] += $hadStart ? 0 : 1;
            } else {
                $c['radHard']++;
                $c['radHardNoStart'] += $hadStart ? 0 : 1;
            }
        }
        unset($s);
    }
    unset($raw);
}
print_r($c);
