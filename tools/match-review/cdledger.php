<?php
// The cooldown ledger (2026-10-02). Drain-then-kill says a go kills once the other side's
// defensives are down; it does not say whether the defensives that went were a good TRADE, or
// whether the side that spent them should have waited before going again. Chriso's framing: a
// 60s defensive (Barkskin) against 60s offensives (Kingsbane, Combustion) is a fair trade, because
// both come back together; a 180s Pain Suppression spent on the same go is not, and the next time
// those 60s cooldowns come round it is not there. This reads every go as that trade, from the
// stored per-game analysis (arena_rounds.payload) only, with every cooldown TALENT-RESOLVED from the
// player's own COMBATANT_INFO talents (payload.combatants), not the spell table's base value.
//
//   php -d memory_limit=1G tools/match-review/cdledger.php [--user=2] [--bracket=3v3|shuffle|all] [--cds] [--games]
//
//   --cds    print every (spec, spell) cooldown used, base against resolved, to check the inputs
//   --games  print the ledger of every enemy go, one line each
//
// What each player COULD press is their spec's matchup profile `answers` (default build) plus every
// defensive they actually pressed in that game. A defensive's target is not stored, so coverage is
// read for the TEAM, not for the player the go was on. Output names other players: not committed.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\CooldownLedgerService;
use App\Models\ArenaRound;
use App\Models\Specialization;

const FAIR_SLACK = 15;     // a defensive back within this many seconds of the offensives it answered is a fair trade
const MEDALLION = CooldownLedgerService::MEDALLION;

$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$userId = (int) $opt('user', '2');
$bracket = $opt('bracket', 'all');
$showCds = in_array('--cds', $argv, true);
$showGames = in_array('--games', $argv, true);

$specs = Specialization::all()->keyBy('external_spec_id');

// ---- talent-resolved cooldowns -------------------------------------------------------------------
// CooldownLedgerService is the definition RoundAnalysisService stores on every go (version 6); these
// wrappers keep this script's reads on exactly the same numbers.
$ledgerSvc = app(CooldownLedgerService::class);
$answersOf = fn (string $classSlug, string $specSlug): array => $ledgerSvc->profileAnswers($classSlug, $specSlug);
$buildOf = fn (array $combatant, $spec) => $ledgerSvc->build($combatant, $spec);
$cdLog = [];
$cdOf = function (string $name, $spec, array $build) use ($ledgerSvc, &$cdLog): ?array {
    $cd = $ledgerSvc->cooldown($name, $spec, $build);
    if ($cd && $name !== MEDALLION) {
        $cdLog[$spec->name.' | '.$name][] = ($cd['base'] !== null && $cd['base'] != $cd['cd'] ? round($cd['base']).'->' : '').round($cd['cd']).($cd['charges'] > 1 ? "x{$cd['charges']}" : '');
    }

    return $cd;
};

// Is $spell (by $who) ready at $t, given every press of it in $presses (times)?
$ready = function (array $times, array $cd, float $t): bool {
    $recent = array_filter($times, fn ($p) => $p < $t && $t < $p + $cd['cd']);

    return count($recent) < $cd['charges'];
};

