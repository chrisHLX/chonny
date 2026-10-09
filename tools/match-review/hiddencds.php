<?php

// Which classified cooldowns the timeline cannot see, spec by spec (2026-10-08). The timeline
// (ArenaMomentService::readTimeline) takes a commitment only from a SPELL_CAST_SUCCESS whose spell id
// has a cooldown of 45s+ in the spell data (or a short defensive by name). A cooldown is missed when:
//  - its cast id has no such cooldown in the data (another copy of the spell holds it, or none does);
//  - it is never cast at all: a talent or proc applies the aura (Radiant Glory's Avenging Wrath from
//    Wake of Ashes), so the log holds SPELL_AURA_APPLIED and no cast.
// For every player in the archive, every cast and every self-applied aura of a spell named in the
// classification files is sorted into: seen (the timeline takes it), cast but not seen (with the cast
// ids and their cooldown in the data), or aura only (no cast of that name by them within 2s before).
// Reports each spec's spells where a share of uses is hidden.
//   php -d memory_limit=2G tools/match-review/hiddencds.php [--min=8] [--spec=70]
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$min = (int) $opt('min', '8');
$onlySpec = $opt('spec');

$arena = app(App\Http\Services\ArenaLogService::class);
$moments = app(App\Http\Services\ArenaMomentService::class);
$cooldowns = (fn () => $this->cooldowns())->call($moments);
$byName = $arena->offensiveDefensiveClassification()['byName'];
$dir = base_path('data/arena-logs/spell-classification');
foreach (['contextual-cooldowns.json', 'short-defensives.json'] as $f) {
    foreach (json_decode((string) @file_get_contents("{$dir}/{$f}"), true) ?: [] as $e) {
        $byName[$e['name']] ??= ['offensive' => false, 'defensive' => true, 'label' => $f];
    }
}
$patch = App\Models\Patch::where('is_current', true)->value('id');
$dataCd = fn (int $id) => App\Models\Spell::where('patch_id', $patch)->where('spell_id', $id)->value('cooldown_seconds');
$labels = App\Models\Specialization::with('gameClass')->whereNotNull('external_spec_id')->get()
    ->mapWithKeys(fn ($s) => [$s->external_spec_id => trim("{$s->name} {$s->gameClass?->name}")]);

// Spells that are real cooldowns somewhere: the longest cooldown any copy of the name has. A short
// rotational spell under the floor (Fire Blast, Blink) is left out of the timeline on purpose.
$longest = App\Models\Spell::where('patch_id', $patch)->whereIn('name', array_keys($byName))
    ->groupBy('name')->selectRaw('name, max(cooldown_seconds) as cd')->pluck('cd', 'name')->all();
$shortDefs = array_column(json_decode((string) @file_get_contents("{$dir}/short-defensives.json"), true) ?: [], 'name');

$stats = [];   // spec => name => [seen, castHidden, auraOnly, players => set, castIds => [id => n]]
$rounds = 0;
foreach (glob(dirname($arena->metadataPath('x')).'/*.json') as $file) {
    $meta = json_decode(file_get_contents($file), true);
    $raw = @gzdecode((string) @file_get_contents($arena->rawLogPath($meta['id'] ?? '')));
    if (! $raw) {
        continue;
    }
    $specOf = [];
    foreach ($meta['units'] ?? [] as $u) {
        if (str_starts_with($u['id'], 'Player-') && (int) ($u['spec'] ?? 0) > 0) {
            $specOf[$u['id']] = (int) $u['spec'];
        }
    }
    $rounds++;
    // Every cast and self-aura of a classified spell, by player and name.
    $casts = [];
    $auras = [];
    foreach (preg_split('/
|
|/', $raw) as $line) {
        $isCast = str_contains($line, 'SPELL_CAST_SUCCESS,');
        if (! $isCast && ! str_contains($line, 'SPELL_AURA_APPLIED,')) {
            continue;
        }
        if (! preg_match('#(\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
            continue;
        }
        $t = $m[1] * 3600 + $m[2] * 60 + $m[3] + (float) ('0.'.$m[4]);
        $f = str_getcsv(explode('  ', $line, 2)[1] ?? '');
        $src = $f[1] ?? '';
        $name = (string) ($f[10] ?? '');
        if (! isset($specOf[$src]) || ! isset($byName[$name]) || ($onlySpec && (string) $specOf[$src] !== $onlySpec)) {
            continue;
        }
        if (($longest[$name] ?? 0) < 45 && ! in_array($name, $shortDefs, true)) {
            continue;
        }
        if ($isCast) {
            $casts[$src][$name][] = [$t, (int) $f[9]];
        } elseif (($f[5] ?? '') === $src) {
            $auras[$src][$name][] = [$t, (int) $f[9]];
        }
    }
    foreach ($casts + array_fill_keys(array_keys($auras), []) as $src => $_) {
        $spec = $specOf[$src];
        foreach (array_unique(array_merge(array_keys($casts[$src] ?? []), array_keys($auras[$src] ?? []))) as $name) {
            $s = &$stats[$spec][$name];
            $s ??= ['seen' => 0, 'castHidden' => 0, 'auraOnly' => 0, 'players' => [], 'castIds' => [], 'auraIds' => []];
            $s['players'][$src] = true;
            // A press: casts of the name within 10s of each other. One press can log two ids, and a
            // channel or a storm logs a cast per tick (Divine Hymn, Doom Winds).
            $press = [];
            foreach ($casts[$src][$name] ?? [] as [$t, $id]) {
                $s['castIds'][$id] = ($s['castIds'][$id] ?? 0) + 1;
                $last = count($press) - 1;
                if ($last >= 0 && $t - $press[$last]['last'] < 10.0) {
                    $press[$last]['ids'][] = $id;
                    $press[$last]['last'] = $t;
                } else {
                    $press[] = ['t' => $t, 'last' => $t, 'ids' => [$id]];
                }
            }
            foreach ($press as $p) {
                collect($p['ids'])->contains(fn ($id) => isset($cooldowns[$id])) ? $s['seen']++ : $s['castHidden']++;
            }
            // An aura with no cast of the name within 2s either side: something else applied it.
            foreach ($auras[$src][$name] ?? [] as [$t, $id]) {
                if (! collect($press)->contains(fn ($p) => abs($p['t'] - $t) <= 2.0)) {
                    $s['auraOnly']++;
                    $s['auraIds'][$id] = ($s['auraIds'][$id] ?? 0) + 1;
                }
            }
            unset($s);
        }
    }
}

echo "rounds read: {$rounds}\n";
ksort($stats);
foreach ($stats as $spec => $names) {
    $rows = [];
    foreach ($names as $name => $s) {
        $hidden = $s['castHidden'] + $s['auraOnly'];
        $all = $hidden + $s['seen'];
        if ($all < $min || $hidden === 0 || $hidden / $all < 0.25) {
            continue;
        }
        $ids = collect($s['castIds'])->map(fn ($n, $id) => "{$id}x{$n}(cd ".($cooldowns[$id] ?? ($dataCd($id) ?? 'none')).')')->implode(' ');
        $auras = collect($s['auraIds'])->map(fn ($n, $id) => "{$id}x{$n}")->implode(' ');
        $rows[] = sprintf('  %-28s seen %4d  cast-not-seen %4d  aura-only %4d  players %3d  | casts %s%s',
            $name, $s['seen'], $s['castHidden'], $s['auraOnly'], count($s['players']), $ids ?: '-', $auras ? " | auras {$auras}" : '');
    }
    if ($rows) {
        echo "\n".($labels[$spec] ?? $spec)."\n".implode("\n", $rows)."\n";
    }
}
