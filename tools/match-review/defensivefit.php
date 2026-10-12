<?php

// A draft of data/arena-logs/spell-classification/defensive-fit.json (2026-10-12): for every
// defensive and trinket that appears on an answer sheet ("what your team had for their go"), whom it
// can reach and what damage it covers, each with its evidence.
//  - reach: from how it was used across the archive (defreach.php --json, saved to
//    storage/app/defreach.json): self, other, self or other, area.
//  - covers: from the spell data's effects (spell_effects.affected_schools on School Immunity, Absorb
//    Damage, Modify Damage Taken%, and parry or dodge for physical): all, physical, magic, or none
//    found (a heal, a movement spell, a crowd-control break).
// It writes a draft for review, never the reviewed file (rule 11).
//   php -d memory_limit=2G tools/match-review/defensivefit.php [--out=storage/app/defensive-fit-draft.json]
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$outPath = base_path($opt('out', 'storage/app/defensive-fit-draft.json'));
$reachEvidence = json_decode((string) @file_get_contents(storage_path('app/defreach.json')), true) ?: [];

// Every defensive and trinket on a stored answer sheet, with how often it was listed.
$seen = [];
App\Models\ArenaRound::query()->select(['id', 'payload'])->chunkById(50, function ($rows) use (&$seen) {
    foreach ($rows as $r) {
        foreach ($r->payload['analysis']['deaths'] ?? [] as $d) {
            foreach ($d['answers']['rows'] ?? [] as $row) {
                if (in_array($row['kind'], ['defensive', 'trinket'], true)) {
                    $seen[$row['spell']] = ($seen[$row['spell']] ?? 0) + 1;
                }
            }
        }
    }
});
arsort($seen);

$patch = App\Models\Patch::where('is_current', true)->value('id');
$magic = ['Arcane', 'Fire', 'Frost', 'Holy', 'Nature', 'Shadow'];
$out = [];
foreach ($seen as $name => $listed) {
    // Every pressable copy of the name: one visible ability is often several spell ids (rule 3).
    $copies = App\Models\Spell::where('patch_id', $patch)->where('name', $name)->where('is_passive', false)->pluck('id');
    $effects = App\Models\SpellEffect::whereIn('spell_id', $copies)->get(['type', 'base_value', 'affected_schools']);
    $schools = [];
    $how = [];
    foreach ($effects as $e) {
        $type = (string) $e->type;
        $kind = match (true) {
            str_starts_with($type, 'School Immunity') => 'immunity',
            str_starts_with($type, 'Absorb Damage') => 'absorb',
            str_starts_with($type, 'Modify Damage Taken%') && (int) $e->base_value < 0 => 'reduction',
            str_starts_with($type, 'Modify Parry%'), str_starts_with($type, 'Modify Dodge%') => 'avoidance',
            str_starts_with($type, 'Spell Reflection') => 'reflect',
            str_contains($type, 'Heal') && ! str_contains($type, 'Healing Received') => 'heal',
            default => null,
        };
        if (! $kind) {
            continue;
        }
        $how[$kind] = true;
        $list = $kind === 'avoidance' ? ['Physical'] : ($kind === 'reflect' ? $magic : array_map('trim', explode(',', (string) $e->affected_schools)));
        foreach ($list as $s) {
            if ($s === 'All') {
                $schools = array_fill_keys(array_merge(['Physical'], $magic), true) + $schools;
            } elseif ($s !== '') {
                $schools[$s] = true;
            }
        }
    }
    $phys = isset($schools['Physical']);
    $mag = count(array_intersect_key($schools, array_flip($magic))) > 0;
    $covers = match (true) {
        $phys && $mag => 'all',
        $phys => 'physical',
        $mag => 'magic',
        isset($how['heal']) => 'heal',
        default => 'none found',
    };
    $r = $reachEvidence[$name] ?? null;
    $out[$name] = [
        'reach' => $r['reach'] ?? 'no evidence',
        'covers' => $covers,
        'how' => implode(', ', array_keys($how)) ?: '-',
        'evidence' => $r ? sprintf('%d casts: self %d, other %d, area %d, unread %d', $r['casts'], $r['self'], $r['other'], $r['area'], $r['unread']) : 'never seen cast in the archive',
        'listed' => $listed,
    ];
}

file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
printf("%d defensives and trinkets on answer sheets -> %s\n\n", count($out), $outPath);
printf("%-30s %-17s %-11s %-28s %s\n", 'Defensive', 'Reach', 'Covers', 'How', 'Evidence');
foreach ($out as $name => $o) {
    printf("%-30s %-17s %-11s %-28s %s\n", mb_strimwidth($name, 0, 30), $o['reach'], $o['covers'], mb_strimwidth($o['how'], 0, 28), $o['evidence']);
}