// ---- read every game ------------------------------------------------------------------------------
$rounds = ArenaRound::where('user_id', $userId)->orderBy('played_at')->get();
$ledger = [];   // per enemy go
$trades = [];   // per defensive of ours pressed inside an enemy go
$ourGoesRead = [];
foreach ($rounds as $r) {
    $a = $r->payload['analysis'] ?? null;
    $combatants = $r->payload['combatants'] ?? [];
    if (! $a || ! $combatants) {
        continue;
    }
    $bk = $r->bracket === 'Rated Solo Shuffle' ? 'shuffle' : $r->bracket;
    if ($bracket !== 'all' && $bk !== $bracket) {
        continue;
    }
    $players = collect($a['players'])->keyBy('guid');
    $me = $players->first(fn ($p) => ! empty($p['logger']));
    if (! $me) {
        continue;
    }
    $usSide = $me['side'];
    $game = substr((string) $r->played_at, 5, 11).' '.explode('-', $me['name'])[0];

    // each player's resolved build and answer set
    $info = [];
    foreach ($players as $guid => $p) {
        $spec = $specs->get($p['specExternalId']);
        if (! $spec || ! isset($combatants[$guid])) {
            continue;
        }
        $info[$guid] = ['spec' => $spec, 'build' => $buildOf($combatants[$guid], $spec), 'side' => $p['side'], 'name' => explode('-', $p['name'])[0], 'specName' => $p['spec']];
    }

    // presses: defensives (both sides) and offensives (from go links, both sides)
    $defPress = [];   // guid => spell => [t...]
    foreach (['us', 'them'] as $side) {
        foreach ($a['defensives'][$side]['rows'] ?? [] as $row) {
            $defPress[$row['who']][$row['spell']][] = $row['t'];
        }
    }
    $offPress = [];   // guid => spell => [t...]
    foreach ($a['goes'] as $g) {
        foreach ($g['links'] as $l) {
            if (in_array($l['cat'], ['offensive', 'mixed'], true)) {
                $offPress[$l['by']][$l['spell']][] = $g['from'] + $l['t'];
            }
        }
    }
    foreach ($offPress as &$bySpell) {
        foreach ($bySpell as &$ts) {
            $ts = array_values(array_unique($ts));
        }
    }
    unset($bySpell, $ts);

    // every answer one side could press: profile answers + whatever was pressed
    $answerSet = function (string $side) use ($info, $answersOf, $defPress, $players) {
        $set = [];
        foreach ($info as $guid => $i) {
            if ($i['side'] !== $side) {
                continue;
            }
            [$classSlug, $specSlug] = [$players[$guid]['classSlug'], $i['spec']->slug];
            $names = array_unique(array_merge($answersOf($classSlug, $specSlug), array_keys($defPress[$guid] ?? [])));
            foreach ($names as $n) {
                $set[] = [$guid, $n];
            }
        }

        return $set;
    };
    $coverage = function (string $side, float $t, bool $bigOnly = false) use ($answerSet, $info, $cdOf, $defPress, $ready) {
        $up = 0;
        $all = 0;
        $down = [];
        foreach ($answerSet($side) as [$guid, $n]) {
            if ($n === MEDALLION) {
                continue;   // a CC break, not damage: read separately
            }
            $cd = $cdOf($n, $info[$guid]['spec'], $info[$guid]['build']);
            if (! $cd || ($bigOnly && $cd['cd'] < 90)) {
                continue;
            }
            $all++;
            if ($ready($defPress[$guid][$n] ?? [], $cd, $t)) {
                $up++;
            } else {
                $down[] = $n;
            }
        }

        return ['up' => $up, 'all' => $all, 'down' => $down];
    };

    $ourGoes = collect($a['goes'])->where('side', $usSide)->values();
    $theirGoes = collect($a['goes'])->reject(fn ($g) => $g['side'] === $usSide)->values();

    foreach ($theirGoes as $i => $g) {
        $kill = $g['kill'] || $g['killLater'];
        $cov = $coverage($usSide, $g['from']);
        $big = $coverage($usSide, $g['from'], true);
        // Did one of OUR goes end (to = last cast + 15s) in the 20s before this one started: a counter-go.
        $after = $ourGoes->first(fn ($o) => $o['from'] < $g['from'] && $g['from'] - ($o['to'] - 15) <= 20);
        $ourCovAtOurGo = $after ? $coverage($usSide, $after['from'], true) : null;
        // The go of ours before this one, whatever the gap: how many big answers did we go with down?
        $prevOurs = $ourGoes->filter(fn ($o) => $o['from'] < $g['from'])->last();
        $bigDownAtPrevOurs = $prevOurs ? (fn ($c) => $c['all'] - $c['up'])($coverage($usSide, $prevOurs['from'], true)) : null;
        // Their offensives in this go, and whether each was fresh or back from an earlier press.
        $offs = collect($g['links'])->whereIn('cat', ['offensive', 'mixed'])->map(fn ($l) => $l['spell'])->unique()->values()->all();
        $ledger[] = ['bk' => $bk, 'game' => $game, 'n' => $i + 1, 'from' => $g['from'], 'kill' => $kill, 'cov' => $cov, 'big' => $big,
            'counter' => (bool) $after, 'ourCovAtOurGo' => $ourCovAtOurGo, 'bigDownAtPrevOurs' => $bigDownAtPrevOurs, 'offs' => $offs, 'healerCc' => $g['healerCc'] ?? 0,
            'target' => $info[$g['target']]['name'] ?? '?'];

        // Each of our defensives pressed inside this go: what did it trade against?
        foreach ($a['defensives'][$usSide]['rows'] ?? [] as $row) {
            if ($row['spell'] === MEDALLION || $row['t'] < $g['from'] || $row['t'] > $g['to']) {
                continue;
            }
            $who = $row['who'];
            if (! isset($info[$who])) {
                continue;
            }
            $dcd = $cdOf($row['spell'], $info[$who]['spec'], $info[$who]['build']);
            if (! $dcd) {
                continue;
            }
            // Their offensives cast in this go before the press, each with its own resolved cooldown.
            $drew = [];
            foreach ($g['links'] as $l) {
                $lt = $g['from'] + $l['t'];
                if (in_array($l['cat'], ['offensive', 'mixed'], true) && $lt <= $row['t'] && isset($info[$l['by']])) {
                    if ($ocd = $cdOf($l['spell'], $info[$l['by']]['spec'], $info[$l['by']]['build'])) {
                        $drew[] = ['spell' => $l['spell'], 'back' => $lt + $ocd['cd'], 'cd' => $ocd['cd']];
                    }
                }
            }
            if (! $drew) {
                continue;
            }
            // Their go comes back when its FIRST offensive does: that is when this answer is next needed.
            $theyBack = min(array_column($drew, 'back'));
            // A charge spell is back when its charge is: with a charge left it was never "down".
            $chargesLeft = $dcd['charges'] - count(array_filter($defPress[$who][$row['spell']], fn ($p) => $p <= $row['t'] && $row['t'] < $p + $dcd['cd']));
            $weBack = $chargesLeft > 0 ? $row['t'] : $row['t'] + $dcd['cd'];
            $next = $theirGoes->first(fn ($x) => $x['from'] > $g['to'] - 15 && $x['from'] > $row['t']);
            $trades[] = ['bk' => $bk, 'game' => $game, 'spell' => $row['spell'], 'who' => $info[$who]['name'], 'cd' => $dcd['cd'],
                'drew' => collect($drew)->unique('spell')->map(fn ($d) => $d['spell'].' '.round($d['cd']))->implode(', '),
                'gap' => $weBack - $theyBack,
                'nextBeforeBack' => $next ? $next['from'] < $weBack : null,
                'nextKilled' => $next ? ($next['kill'] || $next['killLater']) : null];
        }
    }
}

