<?php
// The tag audit (2026-10-04). Every feature reads the spell data's tags: the offensive/defensive
// classification (data/arena-logs/spell-classification, promoted by hand, rule 11), dr_category, and
// the timeline's floor of a 45s base cooldown (ArenaMomentService::MIN_COOLDOWN_SECONDS). A spell
// that is untagged, wrongly tagged, or below the floor is invisible to the goes, the kill read, the
// answer sheets and the comp library. This reads how each spell is ACTUALLY pressed in the archive
// and sets that beside its tags, so the data can be corrected from play.
//
// For every press by a player: was the caster in danger (35% health or lower, or lost 25%+ of their
// health in the 3s before), was the other team in a go (an offensive-tagged cast by them in the 10s
// before), was the caster's own team in one (theirs within 5s either side), was the caster locked
// out, and what did the caster's damage done and damage taken do in the 6s after against the 6s
// before.
//
// IT PROPOSES; IT NEVER WRITES A CLASSIFICATION FILE. Proposals go to
// storage/app/private/match-review/tag-proposals.json for a person to read and promote by hand.
// A spell used both ways (Vanish) is reported as both: some spells are classified by their use,
// not by their name, and that needs a rule per press, not a tag per spell.
//
//   php -d memory_limit=3G tools/match-review/tagaudit.php [--since=2026-09-20] [--min=8] [--all]
//
// A decision on a spell goes in data/arena-logs/spell-classification/reviewed.json; the audit then
// stops showing it (--all shows everything).
//
// Output names no players.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\ArenaLogService;
use App\Http\Services\ArenaMomentService;
use App\Models\Patch;
use App\Models\Spell;

const ARCHIVE = 'D:/MindCollector/arena-logs';
const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];
const FLOOR = ArenaMomentService::MIN_COOLDOWN_SECONDS;

$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$since = $opt('since', '2026-09-20');
$min = (int) $opt('min', '8');
// Spells already put to a person are hidden unless --all: each run shows only what is undecided.
$showAll = in_array('--all', $argv, true);
$reviewed = (json_decode((string) @file_get_contents(base_path('data/arena-logs/spell-classification/reviewed.json')), true) ?: [])['reviewed'] ?? [];

$cc = app(ArenaMomentService::class)->crowdControl();
$classes = app(ArenaLogService::class)->offensiveDefensiveClassification();
$patch = Patch::where('is_current', true)->value('id');
$spells = Spell::where('patch_id', $patch)->get(['spell_id', 'name', 'cooldown_seconds', 'charges', 'is_mobility', 'is_interrupt', 'is_passive'])->keyBy('spell_id');
// The longest cooldown any copy of a name carries.
$cdByName = $spells->groupBy(fn ($s) => $s->display_name)->map(fn ($g) => $g->max('cooldown_seconds'))->all();
// Spells read per press by RoundAnalysisService::classifyContextual() (version 8).
$contextual = array_fill_keys(array_column(json_decode((string) @file_get_contents(base_path('data/arena-logs/spell-classification/contextual-cooldowns.json')), true) ?: [], 'name'), true);
$tagOf = function (int $id, string $name) use ($classes, $cc, $spells, $contextual) {
    $c = $classes['bySpellId'][$id] ?? $classes['byName'][$name] ?? null;
    $s = $spells[$id] ?? null;
    $tags = [];
    if ($c && $c['offensive'] && $c['defensive']) {
        $tags[] = 'mixed';
    } elseif ($c && $c['offensive']) {
        $tags[] = 'offensive';
    } elseif ($c && $c['defensive']) {
        $tags[] = 'defensive';
    }
    if (isset($cc[$id])) {
        $tags[] = 'cc:'.$cc[$id];
    }
    if (isset($contextual[$name])) {
        $tags[] = 'contextual';
    }
    if ($s?->is_mobility) {
        $tags[] = 'mobility';
    }
    if ($s?->is_interrupt) {
        $tags[] = 'interrupt';
    }

    return $tags;
};
// A go is an offensive COOLDOWN, the timeline's own rule: offensive-tagged and at or above the floor.
// Counting every offensive-tagged spell (Penance, Fire Blast) put 95-100% of all presses "in a go".
$isOffensive = fn (int $id, string $name) => (bool) (($classes['bySpellId'][$id] ?? $classes['byName'][$name] ?? [])['offensive'] ?? false)
    && ($spells[$id]?->cooldown_seconds ?? 0) >= FLOOR;

