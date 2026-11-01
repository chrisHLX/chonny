<?php

// Cooldowns against how buttons are really pressed (2026-11-01, docs/plan-overlay.md, "Keeping
// cooldowns right"). Every press in a stored round is direct evidence of what a button's cooldown can
// be, and each round keeps that player's talents, so the data is checked per build. Three questions:
//
//   1. Pressed, but missing from the player's stored kit. The kit was built from the spec's default
//      profile, so a button outside the default build is invisible to whatever reads the kit.
//   2. Pressed sooner than the cooldown allows. The data says it is longer than it is, or something
//      resets it (a proc, a spend-driven reduction, a charge the data does not know).
//   3. Never pressed as fast as the cooldown allows, over enough presses. The data may be too short.
//
// PROPOSES ONLY, like tagaudit.php and hiddencds.php. An early press is often a real reset, and a slow
// one is often play. Reasons and fixes are a person's call: where each kind goes is in the doc.
//
//   php -d memory_limit=3G tools/match-review/cdaudit.php
//   --min=20      shortest cooldown looked at, in seconds
//   --spec=103    one spec, by Blizzard id
//   --user=2      only this account's rounds (default: every stored round)
//   --n=30        rows printed per list
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\ArenaLogService;
use App\Http\Services\CooldownLedgerService;
use App\Models\ArenaRound;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;

