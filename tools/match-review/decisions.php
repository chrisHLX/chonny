<?php

// Situation, choice, outcome: what the defending side did against a go, judged only against goes
// that met the same situation (2026-10-10). The step past comparing players: "in goes like this one,
// sides that did X died Y% of the time".
//
// One row per enemy go, from the defending side, out of storage/app/population (wow:population):
//  SITUATION (fixed before the defenders choose):
//    bigDown   their big answers (90s+) already on cooldown as the go started (the cooldown ledger)
//    pressure  heavy when two or more attackers pressed offensive cooldowns, or the defenders' healer
//              was locked out in the go's peak. Only the ATTACKER's inputs: peak damage is measured
//              after mitigation, so it depends on the choice being judged and would bias the read.
//    targetHp  the go's target's health as it started: fresh (80%+, or no damage yet) or hurt
//    gear      the attackers' median item level against the defenders': behind, even (within 5), ahead
//  CHOICE (Gladiator's Medallion is set apart: it answers control, not damage):
//    none      no answer pressed inside the go, though one was pressable (back, owner not locked out)
//    none: nothing pressable   every answer down, or every owner of one locked out as it started
//    early     the first answer went out before its target was in danger (35% health or 5s to live)
//    late      the first answer went out only once its target was in danger
//    and how many answers the go drew (1, or 2+).
//  OUTCOME:
//    died      a defender died in the go or within 30s of its end (killLater)
//    next      at the attackers' next go: two or more big answers down, and whether it killed
//    won       the defending side won the round
//
// Goes starting after the round's first death are left out: defensive rows stop there, so such a go
// would read as "pressed nothing". A correlation over goes, never a cause: an early answer is often
// pressed BECAUSE the defender saw the go coming, which the log cannot see. Cells under MIN goes
// state no rate.
//
//   php tools/match-review/decisions.php                       # every bracket
//   php tools/match-review/decisions.php --bracket=3v3         # bracket name contains this
//   php tools/match-review/decisions.php --spell="Pain Suppression"   # that answer, wherever it was back: pressed early, late, held, or its owner locked out
//   php tools/match-review/decisions.php --spell="Pain Suppression" --guid=Player-1234-ABCDEF   # one player's own (hashed as wow:population does)
//   php tools/match-review/decisions.php --since=2026-08-01
// Needs wow:population at RoundAnalysisService VERSION 12 or later (the per-answer ledger).

const MIN = 20;

$opts = getopt('', ['bracket:', 'spell:', 'since:', 'guid:']);
$only = isset($opts['guid']) ? substr(sha1('mc-pop:'.$opts['guid']), 0, 12) : null;
$dir = __DIR__.'/../../storage/app/population';
$medallion = "Gladiator's Medallion";

$rows = [];
$skipped = ['noCover' => 0, 'afterDeath' => 0, 'oldVersion' => 0];
$brackets = [];

foreach (glob($dir.'/*.json') as $file) {
    $g = json_decode(file_get_contents($file), true);
    if (! is_array($g) || ($g['version'] ?? 0) < 12) {
        $skipped['oldVersion']++;

        continue;
    }
    $brackets[$g['bracket'] ?? '?'] = ($brackets[$g['bracket'] ?? '?'] ?? 0) + 1;
    if (isset($opts['bracket']) && stripos((string) $g['bracket'], $opts['bracket']) === false) {
        continue;
    }
    if (isset($opts['since']) && ($g['playedAt'] ?? '') < $opts['since']) {
        continue;
    }

    $won = [];
    $ilvls = ['us' => [], 'them' => []];
    foreach ($g['players'] as $p) {
        $won[$p['side']] = $p['won'];
        if ($p['ilvl'] ?? null) {
            $ilvls[$p['side']][] = $p['ilvl'];
        }
    }
    $firstDeath = $g['firstDeath'] ?? INF;

    $bySide = ['us' => [], 'them' => []];
    foreach ($g['goes'] as $go) {
        $bySide[$go['side']][] = $go;
    }

    foreach ($bySide as $side => $goes) {
        usort($goes, fn ($a, $b) => $a['from'] <=> $b['from']);
        $defending = $side === 'us' ? 'them' : 'us';
        foreach ($goes as $i => $go) {
            if ($go['from'] >= $firstDeath) {
                $skipped['afterDeath']++;

                continue;
            }
            if (! is_array($go['cover'] ?? null)) {
                $skipped['noCover']++;

                continue;
            }

            $answers = array_values(array_filter($go['answers'], fn ($x) => $x['spell'] !== $medallion));
            $first = $answers[0] ?? null;
            $next = $goes[$i + 1] ?? null;
            $nextValid = $next && $next['from'] < $firstDeath && is_array($next['cover'] ?? null);

            $ledger = $go['cover']['answers'] ?? [];
            $pressable = array_filter($ledger, fn ($x) => $x['up'] && ! ($x['locked'] ?? false));

            // The named answer, read only where the ledger says it was back as the go started.
            $spellChoice = null;
            if (isset($opts['spell'])) {
                $mine = collect_first($ledger, fn ($x) => $x['spell'] === $opts['spell'] && $x['up'] && ($only === null || $x['who'] === $only));
                if ($mine) {
                    $press = collect_first($go['answers'], fn ($x) => $x['spell'] === $opts['spell'] && $x['who'] === $mine['who']);
                    $spellChoice = $press ? ($press['danger'] ? 'pressed late' : 'pressed early')
                        : (($mine['locked'] ?? false) ? 'owner locked out' : 'held');
                }
            }
            // --guid: that player's own answer with --spell, otherwise every go they defended.
            if ($only !== null && (isset($opts['spell']) ? $spellChoice === null : ($g['players'][$only]['side'] ?? null) !== $defending)) {
                continue;
            }

            $gap = $ilvls[$side] && $ilvls[$defending] ? median($ilvls[$side]) - median($ilvls[$defending]) : null;

            $rows[] = [
                'bigDown' => min(2, (int) $go['cover']['bigDown']),
                'pressure' => ($go['pressers'] >= 2 || $go['healerLocked'] > 0) ? 'heavy' : 'light',
                'targetHp' => ($go['targetHp'] ?? 100) >= 80 ? 'fresh' : 'hurt',
                'gear' => $gap === null ? null : ($gap > 5 ? 'defenders behind' : ($gap < -5 ? 'defenders ahead' : 'even')),
                'choice' => $first === null ? ($pressable ? 'none' : 'none: nothing pressable') : ($first['danger'] ? 'late' : 'early'),
                'count' => count($answers) === 0 ? '0' : (count($answers) === 1 ? '1' : '2+'),
                'spell' => $spellChoice,
                // Danger reached in this go: an answer pressed in danger, or a death. A go that never
                // reached danger with nothing pressed cannot be told from one an early answer defused.
                'danger' => (bool) $go['killLater'] || collect_first($answers, fn ($x) => $x['danger']) !== null,
                'died' => (bool) $go['killLater'],
                'won' => (bool) ($won[$defending] ?? false),
                'nextBigDown' => $nextValid ? $next['cover']['bigDown'] >= 2 : null,
                'nextDied' => $nextValid ? (bool) $next['killLater'] : null,
            ];
        }
    }
}