// ---- report ----------------------------------------------------------------------------------------
$pct = fn ($n, $d) => $d > 0 ? sprintf('%d/%d (%d%%)', $n, $d, round(100 * $n / $d)) : '-';

if ($showCds) {
    echo "=== cooldowns used (spec | spell: base->resolved, per build seen)\n";
    ksort($cdLog);
    foreach ($cdLog as $k => $v) {
        echo "  {$k}: ".implode(' ', array_unique($v))."\n";
    }
}

foreach (collect($ledger)->groupBy('bk') as $bk => $L) {
    echo "\n================ {$bk}: ".$L->count()." enemy goes, ".$L->where('kill', true)->count()." killed one of us\n";

    echo "\n-- 1. Our answers ready when their go started (share of the team's damage defensives, Medallion excluded)\n";
    foreach ([[0, 0.5, 'under half'], [0.5, 0.75, 'half to three quarters'], [0.75, 1.01, 'three quarters or more']] as [$lo, $hi, $lab]) {
        $b = $L->filter(fn ($x) => $x['cov']['all'] > 0 && $x['cov']['up'] / $x['cov']['all'] >= $lo && $x['cov']['up'] / $x['cov']['all'] < $hi);
        printf("   %-24s %s killed\n", $lab, $pct($b->where('kill', true)->count(), $b->count()));
    }
    echo "   big answers only (cooldown 90s+):\n";
    foreach ([[0, 0, 'none down'], [1, 1, 'one down'], [2, 99, 'two or more down']] as [$lo, $hi, $lab]) {
        $b = $L->filter(fn ($x) => ($d = $x['big']['all'] - $x['big']['up']) >= $lo && $d <= $hi);
        printf("   %-24s %s killed\n", $lab, $pct($b->where('kill', true)->count(), $b->count()));
    }

    // Dampening and spent mana make every late go likelier to kill, and late goes also find more
    // cooldowns down. Hold time roughly constant before believing the coverage split.
    echo "   the same, inside bands of game time (big answers down: none | one | two or more):\n";
    foreach ([[0, 60, 'go starts before 60s'], [60, 120, '60-120s'], [120, 9999, 'after 120s']] as [$lo, $hi, $lab]) {
        $band = $L->filter(fn ($x) => $x['from'] >= $lo && $x['from'] < $hi);
        $cells = [];
        foreach ([[0, 0], [1, 1], [2, 99]] as [$dlo, $dhi]) {
            $b = $band->filter(fn ($x) => ($d = $x['big']['all'] - $x['big']['up']) >= $dlo && $d <= $dhi);
            $cells[] = str_pad($pct($b->where('kill', true)->count(), $b->count()), 16);
        }
        printf("   %-22s %s\n", $lab, implode(' | ', $cells));
    }

    echo "\n-- 2. Going while our own big answers are down (Chriso: delay the go until they are back)\n";
    $c = $L->where('counter', true);
    $nc = $L->where('counter', false);
    printf("   their go within 20s of the end of ours (a counter-go) %s killed | other goes %s killed\n", $pct($c->where('kill', true)->count(), $c->count()), $pct($nc->where('kill', true)->count(), $nc->count()));
    echo "   their next go, by how many of OUR big answers were down when WE last went:\n";
    foreach ([[0, 0, 'none down'], [1, 1, 'one down'], [2, 99, 'two or more down']] as [$lo, $hi, $lab]) {
        $b = $L->filter(fn ($x) => $x['bigDownAtPrevOurs'] !== null && $x['bigDownAtPrevOurs'] >= $lo && $x['bigDownAtPrevOurs'] <= $hi);
        printf("   %-24s %s killed\n", $lab, $pct($b->where('kill', true)->count(), $b->count()));
    }

    $T = collect($trades)->where('bk', $bk);
    echo "\n-- 3. The trade: each of our defensives pressed inside their go, against the offensives that drew it\n";
    echo "   gap = when our defensive is back minus when their first offensive of that go is back (a charge left = back at once)\n";
    foreach ([[-999, FAIR_SLACK, 'fair (back within '.FAIR_SLACK.'s of theirs, or before)'], [FAIR_SLACK, 9999, 'expensive (back later)']] as [$lo, $hi, $lab]) {
        $b = $T->filter(fn ($x) => $x['gap'] >= $lo && $x['gap'] < $hi);
        $withNext = $b->filter(fn ($x) => $x['nextBeforeBack'] !== null);
        $early = $withNext->where('nextBeforeBack', true);
        printf("   %-44s %3d presses | their next go came before it was back: %s, and that go killed %s\n", $lab, $b->count(),
            $pct($early->count(), $withNext->count()), $pct($early->where('nextKilled', true)->count(), $early->count()));
    }
    $late = $T->filter(fn ($x) => $x['nextBeforeBack'] === false);
    printf("   (any press whose next enemy go came after it was back: that go killed %s)\n", $pct($late->where('nextKilled', true)->count(), $late->count()));
    echo "   by spell (presses, median gap in seconds, next go before it was back -> killed):\n";
    foreach ($T->groupBy('spell')->sortByDesc(fn ($x) => $x->count())->take(14) as $spell => $b) {
        $early = $b->where('nextBeforeBack', true);
        printf("     %-26s %3d  gap %5.0fs  %s -> %s\n", $spell, $b->count(), $b->pluck('gap')->median(), $pct($early->count(), $b->filter(fn ($x) => $x['nextBeforeBack'] !== null)->count()), $pct($early->where('nextKilled', true)->count(), $early->count()));
    }

    if ($showGames) {
        echo "\n-- every enemy go\n";
        foreach ($L as $x) {
            printf("   %s go %d at %3.0fs on %-12s %s | answers up %d/%d, big down: %s | %s | %s\n", $x['game'], $x['n'], $x['from'], $x['target'], $x['kill'] ? 'KILL' : '    ',
                $x['cov']['up'], $x['cov']['all'], $x['big']['down'] ? implode(', ', $x['big']['down']) : '-', $x['counter'] ? 'counter-go' : '', implode(', ', $x['offs']));
        }
    }
}