$seconds = function (string $line): ?float {
    if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
        return null;
    }

    return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
};

$uses = [];      // spell key => list of press contexts
$atDeath = [];   // spell key => presses by a player in the 30s before their own death
$games = 0;
foreach (glob(ARCHIVE.'/metadata/*.json') as $file) {
    $m = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file)), true);
    if (($m['source'] ?? '') !== 'local-combatlog' || date('Y-m-d', intdiv($m['startTime'], 1000)) < $since || ! is_file(ARCHIVE."/raw/{$m['id']}.log.gz")) {
        continue;
    }
    $games++;
    $t0 = null;
    $team = [];
    $casts = [];
    $hp = [];        // guid => [[t, pct]]
    $dmgIn = [];     // guid => [[t, amount]]
    $dmgOut = [];    // guid => [[t, amount]]
    $locks = [];
    $open = [];
    $deaths = [];
    $owner = [];
    foreach (gzfile(ARCHIVE."/raw/{$m['id']}.log.gz") as $line) {
        $s = $seconds($line);
        if ($s === null) {
            continue;
        }
        $t0 ??= $s;
        $t = $s - $t0;
        $body = rtrim(explode('  ', $line, 2)[1] ?? '');
        $e = strtok($body, ',');
        if ($e === 'COMBATANT_INFO') {
            $p = explode(',', $body, 4);
            $team[$p[1]] = $p[2];

            continue;
        }
        if (! in_array($e, ['SPELL_CAST_SUCCESS', 'SPELL_SUMMON', 'SPELL_AURA_APPLIED', 'SPELL_AURA_REMOVED', 'UNIT_DIED', 'SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'RANGE_DAMAGE', 'SWING_DAMAGE_LANDED'], true)) {
            continue;
        }
        $f = str_getcsv($body);
        if ($e === 'SPELL_SUMMON') {
            $owner[$f[5]] = $f[1];
        } elseif ($e === 'SPELL_CAST_SUCCESS') {
            if (str_starts_with($f[1], 'Player-')) {
                $casts[] = ['t' => $t, 'who' => $f[1], 'dst' => $f[5], 'id' => (int) $f[9], 'spell' => $f[10]];
            } elseif (! str_starts_with($f[1], 'Player-') && str_starts_with($f[13] ?? '', 'Player-')) {
                $owner[$f[1]] ??= $f[13];
            }
        } elseif ($e === 'UNIT_DIED') {
            if (str_starts_with($f[5], 'Player-') && end($f) === '0') {
                $deaths[$f[5]] ??= $t;
            }
        } elseif ($e === 'SPELL_AURA_APPLIED' || $e === 'SPELL_AURA_REMOVED') {
            if (($f[12] ?? '') === 'DEBUFF' && in_array($cc[(int) $f[9]] ?? '', LOCKOUT, true) && $f[10] !== 'Garrote') {
                $k = $f[5].'|'.$f[10];
                if ($e === 'SPELL_AURA_APPLIED') {
                    $open[$k] = $t;
                } elseif (isset($open[$k])) {
                    $locks[$f[5]][] = [$open[$k], $t];
                    unset($open[$k]);
                }
            }
        } else {
            // Damage: amount and the target's health read from the end of the line (CombatantThroughputService).
            $n = count($f);
            $swing = str_starts_with($e, 'SWING');
            $amount = (int) ($f[$n - ($swing ? 10 : 11)] ?? 0);
            $base = $swing ? 9 : 12;
            if (str_starts_with($f[5], 'Player-')) {
                $dmgIn[$f[5]][] = [$t, $amount];
                if (is_numeric($f[$base + 2] ?? null) && ($f[$base + 3] ?? 0) > 0) {
                    $hp[$f[5]][] = [$t, 100 * $f[$base + 2] / $f[$base + 3]];
                }
            }
            $src = $owner[$f[1]] ?? $f[1];
            if (str_starts_with($src, 'Player-') && str_starts_with($f[5], 'Player-')) {
                $dmgOut[$src][] = [$t, $amount];
            }
        }
    }

    $sum = fn (array $rows, float $a, float $b) => array_sum(array_map(fn ($r) => $r[1], array_filter($rows, fn ($r) => $r[0] >= $a && $r[0] < $b)));
    $hpAt = function (string $g, float $t) use ($hp) {
        $last = null;
        foreach ($hp[$g] ?? [] as [$x, $p]) {
            if ($x > $t) {
                break;
            }
            $last = $p;
        }

        return $last;
    };
    $offBy = [];
    foreach ($casts as $c) {
        if ($isOffensive($c['id'], $c['spell'])) {
            $offBy[$team[$c['who']] ?? '?'][] = $c['t'];
        }
    }

    foreach ($casts as $c) {
        $side = $team[$c['who']] ?? null;
        if ($side === null) {
            continue;
        }
        $t = $c['t'];
        // Danger is read on whoever the spell went on when that was a teammate (Pain Suppression,
        // Blessing of Sacrifice), otherwise on the caster.
        $subject = str_starts_with($c['dst'], 'Player-') && ($team[$c['dst']] ?? null) === $side ? $c['dst'] : $c['who'];
        $now = $hpAt($subject, $t);
        $before = $hpAt($subject, $t - 3);
        $danger = ($now !== null && $now <= 35) || ($now !== null && $before !== null && $before - $now >= 25);
        $enemy = $side === '0' ? '1' : '0';
        $theirGo = collect($offBy[$enemy] ?? [])->contains(fn ($x) => $x <= $t && $x >= $t - 10);
        $ourGo = collect($offBy[$side] ?? [])->contains(fn ($x) => abs($x - $t) <= 5);
        $locked = collect($locks[$c['who']] ?? [])->contains(fn ($iv) => $t >= $iv[0] - 0.05 && $t <= $iv[1] + 0.05);
        $outB = $sum($dmgOut[$c['who']] ?? [], $t - 6, $t);
        $outA = $sum($dmgOut[$c['who']] ?? [], $t, $t + 6);
        $inB = $sum($dmgIn[$c['who']] ?? [], $t - 6, $t);
        $inA = $sum($dmgIn[$c['who']] ?? [], $t, $t + 6);
        $key = $c['id'].'|'.$c['spell'];
        $uses[$key][] = ['who' => $c['who'], 'danger' => $danger, 'theirGo' => $theirGo, 'ourGo' => $ourGo, 'locked' => $locked, 'outB' => $outB, 'outA' => $outA, 'inB' => $inB, 'inA' => $inA];
        if (isset($deaths[$c['who']]) && $t <= $deaths[$c['who']] && $t >= $deaths[$c['who']] - 30) {
            $atDeath[$key] = ($atDeath[$key] ?? 0) + 1;
        }
    }
}

// ---- per spell: context shares, tags, and what to propose
$rows = [];
foreach ($uses as $key => $list) {
    [$id, $name] = explode('|', $key, 2);
    $id = (int) $id;
    $n = count($list);
    $s = $spells[$id] ?? null;
    if ($n < $min || ($s && $s->is_passive)) {
        continue;
    }
    $cd = $s?->cooldown_seconds;
    $share = fn (string $k) => round(100 * count(array_filter($list, fn ($u) => $u[$k])) / $n);
    $ratio = fn (string $a, string $b) => ($x = array_sum(array_column($list, $b))) > 0 ? round(array_sum(array_column($list, $a)) / $x, 2) : null;
    $tags = $tagOf($id, $name);
    $danger = $share('danger');
    $ourGo = $share('ourGo');
    $locked = $share('locked');
    $dmgUp = $ratio('outA', 'outB');
    $inDangerTaken = ($d = array_filter($list, fn ($u) => $u['danger'])) ? (($x = array_sum(array_column($d, 'inB'))) > 0 ? round(array_sum(array_column($d, 'inA')) / $x, 2) : null) : null;

    $proposal = [];
    $tagged = $tags !== [];
    // Context per press: defensive when the target was in danger, or the other team was in a go and
    // ours was not; offensive when our own team was in a go and no one was in danger.
    $defCtx = round(100 * count(array_filter($list, fn ($u) => $u['danger'] || ($u['theirGo'] && ! $u['ourGo']))) / $n);
    $offCtx = round(100 * count(array_filter($list, fn ($u) => $u['ourGo'] && ! $u['danger'])) / $n);
    if (! $tagged && ($cd ?? 0) >= 20) {
        if ($defCtx >= 35 && $offCtx >= 30) {
            $proposal[] = "untagged, used both ways ({$defCtx}% defensive, {$offCtx}% offensive): a per-press rule, not a tag";
        } elseif ($defCtx >= 50) {
            $proposal[] = "untagged, looks DEFENSIVE ({$defCtx}% of presses)";
        } elseif ($offCtx >= 50 && ($dmgUp ?? 0) >= 1.2) {
            $proposal[] = "untagged, looks OFFENSIVE ({$offCtx}% of presses, damage x{$dmgUp} after)";
        }
        if ($locked >= 40) {
            $proposal[] = 'pressed while locked out: a CC break';
        }
    }
    $defensiveTag = in_array('defensive', $tags, true);
    // A "defensive" pressed on a short cooldown and almost never in danger is a heal on a rotation
    // (Power Word: Radiance, Wild Growth), not a cooldown held for a go.
    $routine = $defensiveTag && $cd !== null && $cd < 30 && $danger < 10;
    if ($routine) {
        $proposal[] = 'tagged defensive, but pressed like a routine heal or rotation spell: not a defensive cooldown?';
    } elseif ($defensiveTag && $danger < 10 && $ourGo >= 60) {
        $proposal[] = 'tagged defensive, pressed mostly in your own go and rarely in danger';
    }
    // Only a defensive: a cast with no cooldown (Pyroblast, Polymorph) is right to have none. And only
    // when NO copy of the name has one: the ledger looks spells up by name and takes the copy that
    // does (rule 3), so a pressed copy without a cooldown beside one with it is not a gap.
    if ($defensiveTag && $cd === null && ! ($cdByName[$name] ?? null)) {
        $proposal[] = 'a defensive with no cooldown in the spell data: the ledger and the answer sheets skip it';
    }
    if (in_array('offensive', $tags, true) && $danger >= 50) {
        $proposal[] = 'tagged offensive, pressed mostly in danger';
    }
    // Defensives count from DEFENSIVE_FLOOR since version 8; only one shorter than that is missed.
    if ($defensiveTag && ! $routine && $cd !== null && $cd < ArenaMomentService::DEFENSIVE_FLOOR) {
        $proposal[] = "a defensive under the timeline's ".ArenaMomentService::DEFENSIVE_FLOOR.'s defensive floor: missing from deaths, overlaps and the defensive counts';
    }
    if (! $tagged && ($atDeath[$key] ?? 0) >= 3 && ($cd ?? 0) >= 10) {
        $proposal[] = 'pressed before the presser died '.$atDeath[$key].' times, and invisible there';
    }

    $rows[] = ['id' => $id, 'name' => $name, 'uses' => $n, 'players' => count(array_unique(array_column($list, 'who'))), 'cd' => $cd,
        'tags' => $tags, 'danger' => $danger, 'ourGo' => $ourGo, 'theirGo' => $share('theirGo'), 'locked' => $locked,
        'dmgUp' => $dmgUp, 'takenAfterInDanger' => $inDangerTaken, 'atDeath' => $atDeath[$key] ?? 0, 'proposal' => $proposal];
}

usort($rows, fn ($a, $b) => [count($b['proposal']) > 0, $b['uses']] <=> [count($a['proposal']) > 0, $a['uses']]);
$flagged = array_values(array_filter($rows, fn ($r) => $r['proposal']));
$decided = array_filter($flagged, fn ($r) => isset($reviewed[$r['name']]));
if (! $showAll) {
    $flagged = array_values(array_filter($flagged, fn ($r) => ! isset($reviewed[$r['name']])));
}

printf("%d games since %s, %d spells pressed %d+ times, %d with something to look at%s\n\n", $games, $since, count($rows), $min, count($flagged),
    $showAll ? '' : sprintf(' (%d more already decided in reviewed.json; --all shows them)', count($decided)));
printf("%-28s %5s %4s %5s %-26s %6s %6s %6s %6s %6s %5s  %s\n", 'spell', 'uses', 'who', 'cd', 'tags', 'danger', 'ourGo', 'thrGo', 'locked', 'dmgx', 'death', 'proposal');
foreach ($flagged as $r) {
    printf("%-28s %5d %4d %5s %-26s %5d%% %5d%% %5d%% %5d%% %6s %5d  %s\n", mb_strimwidth($r['name'], 0, 28), $r['uses'], $r['players'], $r['cd'] ?? '-', implode(',', $r['tags']) ?: '(none)',
        $r['danger'], $r['ourGo'], $r['theirGo'], $r['locked'], $r['dmgUp'] ?? '-', $r['atDeath'], implode('; ', $r['proposal']));
}

$out = storage_path('app/private/match-review/tag-proposals.json');
@mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode(['generatedAt' => date('c'), 'since' => $since, 'games' => $games, 'spells' => $flagged], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nProposals for review: {$out}\nNothing was changed. Promote by hand into data/arena-logs/spell-classification/ (rule 11), then re-measure.\n";
