<?php

// Whom each defensive can reach, from how it was used (2026-10-12). For every defensive named in the
// classification files, every player who pressed it in the archive: where its aura landed within 1s
// of the cast. On the caster only, on one teammate (cast on someone else), or on several at once (an
// area: Darkness, Anti-Magic Zone, Spirit Link Totem). Proposes a reach for each; it writes nothing.
// The reviewed answer goes in data/arena-logs/spell-classification/defensive-reach.json by hand
// (rule 11), with the player who knows the game checking each line.
//   php -d memory_limit=2G tools/match-review/defreach.php [--min=5] [--json]
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$min = (int) $opt('min', '5');
$asJson = in_array('--json', $argv, true);

$arena = app(App\Http\Services\ArenaLogService::class);
$moments = app(App\Http\Services\ArenaMomentService::class);
$byName = array_filter($arena->offensiveDefensiveClassification()['byName'], fn ($e) => ! empty($e['defensive']));
$dir = base_path('data/arena-logs/spell-classification');
foreach (['contextual-cooldowns.json', 'short-defensives.json'] as $f) {
    foreach (json_decode((string) @file_get_contents("{$dir}/{$f}"), true) ?: [] as $e) {
        $byName[$e['name']] ??= ['defensive' => true];
    }
}

// name => [casts, self, other, area, specs => set, games]
$stats = [];
$games = 0;
foreach (glob(dirname($arena->metadataPath('x')).'/*.json') as $file) {
    $meta = json_decode(file_get_contents($file), true);
    $raw = @gzdecode((string) @file_get_contents($arena->rawLogPath($meta['id'] ?? '')));
    if (! $raw) {
        continue;
    }
    $games++;
    // Teams from the arena's own roster (COMBATANT_INFO, rule 12), never `reaction`.
    $team = array_map(fn ($r) => $r['side'], $moments->roster($meta));
    $spec = [];
    foreach ($meta['units'] ?? [] as $u) {
        $spec[$u['id']] = (int) ($u['spec'] ?? 0);
    }
    $casts = [];   // each cast: [t, src, name, castDst]
    $auras = [];   // name => [[t, src, dst]]
    foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
        if (! str_contains($line, 'SPELL_CAST_SUCCESS') && ! str_contains($line, 'SPELL_AURA_APPLIED')) {
            continue;
        }
        $parts = explode('  ', $line, 2);
        if (count($parts) < 2) {
            continue;
        }
        $f = str_getcsv($parts[1]);
        $event = $f[0] ?? '';
        $name = $f[10] ?? '';
        if (! isset($byName[$name]) || ! isset($team[$f[1] ?? ''])) {
            continue;
        }
        $t = strtotime(substr($parts[0], 0, strrpos($parts[0], '.') ?: null)) + (float) ('0'.strrchr($parts[0], '.'));
        if ($event === 'SPELL_CAST_SUCCESS') {
            $casts[] = [$t, $f[1], $name, $f[5] ?? ''];
        } elseif ($event === 'SPELL_AURA_APPLIED' && isset($team[$f[5] ?? ''])) {
            $auras[$name][] = [$t, $f[1], $f[5]];
        }
    }
    foreach ($casts as [$t, $src, $name, $castDst]) {
        $landed = [];
        foreach ($auras[$name] ?? [] as [$at, $by, $on]) {
            if ($by === $src && $at >= $t - 0.2 && $at <= $t + 1.0 && ($team[$on] ?? 0) === ($team[$src] ?? 1)) {
                $landed[$on] = true;
            }
        }
        // The cast's own target, when it names a teammate and no aura carries the name (a heal).
        if (! $landed && isset($team[$castDst]) && $team[$castDst] === $team[$src]) {
            $landed[$castDst] = true;
        }
        $s = &$stats[$name];
        $s ??= ['casts' => 0, 'self' => 0, 'other' => 0, 'area' => 0, 'none' => 0, 'specs' => []];
        $s['casts']++;
        $s['specs'][$spec[$src] ?? 0] = true;
        $others = count(array_diff_key($landed, [$src => true]));
        match (true) {
            ! $landed => $s['none']++,
            $others >= 2 || ($others >= 1 && isset($landed[$src])) => $s['area']++,
            $others === 1 => $s['other']++,
            default => $s['self']++,
        };
        unset($s);
    }
}

// The proposal: self only when it never reached a teammate; area when it mostly hit several; else
// "self or other" when both happen, "other" when it was almost always cast on someone else.
$out = [];
ksort($stats);
foreach ($stats as $name => $s) {
    if ($s['casts'] < $min) {
        continue;
    }
    $seen = max(1, $s['casts'] - $s['none']);
    $reach = match (true) {
        $s['casts'] === $s['none'] => 'unseen',
        $s['area'] / $seen >= 0.5 => 'area',
        $s['other'] + $s['area'] === 0 => 'self',
        $s['other'] / $seen >= 0.9 => 'other',
        ($s['other'] + $s['area']) / $seen < 0.05 => 'self (rare other)',
        default => 'self or other',
    };
    $out[$name] = ['reach' => $reach, 'casts' => $s['casts'], 'self' => $s['self'], 'other' => $s['other'], 'area' => $s['area'], 'unread' => $s['none'], 'specs' => count($s['specs'])];
}

if ($asJson) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}
printf("%d games, %d defensives pressed %d+ times\n\n", $games, count($out), $min);
printf("%-34s %-18s %6s %6s %6s %6s %6s\n", 'Defensive', 'Proposed reach', 'casts', 'self', 'other', 'area', 'unread');
foreach ($out as $name => $o) {
    printf("%-34s %-18s %6d %6d %6d %6d %6d\n", mb_strimwidth($name, 0, 34), $o['reach'], $o['casts'], $o['self'], $o['other'], $o['area'], $o['unread']);
}