function median(array $v): float
{
    sort($v);
    $n = count($v);

    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

function collect_first(array $list, callable $fn)
{
    foreach ($list as $x) {
        if ($fn($x)) {
            return $x;
        }
    }

    return null;
}

function rate(array $set, string $key): string
{
    $vals = array_values(array_filter(array_column($set, $key), fn ($v) => $v !== null));
    if (count($vals) < MIN) {
        return sprintf('%6s', '-').sprintf(' (%4d)', count($vals));
    }

    return sprintf('%5.0f%%', 100 * array_sum($vals) / count($vals)).sprintf(' (%4d)', count($vals));
}

function table(string $title, array $rows, string $by): void
{
    echo "\n{$title}\n";
    printf("  %-24s %14s %14s %14s %14s\n", $by, 'died', 'next: 2+ down', 'next died', 'won round');
    $groups = [];
    foreach ($rows as $r) {
        if ($r[$by] !== null) {
            $groups[$r[$by]][] = $r;
        }
    }
    ksort($groups);
    foreach ($groups as $k => $set) {
        printf("  %-24s %14s %14s %14s %14s\n", $k, rate($set, 'died'), rate($set, 'nextBigDown'), rate($set, 'nextDied'), rate($set, 'won'));
    }
}

echo 'Brackets in the archive: '.implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($brackets), $brackets))."\n";
printf("%d goes read. Left out: %d after the first death, %d without a ledger, %d games measured before VERSION 12 (run wow:population --fresh).\n",
    count($rows), $skipped['afterDeath'], $skipped['noCover'], $skipped['oldVersion']);
echo 'Rates with the number of goes behind them; under '.MIN." goes, no rate.\n";

table('ALL GOES, situation ignored (the naive read)', $rows, 'choice');
table('ALL GOES, answers drawn', $rows, 'count');
// "Late" means the target was in danger, which is closer to dying by definition. Holding danger
// fixed compares like with like, at the cost of dropping the goes an early answer kept out of it.
table('GOES THAT REACHED DANGER (early answer, then danger anyway, against first answer in danger)',
    array_filter($rows, fn ($r) => $r['danger']), 'choice');

foreach ([0, 1, 2] as $bd) {
    foreach (['light', 'heavy'] as $pr) {
        $cell = array_filter($rows, fn ($r) => $r['bigDown'] === $bd && $r['pressure'] === $pr);
        $label = ($bd === 2 ? '2+' : $bd)." big answers down, {$pr} pressure";
        table("SITUATION: {$label}", $cell, 'choice');
        table("SITUATION: {$label}", $cell, 'count');
    }
}

// Early against late again, with the target's health at the go's start held: a go that opened on a
// fresh target and still had its first answer go out only in danger is the cleaner "late".
foreach (['fresh', 'hurt'] as $hp) {
    foreach ([0, 2] as $bd) {
        table("HEAVY GOES, target {$hp} at the start, ".($bd === 2 ? '2+' : $bd).' big answers down',
            array_filter($rows, fn ($r) => $r['pressure'] === 'heavy' && $r['targetHp'] === $hp && $r['bigDown'] === $bd), 'choice');
    }
}

// Gear is context, not a choice: how much the item-level gap alone moves the outcome, and whether
// the drained-and-heavy read holds when the two sides are even.
table('ALL GOES, by the attackers\' item level against the defenders\'', $rows, 'gear');
table('HEAVY GOES, 2+ big answers down, gear even', array_filter($rows, fn ($r) => $r['pressure'] === 'heavy' && $r['bigDown'] === 2 && $r['gear'] === 'even'), 'choice');

if (isset($opts['spell'])) {
    foreach (['light', 'heavy'] as $pr) {
        foreach (['fresh', 'hurt'] as $hp) {
            table("{$opts['spell']}, {$pr} pressure, target {$hp} at the start (goes where it was back)",
                array_filter($rows, fn ($r) => $r['pressure'] === $pr && $r['targetHp'] === $hp), 'spell');
        }
    }
}