$args = array_slice($argv, 1);
$opt = fn (string $k, ?string $d) => array_reduce($args, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$min = (float) $opt('min', '20');
$only = $opt('spec', null);
$user = $opt('user', null);
$top = (int) $opt('n', '30');

// Two casts this close are one press: channel ticks, and a press logged twice.
const SAME_PRESS = 1.5;

$arena = app(ArenaLogService::class);
$ledger = app(CooldownLedgerService::class);
$patch = Patch::where('is_current', true)->value('id');
$specs = Specialization::with('gameClass')->get()->keyBy('external_spec_id');
$classes = $arena->offensiveDefensiveClassification()['byName'] ?? [];

/** What kind of button a name is, or null for one nobody tracks. Cached per name. */
$kinds = [];
$kindOf = function (string $name) use (&$kinds, $classes, $patch): ?string {
    if (array_key_exists($name, $kinds)) {
        return $kinds[$name];
    }
    $tags = $classes[$name] ?? $classes[strtolower($name)] ?? [];
    if ($tags['offensive'] ?? false) {
        return $kinds[$name] = 'offensive';
    }
    if ($tags['defensive'] ?? false) {
        return $kinds[$name] = 'defensive';
    }
    $control = Spell::where('patch_id', $patch)->whereNotNull('dr_category')
        ->where(fn ($q) => $q->where('name', $name)->orWhere('name', 'like', $name.' (desc=%'))->exists();

    return $kinds[$name] = $control ? 'control' : null;
};

$rows = [];
$rounds = 0;
$query = ArenaRound::query();
if ($user) {
    $query->where('user_id', (int) $user);
}
$query->chunkById(25, function ($batch) use (&$rows, &$rounds, $specs, $ledger, $kindOf, $only, $min) {
    foreach ($batch as $r) {
        $a = $r->payload['analysis'] ?? [];
        if (($a['version'] ?? 0) < 16) {
            continue;
        }
        $rounds++;
        foreach ($a['players'] ?? [] as $p) {
            $ext = (int) ($p['specExternalId'] ?? 0);
            $spec = $specs[$ext] ?? null;
            if (! $spec || ($only && (string) $ext !== $only)) {
                continue;
            }
            $kit = collect($p['kit'] ?? [])->keyBy('name');
            $combatant = $r->payload['combatants'][$p['guid']] ?? null;
            $build = null;

            $presses = [];
            foreach ($a['timeline']['casts'][$p['guid']] ?? [] as $c) {
                $list = $presses[$c[1]] ?? [];
                if (! $list || $c[0] - end($list) > SAME_PRESS) {
                    $presses[$c[1]][] = (float) $c[0];
                }
            }

            foreach ($presses as $name => $times) {
                $entry = $kit[$name] ?? null;
                $kind = $entry['kind'] ?? $kindOf($name);
                if (! $kind) {
                    continue;
                }
                if ($entry) {
                    $cd = (float) $entry['cd'];
                    $charges = (int) ($entry['charges'] ?? 1);
                } else {
                    if (! $combatant) {
                        continue;
                    }
                    $build ??= $ledger->build($combatant, $spec);
                    $found = $ledger->cooldown($name, $spec, $build);
                    if (! $found) {
                        continue;
                    }
                    [$cd, $charges] = [$found['cd'], $found['charges']];
                }
                if ($cd < $min) {
                    continue;
                }
                $key = $ext.'|'.$name.'|'.$cd.'|'.$charges;
                $row = $rows[$key] ??= ['spec' => "{$spec->name} {$spec->gameClass?->name}", 'name' => $name, 'kind' => $kind, 'cd' => $cd, 'charges' => $charges, 'rounds' => 0, 'missing' => 0, 'presses' => 0, 'spans' => 0, 'gaps' => [], 'players' => []];
                $row['rounds']++;
                $row['spans'] += collect($a['timeline']['control'] ?? [])->filter(fn ($c) => $c[1] === $p['guid'] && $c[2] === $name)->count();
                $row['missing'] += $entry ? 0 : 1;
                $row['presses'] += count($times);
                $row['players'][$p['name']] = true;
                for ($i = $charges; $i < count($times); $i++) {
                    $row['gaps'][] = round($times[$i] - $times[$i - $charges], 1);
                }
                $rows[$key] = $row;
            }
        }
    }
});

$note = fn (string $name) => Spell::where(fn ($q) => $q->where('name', $name)->orWhere('name', 'like', $name.' (desc=%'))->whereNotNull('cooldown_scaling_note')->exists();
$pct = fn (array $v, float $p) => $v ? $v[(int) floor((count($v) - 1) * $p)] : null;

echo "{$rounds} stored rounds, ".count($rows)." button and cooldown pairs at {$min}s or longer\n";

$missing = array_filter($rows, fn ($r) => $r['missing'] > 0);
uasort($missing, fn ($x, $y) => $y['presses'] <=> $x['presses']);
$offensive = count(array_filter($missing, fn ($r) => $r['kind'] === 'offensive'));
printf("\n1. Pressed, but not in the stored kit: %d pairs, %d presses, %d of the pairs offensive\n", count($missing), array_sum(array_column($missing, 'presses')), $offensive);
printf("  %-26s %-34s %-10s %6s %7s %8s %8s\n", 'spec', 'button', 'kind', 'cd', 'presses', 'rounds', 'players');
foreach (array_slice($missing, 0, $top) as $r) {
    printf("  %-26s %-34s %-10s %5.0fs %7d %8d %8d\n", $r['spec'], mb_substr($r['name'], 0, 33), $r['kind'], $r['cd'], $r['presses'], $r['missing'], count($r['players']));
}

$fast = [];
$slow = [];
foreach ($rows as $key => $r) {
    $gaps = $r['gaps'];
    sort($gaps);
    if (count($gaps) < 8) {
        continue;
    }
    $allow = max(2.0, $r['cd'] * 0.05);
    $early = count(array_filter($gaps, fn ($g) => $g < $r['cd'] - $allow));
    $r['n'] = count($gaps);
    $r['early'] = $early;
    $r['low'] = $gaps[0];
    $r['median'] = $pct($gaps, .5);
    // Early gaps stacked on one value read as a fixed cooldown the data has wrong: a talent it did not
    // apply, or another cast's clock. Early gaps spread out read as haste, resets or spending.
    $atFloor = count(array_filter($gaps, fn ($g) => $g <= $gaps[0] + 1.5));
    $r['floor'] = $early > 0 && $atFloor >= 3 && $atFloor / $early >= .25;
    if ($early / count($gaps) >= .1) {
        $fast[$key] = $r;
    } elseif (count($gaps) >= 15 && $gaps[0] > $r['cd'] * 1.2) {
        $slow[$key] = $r;
    }
}
uasort($fast, fn ($x, $y) => $y['early'] <=> $x['early']);
uasort($slow, fn ($x, $y) => $y['n'] <=> $x['n']);

printf("\n2. Pressed sooner than the cooldown allows, in 10%% or more of gaps: %d pairs\n", count($fast));
$floors = array_filter($fast, fn ($r) => $r['floor']);
printf("  %d have a fixed floor (early gaps stacked on one value); the other %d are spread (haste, resets, spending)\n", count($floors), count($fast) - count($floors));
printf("  %-26s %-34s %6s %3s %7s %7s %7s %7s  %-7s %s\n", 'spec', 'button', 'cd', 'ch', 'gaps', 'early', 'lowest', 'median', 'shape', 'note on file');
foreach (array_slice($floors + $fast, 0, $top) as $r) {
    printf("  %-26s %-34s %5.0fs %3d %7d %7d %6.1fs %6.1fs  %-7s %s\n", $r['spec'], mb_substr($r['name'], 0, 33), $r['cd'], $r['charges'], $r['n'], $r['early'], $r['low'], $r['median'], $r['floor'] ? 'floor' : 'spread', $note($r['name']) ? 'yes' : '');
}

printf("\n3. Never pressed as fast as the cooldown, over 15+ gaps: %d pairs\n", count($slow));
printf("  %-26s %-34s %6s %7s %7s %7s\n", 'spec', 'button', 'cd', 'gaps', 'lowest', 'median');
foreach (array_slice($slow, 0, $top) as $r) {
    printf("  %-26s %-34s %5.0fs %7d %6.1fs %6.1fs\n", $r['spec'], mb_substr($r['name'], 0, 33), $r['cd'], $r['n'], $r['low'], $r['median']);
}

// A control button pressed often that hardly ever shows as control: the aura is under another id, or
// the spell is not control at all. Whatever reads timeline.control cannot see these landing.
$blind = array_filter($rows, fn ($r) => $r['kind'] === 'control' && $r['presses'] >= 10 && $r['spans'] / $r['presses'] < .2);
uasort($blind, fn ($x, $y) => $y['presses'] <=> $x['presses']);
printf("\n4. Pressed as control, but under 1 in 5 presses shows as control in the timeline: %d pairs\n", count($blind));
printf("  %-26s %-34s %6s %7s %7s\n", 'spec', 'button', 'cd', 'presses', 'spans');
foreach (array_slice($blind, 0, $top) as $r) {
    printf("  %-26s %-34s %5.0fs %7d %7d\n", $r['spec'], mb_substr($r['name'], 0, 33), $r['cd'], $r['presses'], $r['spans']);
}

$agree = array_filter($rows, fn ($r) => count($r['gaps']) >= 8);
printf("\nChecked: %d pairs with 8+ gaps. %d read early, %d read slow, %d agree with the data.\n", count($agree), count($fast), count($slow), count($agree) - count($fast) - count($slow));
