<?php
// Kicks against the chance to kick (2026-10-03). "He didn't have the reaction time to stop their
// CC" is a claim about chances: an instant stun or fear cannot be kicked, so the question is only
// asked of enemy crowd control WITH A CAST TIME (a SPELL_CAST_START of a spell the data calls
// crowd control), and only while the player could have answered: alive, not locked out, and with
// his interrupt off cooldown. For each, did he stop it, did a teammate, or did it land, and how
// long after the cast began did his interrupt come?
//
//   php -d memory_limit=2G tools/match-review/kickread.php [--class=hunter] [--me=Doubletapz] [--since=2026-09-01]
//
// The interrupt per class and its cooldown are below. Range and line of sight are not in the log:
// a chance here may have been out of reach. Output names other players: not committed.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\ArenaMomentService;

const ARCHIVE = 'D:/MindCollector/arena-logs';
const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];
// class => [interrupt names, base cooldown]. The base value: talents that shorten it are not read.
const KICKS = [
    'hunter' => [['Counter Shot', 'Muzzle'], 24], 'rogue' => [['Kick'], 15], 'warrior' => [['Pummel'], 15],
    'deathknight' => [['Mind Freeze'], 15], 'monk' => [['Spear Hand Strike'], 15], 'paladin' => [['Rebuke'], 15],
    'shaman' => [['Wind Shear'], 12], 'druid' => [['Skull Bash', 'Solar Beam'], 15], 'mage' => [['Counterspell'], 24],
    'demonhunter' => [['Disrupt'], 15], 'evoker' => [['Quell'], 20], 'warlock' => [['Spell Lock', 'Axe Toss'], 24], 'priest' => [['Silence'], 45],
];

$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$class = $opt('class', 'hunter');
$meName = $opt('me', 'Doubletapz');
$since = $opt('since', '2026-09-01');
[$kickNames, $kickCd] = KICKS[$class];

$svc = app(ArenaMomentService::class);
$cc = $svc->crowdControl();
$specIds = App\Models\Specialization::whereHas('gameClass', fn ($q) => $q->where('slug', $class))->pluck('external_spec_id')->map(fn ($v) => (string) $v)->all();

$seconds = function (string $line): ?float {
    if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
        return null;
    }

    return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
};

