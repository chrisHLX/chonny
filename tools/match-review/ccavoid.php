<?php
// Tool (2026-11-06; run: php -d memory_limit=3G tools/match-review/ccavoid.php): what the raw combat logs show when crowd control does not land on its target.
// Reads only; prints tallies.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cc = app(App\Http\Services\ArenaMomentService::class)->crowdControl();   // spell id => kind
$lock = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];
$cc = array_filter($cc, fn ($k) => in_array($k, $lock, true));
$archive = app(App\Http\Services\ArenaLogService::class);
$matches = App\Models\ArenaRound::where('user_id', 2)->pluck('match_id');

$missed = [];      // "spell | missType" => n
$onTotem = [];     // "spell -> dstName" => n
$dispelled = [];   // "by spell (friendly|enemy)" => n
$failedNear = [];  // spell the other team cast while a CC cast failed => n
$failedCasts = 0;
$startedCasts = 0;
$traps = ['cast' => 0, 'landed' => 0];
$trapNear = [];    // what the other team cast within 1s of a trap that caught nobody
$files = 0;

$sec = function (string $stamp): float {
    [, $time] = explode(' ', $stamp, 2);
    [$h, $m, $s] = explode(':', $time);

    return $h * 3600 + $m * 60 + (float) $s;
};

foreach ($matches as $match) {
    $path = $archive->rawLogPath($match);
    if (! is_file($path)) {
        continue;
    }
    $files++;
    $team = [];
    $events = [];
    foreach (gzfile($path) as $line) {
        $pos = strpos($line, '  ');
        if ($pos === false) {
            continue;
        }
        $f = str_getcsv(rtrim(substr($line, $pos + 2)), ',', '"', '\\');
        $t = $sec(substr($line, 0, $pos));
        if ($f[0] === 'COMBATANT_INFO') {
            $team[$f[1]] = $f[2];

            continue;
        }
        if (! str_starts_with($f[0], 'SPELL_') || ! isset($f[9])) {
            continue;
        }
        $events[] = [$t, $f];
    }
    $side = fn ($guid) => $team[$guid] ?? null;
    $opposed = fn ($a, $b) => $side($a) !== null && $side($b) !== null && $side($a) !== $side($b);

    // Casts started and what followed.
    $open = [];   // src => [t, spellId, name]
    foreach ($events as $i => [$t, $f]) {
        [$ev, $src, , , , $dst, $dstName] = $f;
        $id = (int) $f[9];
        $name = $f[10];
        if ($ev === 'SPELL_MISSED' && isset($cc[$id])) {
            $missed[$name.' | '.$f[12]] = ($missed[$name.' | '.$f[12]] ?? 0) + 1;
        }
        if ($ev === 'SPELL_AURA_APPLIED' && isset($cc[$id]) && ! str_starts_with($dst, 'Player-')) {
            $k = $name.' -> '.preg_replace('/-.*/', '', $dstName);
            $onTotem[$k] = ($onTotem[$k] ?? 0) + 1;
        }
        if (in_array($ev, ['SPELL_DISPEL', 'SPELL_STOLEN'], true) && isset($cc[(int) $f[12]])) {
            $k = $name.($opposed($src, $dst) ? ' (enemy)' : ' (friendly)');
            $dispelled[$k] = ($dispelled[$k] ?? 0) + 1;
        }
        if ($ev === 'SPELL_CAST_START' && isset($cc[$id]) && isset($team[$src])) {
            $open[$src] = [$t, $id, $name, $i];
            $startedCasts++;
        }
        if (($ev === 'SPELL_CAST_SUCCESS' || $ev === 'SPELL_INTERRUPT') && isset($open[$ev === 'SPELL_INTERRUPT' ? $dst : $src])) {
            unset($open[$ev === 'SPELL_INTERRUPT' ? $dst : $src]);
        }
        // A started cast with nothing after it for 2.5s failed: what did the other team press meanwhile?
        foreach ($open as $who => [$t0, , , $from]) {
            if ($t - $t0 > 2.5) {
                $failedCasts++;
                $seen = [];
                for ($j = $from + 1; $j < count($events) && $events[$j][0] <= $t0 + 2.2; $j++) {
                    $g = $events[$j][1];
                    if ($g[0] === 'SPELL_CAST_SUCCESS' && $opposed($who, $g[1]) && ! isset($seen[$g[10]])) {
                        $seen[$g[10]] = true;
                        $failedNear[$g[10]] = ($failedNear[$g[10]] ?? 0) + 1;
                    }
                }
                unset($open[$who]);
            }
        }
        // Freezing Trap: did it catch anyone within 8s?
        if ($ev === 'SPELL_CAST_SUCCESS' && $name === 'Freezing Trap' && isset($team[$src])) {
            $traps['cast']++;
            $caught = false;
            $near = [];
            for ($j = $i + 1; $j < count($events) && $events[$j][0] <= $t + 8; $j++) {
                $g = $events[$j][1];
                if ($g[0] === 'SPELL_AURA_APPLIED' && $g[10] === 'Freezing Trap') {
                    $caught = true;
                    break;
                }
                if ($g[0] === 'SPELL_CAST_SUCCESS' && $events[$j][0] <= $t + 1.5 && $opposed($src, $g[1])) {
                    $near[$g[10]] = true;
                }
            }
            if ($caught) {
                $traps['landed']++;
            } else {
                foreach (array_keys($near) as $n) {
                    $trapNear[$n] = ($trapNear[$n] ?? 0) + 1;
                }
            }
        }
    }
}

$top = function (array $a, int $n) {
    arsort($a);

    return array_slice($a, 0, $n, true);
};
echo "{$files} raw logs\n\n== Crowd control that MISSED its target (spell | why) ==\n";
foreach ($top($missed, 25) as $k => $n) {
    echo "  {$n}  {$k}\n";
}
echo "\n== Crowd control that landed on something not a player ==\n";
foreach ($top($onTotem, 15) as $k => $n) {
    echo "  {$n}  {$k}\n";
}
echo "\n== Crowd control dispelled or stolen, by what ==\n";
foreach ($top($dispelled, 20) as $k => $n) {
    echo "  {$n}  {$k}\n";
}
echo "\n== {$failedCasts} of {$startedCasts} crowd control casts started and never finished (not kicked); what the other team pressed meanwhile ==\n";
foreach ($top($failedNear, 25) as $k => $n) {
    echo "  {$n}  {$k}\n";
}
echo "\n== Freezing Traps: {$traps['cast']} laid, {$traps['landed']} caught someone. Pressed by the other side within 1.5s of one that caught nobody ==\n";
foreach ($top($trapNear, 15) as $k => $n) {
    echo "  {$n}  {$k}\n";
}
