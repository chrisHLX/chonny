<?php
// Tool (2026-11-06; run: php -d memory_limit=3G tools/match-review/ccimmune.php): crowd control that missed a player as IMMUNE, by what was protecting them:
// diminishing returns (the third of a kind), or an aura up on them at that moment, and whose aura.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cc = array_filter(app(App\Http\Services\ArenaMomentService::class)->crowdControl(), fn ($k) => in_array($k, ['Stun', 'Silence', 'Disorient', 'Incapacitate'], true));
$archive = app(App\Http\Services\ArenaLogService::class);
$matches = App\Models\ArenaRound::where('user_id', 2)->pluck('match_id');
$sec = function (string $stamp): float {
    [, $time] = explode(' ', $stamp, 2);
    [$h, $m, $s] = explode(':', $time);

    return $h * 3600 + $m * 60 + (float) $s;
};

$why = [];       // reason => n
$byWhom = [];    // aura (own|teammate) => n
$unknown = [];   // the CC spell of IMMUNE misses with no reason found => n
$total = 0;
$lands = 0;
$atLand = [];
$atMiss = [];
foreach ($matches as $match) {
    $path = $archive->rawLogPath($match);
    if (! is_file($path)) {
        continue;
    }
    $up = [];      // dst => aura name => caster
    $landed = [];  // dst => kind => [times]
    foreach (gzfile($path) as $line) {
        $pos = strpos($line, '  ');
        if ($pos === false) {
            continue;
        }
        $f = str_getcsv(rtrim(substr($line, $pos + 2)), ',', '"', '\\');
        if (! str_starts_with($f[0], 'SPELL_') || ! isset($f[10])) {
            continue;
        }
        $t = $sec(substr($line, 0, $pos));
        [$ev, $src, , , , $dst] = $f;
        $name = $f[10];
        if ($ev === 'SPELL_AURA_APPLIED' && ($f[12] ?? '') === 'BUFF') {
            $up[$dst][$name] = $src;
        } elseif ($ev === 'SPELL_AURA_REMOVED') {
            unset($up[$dst][$name]);
        }
        $id = (int) $f[9];
        if ($ev === 'SPELL_AURA_APPLIED' && isset($cc[$id])) {
            $landed[$dst][$cc[$id]][] = $t;
            if (str_starts_with($dst, 'Player-')) {
                $lands++;
                foreach ($up[$dst] ?? [] as $aura => $caster) {
                    $atLand[$aura] = ($atLand[$aura] ?? 0) + 1;
                }
            }
        }
        if ($ev === 'SPELL_MISSED' && ($f[12] ?? '') === 'IMMUNE' && isset($cc[$id]) && str_starts_with($dst, 'Player-')) {
            $total++;
            // Diminishing returns: two of this kind already landed in the last 18s.
            $recent = array_filter($landed[$dst][$cc[$id]] ?? [], fn ($x) => $t - $x <= 18);
            if (count($recent) >= 2) {
                $why['diminishing returns'] = ($why['diminishing returns'] ?? 0) + 1;

                continue;
            }
            $auras = $up[$dst] ?? [];
            if ($auras === []) {
                $why['no buff up'] = ($why['no buff up'] ?? 0) + 1;
                $unknown[$name] = ($unknown[$name] ?? 0) + 1;

                continue;
            }
            $why['a buff was up'] = ($why['a buff was up'] ?? 0) + 1;
            foreach ($auras as $aura => $caster) {
                $atMiss[$aura] = ($atMiss[$aura] ?? 0) + 1;
                $k = $aura.($caster === $dst ? ' (own)' : ' (from a teammate)');
                $byWhom[$k] = ($byWhom[$k] ?? 0) + 1;
            }
        }
    }
}
arsort($why);
arsort($byWhom);
arsort($unknown);
echo "{$total} crowd control misses on players as IMMUNE\n";
foreach ($why as $k => $n) {
    echo "  {$n}  {$k}\n";
}
echo "\nBuffs up on the target at those misses (one miss can count several):\n";
foreach (array_slice($byWhom, 0, 40, true) as $k => $n) {
    echo "  {$n}  {$k}\n";
}
echo "\nNo buff and no diminishing returns, by spell:\n";
foreach (array_slice($unknown, 0, 12, true) as $k => $n) {
    echo "  {$n}  {$k}\n";
}

// Buffs far more often up at a miss than at a landing: what protects.
$score = [];
foreach ($atMiss as $aura => $n) {
    $m = $n / max(1, $total);
    $l = ($atLand[$aura] ?? 0) / max(1, $lands);
    if ($n >= 5 && $m > 4 * $l) {
        $score[$aura] = [$n, $atLand[$aura] ?? 0];
    }
}
uasort($score, fn ($a, $b) => $b[0] <=> $a[0]);
echo "
{$lands} crowd control landings on players. Buffs up at 4x the rate at a miss as at a landing (misses, landings):
";
foreach (array_slice($score, 0, 45, true) as $k => [$n, $l]) {
    echo "  {$n} / {$l}  {$k}
";
}