$rows = [];   // one per (game, player of the class)
foreach (glob(ARCHIVE.'/metadata/*.json') as $file) {
    $m = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file)), true);
    if (! in_array($m['startInfo']['bracket'] ?? '', ['3v3', '2v2'], true) || date('Y-m-d', intdiv($m['startTime'], 1000)) < $since || ! is_file(ARCHIVE."/raw/{$m['id']}.log.gz")) {
        continue;
    }
    $mine = array_values(array_filter($m['units'], fn ($u) => str_starts_with($u['id'], 'Player-') && in_array((string) ($u['spec'] ?? ''), $specIds, true)));
    if (! $mine) {
        continue;
    }

    $t0 = null;
    $team = [];
    $starts = [];      // enemy CC cast starts
    $ends = [];        // src|spell => [t, kind]
    $interrupts = [];
    $kicks = [];       // who => [t...]
    $locks = [];       // who => [[from,to]]
    $open = [];
    $died = [];
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
        if (! in_array($e, ['SPELL_CAST_START', 'SPELL_CAST_SUCCESS', 'SPELL_CAST_FAILED', 'SPELL_INTERRUPT', 'SPELL_AURA_APPLIED', 'SPELL_AURA_REMOVED', 'UNIT_DIED'], true)) {
            continue;
        }
        $f = str_getcsv($body);
        if ($e === 'UNIT_DIED') {
            if (str_starts_with($f[5], 'Player-') && end($f) === '0') {
                $died[$f[5]] ??= $t;
            }

            continue;
        }
        if ($e === 'SPELL_CAST_START' && isset($cc[(int) $f[9]]) && str_starts_with($f[1], 'Player-')) {
            $starts[] = ['t' => $t, 'src' => $f[1], 'spell' => $f[10]];
        } elseif ($e === 'SPELL_CAST_SUCCESS' || $e === 'SPELL_CAST_FAILED') {
            $ends[$f[1].'|'.$f[10]][] = [$t, $e === 'SPELL_CAST_SUCCESS' ? 'landed' : 'failed', $f[5] ?? ''];
            if ($e === 'SPELL_CAST_SUCCESS' && in_array($f[10], $kickNames, true)) {
                $kicks[$f[1]][] = $t;
            }
        } elseif ($e === 'SPELL_INTERRUPT') {
            $interrupts[] = ['t' => $t, 'by' => $f[1], 'on' => $f[5], 'spell' => $f[10], 'stopped' => $f[13] ?? ''];
        } elseif (($e === 'SPELL_AURA_APPLIED' || $e === 'SPELL_AURA_REMOVED') && ($f[12] ?? '') === 'DEBUFF' && in_array($cc[(int) $f[9]] ?? '', LOCKOUT, true) && $f[10] !== 'Garrote') {
            $k = $f[5].'|'.$f[10];
            if ($e === 'SPELL_AURA_APPLIED') {
                $open[$k] = $t;
            } elseif (isset($open[$k])) {
                $locks[$f[5]][] = [$open[$k], $t];
                unset($open[$k]);
            }
        }
    }
    foreach ($open as $k => $from) {
        $locks[explode('|', $k)[0]][] = [$from, $t];
    }

    foreach ($mine as $u) {
        $g = $u['id'];
        $side = $team[$g] ?? null;
        if ($side === null) {
            continue;
        }
        $name = explode('-', $u['name'])[0];
        $row = ['name' => $name, 'game' => date('m-d H:i', intdiv($m['startTime'], 1000)), 'enemyCasts' => 0, 'chances' => 0, 'mine' => 0, 'mate' => 0, 'landed' => 0, 'other' => 0, 'delays' => [], 'kicksUsed' => count($kicks[$g] ?? []), 'mins' => ($m['durationInSeconds'] ?? 0) / 60];
        foreach ($starts as $c) {
            if (($team[$c['src']] ?? $side) === $side) {
                continue;   // our own side's casts
            }
            $row['enemyCasts']++;
            $t = $c['t'];
            $alive = ! isset($died[$g]) || $died[$g] > $t;
            $free = ! collect($locks[$g] ?? [])->contains(fn ($iv) => $t >= $iv[0] && $t < $iv[1]);
            $ready = ! collect($kicks[$g] ?? [])->contains(fn ($k) => $k < $t && $t < $k + $kickCd);
            if (! ($alive && $free && $ready)) {
                continue;
            }
            $row['chances']++;
            $int = collect($interrupts)->first(fn ($i) => $i['on'] === $c['src'] && $i['t'] >= $t && $i['t'] <= $t + 3.0);
            $end = collect($ends[$c['src'].'|'.$c['spell']] ?? [])->first(fn ($x) => $x[0] >= $t);
            if ($int && $int['by'] === $g) {
                $row['mine']++;
                $row['delays'][] = round($int['t'] - $t, 2);
            } elseif ($int && ($team[$int['by']] ?? null) === $side) {
                $row['mate']++;
            } elseif ($end && $end[1] === 'landed' && $end[0] - $t <= 3.0) {
                $row['landed']++;
            } else {
                $row['other']++;   // cancelled, failed, or moved out of: no one had to stop it
            }
        }
        $rows[] = $row;
    }
}

$pct = fn ($n, $d) => $d > 0 ? sprintf('%d/%d (%d%%)', $n, $d, round(100 * $n / $d)) : '-';
$report = function (string $label, array $rs) use ($pct) {
    $c = collect($rs);
    $delays = $c->flatMap(fn ($r) => $r['delays'])->sort()->values();
    printf("%-22s %3d games | enemy CC casts %3d | chances (alive, free, kick ready) %3d | he kicked %-14s | a teammate %-14s | landed %-14s | other %d | kick delay median %s | kicks used a game %.1f\n",
        $label, $c->count(), $c->sum('enemyCasts'), $c->sum('chances'), $pct($c->sum('mine'), $c->sum('chances')), $pct($c->sum('mate'), $c->sum('chances')),
        $pct($c->sum('landed'), $c->sum('chances')), $c->sum('other'), $delays->isEmpty() ? '-' : $delays->median().'s', $c->avg('kicksUsed'));
};

echo "Enemy crowd control WITH A CAST TIME, while each {$class} was alive, free and had ".implode('/', $kickNames)." ready ({$kickCd}s base cooldown)\n\n";
$report($meName, array_values(array_filter($rows, fn ($r) => $r['name'] === $meName)));
$report("every other {$class}", array_values(array_filter($rows, fn ($r) => $r['name'] !== $meName)));
echo "\nPer player (3+ games):\n";
foreach (collect($rows)->groupBy('name')->filter(fn ($g) => $g->count() >= 3)->sortByDesc(fn ($g) => $g->count()) as $n => $g) {
    $report($n, $g->all());
}
echo "\n{$meName}, game by game:\n";
foreach (array_filter($rows, fn ($r) => $r['name'] === $meName) as $r) {
    printf("  %s  enemy CC casts %2d  chances %2d  kicked %d  teammate %d  landed %d  delays %s\n", $r['game'], $r['enemyCasts'], $r['chances'], $r['mine'], $r['mate'], $r['landed'], json_encode($r['delays']));
}
