<?php

// One spec, several players side by side: how much each does per minute alive, what they press,
// what their control lands on, how their cooldowns are timed, and how they die. The comparison
// behind "how does my Feral differ from a higher-rated Feral in my own logs" (match-review-analysis.md).
// Draft, like the rest of this folder; match-review-operations.md, "One spec, side by side".
//
//   php -d memory_limit=1G tools/match-review/specread.php --spec=103 --since=2026-09-01 \
//       --col="Me:Crawlordx" --col="Rastic 2150+:Rastic:2150:9999" --col="Others 2100+:*:2100:9999" \
//       [--with=Doubletapz] [--deaths=Crawlordx]
//
//   --col=Label:Name[:minMMR:maxMMR[:W|L]]   one column. Name * is every other player of the spec.
//   --with=Name     columns naming the first column's player only count games this player was also in.
//   --deaths=Name   print each real death of this player: health, defensives, lockout, their cooldowns.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\ArenaMomentService;
use App\Models\Patch;
use App\Models\Spell;

const ARCHIVE = 'D:/MindCollector/arena-logs';
const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];
const GAP = 2.5;            // a stretch this long between two casts counts as not pressing anything
const ALIGN = 6.0;          // a teammate's offensive cooldown this close counts as pressed together
const AFTER_CD = 8.0;       // the stretch after an offensive cooldown in which their healer's lockout is read
const POWER = [0 => 'mana', 1 => 'rage', 2 => 'focus', 3 => 'energy', 6 => 'runic power', 12 => 'chi'];

$opt = fn (string $k, ?string $default = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $default);
$specId = (string) $opt('spec', '103');
$since = $opt('since', '2026-09-01');
$bracket = $opt('bracket', '3v3');
$with = $opt('with');
$deathsOf = $opt('deaths');
$cols = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--col=')) {
        $p = explode(':', substr($a, 6));
        $cols[] = ['label' => $p[0], 'name' => $p[1] ?? '*', 'lo' => (int) ($p[2] ?? 0), 'hi' => (int) ($p[3] ?? 99999), 'res' => $p[4] ?? null];
    }
}
$named = array_values(array_filter(array_unique(array_column($cols, 'name')), fn ($n) => $n !== '*'));

$svc = app(ArenaMomentService::class);
$patch = Patch::where('is_current', true)->value('id');
$cooldownOf = Spell::where('patch_id', $patch)->where('cooldown_seconds', '>=', 20)->pluck('cooldown_seconds', 'spell_id')->all();

$seconds = function (string $line): ?float {
    if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
        return null;
    }

    return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
};
$union = function (array $iv): array {
    usort($iv, fn ($a, $b) => $a[0] <=> $b[0]);
    $out = [];
    foreach ($iv as [$a, $b]) {
        if ($out && $a <= $out[count($out) - 1][1]) {
            $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
        } else {
            $out[] = [$a, $b];
        }
    }

    return $out;
};
$len = fn (array $iv) => array_sum(array_map(fn ($x) => $x[1] - $x[0], $iv));
$cross = function (array $a, array $b) {
    $s = 0;
    foreach ($a as $x) {
        foreach ($b as $y) {
            $s += max(0, min($x[1], $y[1]) - max($x[0], $y[0]));
        }
    }

    return $s;
};
$short = fn ($name) => explode('-', $name)[0];

$records = [];   // one per (game, player of the spec)
$defNames = [];  // every spell the labels call defensive that a measured player pressed
const SELF_CARE = ['Bear Form', 'Frenzied Regeneration', 'Regrowth', "Gladiator's Medallion", 'Exhilaration', 'Feign Death', 'Shadowmeld', 'Renewal'];
$deathNotes = [];

foreach (glob(ARCHIVE.'/metadata/*.json') as $file) {
    $m = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file)), true);
    if (($m['startInfo']['bracket'] ?? null) !== $bracket || date('Y-m-d', intdiv($m['startTime'], 1000)) < $since) {
        continue;
    }
    $players = array_filter($m['units'], fn ($u) => str_starts_with($u['id'], 'Player-') && (string) ($u['spec'] ?? '') === $specId);
    if (! $players || ! is_file(ARCHIVE."/raw/{$m['id']}.log.gz")) {
        continue;
    }
    $lines = array_map('rtrim', gzfile(ARCHIVE."/raw/{$m['id']}.log.gz"));
    $roster = $svc->roster($m);
    $tl = $svc->readTimeline($lines, $roster);
    $clock = date('m-d H:i', intdiv($m['startTime'], 1000));
    $allNames = array_map(fn ($r) => $short($r['name']), $roster);

    // One pass: everything a per-player read needs, kept raw.
    $t0 = null;
    $team = [];
    $end = null;
    $owner = [];
    $casts = [];
    $dmg = [];
    $heal = [];
    $auras = [];      // closed auras: [src, dst, name, type, from, to]
    $open = [];
    $kicks = [];
    $petCasts = [];   // a pet's cooldown presses: some players' Master's Call is logged from the pet only
    $last = 0.0;
    foreach ($lines as $line) {
        $s = $seconds($line);
        if ($s === null) {
            continue;
        }
        $t0 ??= $s;
        $t = round($s - $t0, 2);
        $last = $t;
        $body = explode('  ', $line, 2)[1] ?? '';
        $event = strtok($body, ',');
        if ($event === 'COMBATANT_INFO') {
            $p = explode(',', $body, 4);
            $team[$p[1]] = $p[2];

            continue;
        }
        if ($event === 'ARENA_MATCH_END') {
            $end = explode(',', $body);

            continue;
        }
        if ($event === 'SPELL_SUMMON') {
            $f = str_getcsv($body);
            $owner[$f[5]] = $f[1];

            continue;
        }
        if ($event === 'SPELL_CAST_SUCCESS') {
            $f = str_getcsv($body);
            if (! str_starts_with($f[1], 'Player-') && str_starts_with($f[13] ?? '', 'Player-')) {
                $owner[$f[1]] ??= $f[13];
            }
            if (isset($roster[$f[1]])) {
                $n = count($f);
                $casts[] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'id' => (int) $f[9], 'spell' => $f[10],
                    'ptype' => (int) ($f[$n - 9] ?? -1), 'pcur' => (int) ($f[$n - 8] ?? 0), 'pmax' => (int) ($f[$n - 7] ?? 0)];
            } elseif (isset($cooldownOf[(int) $f[9]], $owner[$f[1]])) {
                $petCasts[] = ['t' => $t, 'owner' => $owner[$f[1]], 'spell' => 'pet: '.$f[10]];
            }

            continue;
        }
        if ($event === 'SPELL_INTERRUPT') {
            $f = str_getcsv($body);
            $kicks[] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'spell' => $f[10], 'what' => $f[count($f) - 2] ?? ''];

            continue;
        }
        if ($event === 'SPELL_AURA_APPLIED' || $event === 'SPELL_AURA_REMOVED') {
            $f = str_getcsv($body);
            if (! isset($roster[$f[5]])) {
                continue;
            }
            $k = $f[1].'|'.$f[5].'|'.$f[9];
            if ($event === 'SPELL_AURA_APPLIED') {
                $open[$k] = $t;
            } elseif (isset($open[$k])) {
                $auras[] = ['src' => $f[1], 'dst' => $f[5], 'name' => $f[10], 'type' => $f[12] ?? '', 'from' => $open[$k], 'to' => $t];
                unset($open[$k]);
            }

            continue;
        }
        if ($event === 'SPELL_HEAL' || $event === 'SPELL_PERIODIC_HEAL') {
            $f = str_getcsv($body);
            $n = count($f);
            if (isset($roster[$f[5]]) && is_numeric($f[$n - 5] ?? null) && is_numeric($f[$n - 3] ?? null)) {
                $heal[] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'amount' => max(0, (int) $f[$n - 5] - (int) $f[$n - 3]), 'spell' => $f[10]];
            }

            continue;
        }
        if (! in_array($event, ['SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'RANGE_DAMAGE', 'SWING_DAMAGE_LANDED'], true)) {
            continue;
        }
        $f = str_getcsv($body);
        $swing = str_starts_with($event, 'SWING');
        $amount = $f[count($f) - ($swing ? 10 : 11)] ?? null;
        if (! is_numeric($amount) || ! isset($roster[$f[5]]) || $f[1] === $f[5]) {
            continue;
        }
        $dmg[] = ['t' => $t, 'src' => $f[1], 'dst' => $f[5], 'amount' => (int) $amount, 'spell' => $swing ? 'Melee' : $f[10]];
    }
    // Auras still up when the log ends run to the end.
    foreach ($open as $k => $from) {
        [$src, $dst, $id] = explode('|', $k);
        $auras[] = ['src' => $src, 'dst' => $dst, 'name' => '#'.$id, 'type' => '', 'from' => $from, 'to' => $last];
    }
    $credit = function ($src) use ($roster, &$owner) {
        $seen = 0;
        while (! isset($roster[$src]) && isset($owner[$src]) && $seen++ < 3) {
            $src = $owner[$src];
        }

        return $src;
    };
    $locked = [];
    foreach ($roster as $g => $r) {
        $locked[$g] = $union(array_map(fn ($c) => [$c['from'], $c['to']],
            array_filter($tl['control'], fn ($c) => $c['on'] === $g && in_array($c['dr'], LOCKOUT, true))));
    }
    $deathAt = array_column($tl['deaths'], 't', 'who');
    $firstDeath = $tl['deaths'][0] ?? null;

    foreach ($players as $u) {
        $g = $u['id'];
        if (! isset($roster[$g]) || ! isset($team[$g])) {
            continue;
        }
        $name = $short($u['name']);
        $side = $roster[$g]['side'];
        $mates = array_keys(array_filter($roster, fn ($r, $x) => $r['side'] === $side && $x !== $g, ARRAY_FILTER_USE_BOTH));
        $foes = array_keys(array_filter($roster, fn ($r) => $r['side'] !== $side));
        $foeHealer = null;
        $ownHealer = null;
        foreach ($roster as $x => $r) {
            if ($r['healer']) {
                $r['side'] === $side ? $ownHealer = $x : $foeHealer = $x;
            }
        }
        // Both MMRs sit on the END line, indexed by arena team id (rule 12); 3v3 only.
        $mmr = $end ? (int) ($end[3 + (int) $team[$g]] ?? 0) : 0;
        $opp = $end ? (int) ($end[3 + (1 - (int) $team[$g])] ?? 0) : 0;
        $won = $end ? ((string) $end[1] === (string) $team[$g]) : null;
        $alive = $deathAt[$g] ?? (float) $m['durationInSeconds'];
        $alive = max(1.0, min($alive, $last));
        $mine = fn ($src) => $credit($src) === $g;

        $r = ['name' => $name, 'clock' => $clock, 'mmr' => $mmr, 'opp' => $opp, 'won' => $won, 'alive' => $alive,
            'mates' => array_map(fn ($x) => $short($roster[$x]['name']), $mates), 'died' => isset($deathAt[$g]),
            'diedFirst' => $firstDeath && $firstDeath['who'] === $g, 'teamLostADeath' => $firstDeath && $roster[$firstDeath['who']]['side'] === $side,
            'casts' => [], 'dmg' => [], 'dmgHealer' => 0, 'dmgTotal' => 0, 'dmgTop' => 0, 'heal' => [], 'healSelf' => 0, 'healMates' => 0,
            'cc' => [], 'ccHealerSec' => 0.0, 'ccDpsSec' => 0.0, 'lockedSec' => $cross($locked[$g], [[0, $alive]]),
            'kicks' => 0, 'kicksHealer' => 0, 'kicked' => 0, 'taken' => 0, 'buff' => [], 'debuff' => [], 'power' => [],
            'cd' => [], 'idle' => 0.0, 'off' => [], 'hpAt' => []];

        $hp = $tl['health'][$g] ?? [];
        $hpAt = function (float $t) use ($hp) {
            $v = null;
            foreach ($hp as $h) {
                if ($h['t'] > $t) {
                    break;
                }
                $v = $h['pct'];
            }

            return $v;
        };
        $isDef = [];
        foreach ($tl['commitments'] as $cm) {
            if ($cm['who'] === $g && $cm['kind'] === 'defensive') {
                $isDef[$cm['spell']] = true;
                $defNames[$cm['spell']] = true;
            }
        }

        // Casts, the resource behind them, and the gaps between them.
        $prev = null;
        foreach ($casts as $c) {
            if ($c['src'] !== $g || $c['t'] > $alive) {
                continue;
            }
            $r['casts'][$c['spell']] = ($r['casts'][$c['spell']] ?? 0) + 1;
            if (isset(POWER[$c['ptype']]) && $c['pmax'] > 0) {
                $r['power'][$c['spell']][] = [$c['ptype'], $c['pcur'] / $c['pmax']];
            }
            if (isset($cooldownOf[$c['id']])) {
                $r['cd'][$c['spell']][] = $c['t'];
            }
            // Health when a defensive or a self-heal was pressed. Regrowth only when cast on oneself.
            if ((isset($isDef[$c['spell']]) || in_array($c['spell'], SELF_CARE, true)) && ($c['spell'] !== 'Regrowth' || $c['dst'] === $g)
                && ($v = $hpAt($c['t'])) !== null) {
                $r['hpAt'][$c['spell']][] = $v;
            }
            if ($prev !== null && $c['t'] - $prev > GAP) {
                // Time not pressing anything, less the part of it spent locked out.
                $r['idle'] += max(0, ($c['t'] - $prev - 1.5) - $cross($locked[$g], [[$prev + 1.5, $c['t']]]));
            }
            $prev = $c['t'];
        }

        // A pet's cooldown presses, under the owner. One press can log several times (once per
        // target it touches), so presses of one spell within a second are one.
        foreach ($petCasts as $c) {
            $presses = $r['cd'][$c['spell']] ?? [];
            $lastPress = $presses ? $presses[count($presses) - 1] : -INF;
            if ($c['owner'] === $g && $c['t'] <= $alive && $c['t'] - $lastPress > 1.0) {
                $r['cd'][$c['spell']][] = $c['t'];
            }
        }

        // Damage out (pets credited) and in.
        $byTarget = [];
        foreach ($dmg as $x) {
            if ($x['t'] > $alive) {
                continue;
            }
            if ($x['dst'] === $g) {
                $r['taken'] += $x['amount'];
            } elseif (in_array($x['dst'], $foes, true) && $mine($x['src'])) {
                $k = ($x['src'] === $g ? '' : 'pet: ').$x['spell'];
                $r['dmg'][$k] = ($r['dmg'][$k] ?? 0) + $x['amount'];
                $r['dmgTotal'] += $x['amount'];
                $byTarget[$x['dst']] = ($byTarget[$x['dst']] ?? 0) + $x['amount'];
                if ($x['dst'] === $foeHealer) {
                    $r['dmgHealer'] += $x['amount'];
                }
            }
        }
        $r['dmgTop'] = $byTarget ? max($byTarget) : 0;

        foreach ($heal as $x) {
            if ($x['src'] !== $g || $x['t'] > $alive) {
                continue;
            }
            $r['heal'][$x['spell']] = ($r['heal'][$x['spell']] ?? 0) + $x['amount'];
            $x['dst'] === $g ? $r['healSelf'] += $x['amount'] : $r['healMates'] += $x['amount'];
        }

        // Control this player landed. A pet's or trap's aura names no player, so an unowned source
        // is NOT credited: it is counted under "team, no caster" by the caller instead.
        foreach ($tl['control'] as $c) {
            if (! in_array($c['on'], $foes, true) || ! $mine($c['by'])) {
                continue;
            }
            $role = $c['on'] === $foeHealer ? 'healer' : 'dps';
            $secs = $c['to'] - $c['from'];
            $lock = in_array($c['dr'], LOCKOUT, true);
            $k = $c['spell'].' > '.$role;
            $r['cc'][$k] = ['n' => ($r['cc'][$k]['n'] ?? 0) + 1, 's' => ($r['cc'][$k]['s'] ?? 0) + $secs, 'dr' => $c['dr']];
            if ($lock) {
                $role === 'healer' ? $r['ccHealerSec'] += $secs : $r['ccDpsSec'] += $secs;
            }
        }
        foreach ($kicks as $x) {
            if ($mine($x['src']) && in_array($x['dst'], $foes, true)) {
                $r['kicks']++;
                $r['kicksHealer'] += $x['dst'] === $foeHealer ? 1 : 0;
            }
            if ($x['dst'] === $g) {
                $r['kicked']++;
            }
        }

        // Uptime: own buffs on self, and own debuffs on enemy players (at least one enemy has it).
        $bu = [];
        $de = [];
        foreach ($auras as $a) {
            if ($a['from'] > $alive) {
                continue;
            }
            $iv = [$a['from'], min($a['to'], $alive)];
            if ($a['dst'] === $g && $a['src'] === $g && $a['type'] === 'BUFF') {
                $bu[$a['name']][] = $iv;
            } elseif (in_array($a['dst'], $foes, true) && $mine($a['src']) && $a['type'] === 'DEBUFF') {
                $de[$a['name']][] = $iv;
            }
        }
        foreach ($bu as $k => $iv) {
            $r['buff'][$k] = $len($union($iv));
        }
        foreach ($de as $k => $iv) {
            $r['debuff'][$k] = ['any' => $len($union($iv)), 'sum' => $len($iv)];
        }

        // Each offensive cooldown: was a teammate's pressed with it, and was their healer locked
        // out in the seconds after it?
        $offs = array_filter($tl['commitments'], fn ($c) => in_array($c['kind'], ['offensive', 'mixed'], true));
        foreach ($offs as $c) {
            if ($c['who'] !== $g || $c['t'] > $alive) {
                continue;
            }
            $together = array_filter($offs, fn ($o) => in_array($o['who'], $mates, true) && abs($o['t'] - $c['t']) <= ALIGN);
            $r['off'][] = ['spell' => $c['spell'], 't' => $c['t'], 'together' => (bool) $together,
                'healerLocked' => $foeHealer ? $cross($locked[$foeHealer], [[$c['t'], $c['t'] + AFTER_CD]]) : 0,
                'selfLocked' => $cross($locked[$g], [[$c['t'], $c['t'] + AFTER_CD]])];
        }
        $records[] = $r;

        // The death read, for one named player.
        if ($deathsOf !== null && $name === $deathsOf && isset($deathAt[$g])) {
            $d = $deathAt[$g];
            $note = sprintf("%s %s, MMR %d vs %d, with %s. Died at %.1fs%s\n", $clock, $won ? 'WON' : 'LOST', $mmr, $opp, implode(' + ', $r['mates']), $d,
                $firstDeath['who'] === $g ? ' (first death)' : '');
            $note .= '   health: '.implode(' ', array_map(fn ($b) => sprintf('-%ds %s', $b, ($v = $hpAt($d - $b)) === null ? '?' : round($v).'%'), [20, 15, 12, 10, 8, 6, 5, 4, 3, 2, 1]))."\n";
            $by = [];
            foreach ($dmg as $x) {
                if ($x['dst'] === $g && $x['t'] >= $d - 10 && $x['t'] <= $d + 0.05) {
                    $who = $credit($x['src']);
                    $k = isset($roster[$who]) ? $short($roster[$who]['name']).' ('.$roster[$who]['spec'].')' : 'other';
                    $by[$k] = ($by[$k] ?? 0) + $x['amount'];
                }
            }
            arsort($by);
            $note .= '   damage in, last 10s: '.implode(', ', array_map(fn ($k, $v) => $k.' '.number_format($v / 1000).'k', array_keys($by), $by))."\n";
            $note .= "   defensives and heals this player pressed (time before the death, health when pressed):\n";
            foreach ($casts as $c) {
                if ($c['src'] !== $g || $c['t'] > $d) {
                    continue;
                }
                $kind = null;
                foreach ($tl['commitments'] as $cm) {
                    if ($cm['who'] === $g && abs($cm['t'] - $c['t']) < 0.05 && $cm['spell'] === $c['spell']) {
                        $kind = $cm['kind'];
                    }
                }
                $isSelfCare = $kind === 'defensive' || in_array($c['spell'], ['Regrowth', 'Frenzied Regeneration', 'Bear Form', 'Renewal', 'Exhilaration', 'Feign Death', 'Fortitude of the Bear', "Nature's Vigil", 'Shadowmeld', 'Rejuvenation'], true);
                if ($isSelfCare) {
                    $note .= sprintf("      -%5.1fs  %-26s at %s%s\n", $d - $c['t'], $c['spell'], ($v = $hpAt($c['t'])) === null ? '?' : round($v).'%',
                        $c['dst'] !== $g && isset($roster[$c['dst']]) ? ' on '.$short($roster[$c['dst']]['name']) : '');
                }
            }
            $note .= "   defensives teammates put on this player in the last 30s:\n";
            foreach ($tl['commitments'] as $cm) {
                if (in_array($cm['who'], $mates, true) && $cm['kind'] === 'defensive' && $cm['t'] >= $d - 30 && $cm['t'] <= $d) {
                    $note .= sprintf("      -%5.1fs  %s (%s)\n", $d - $cm['t'], $cm['spell'], $short($roster[$cm['who']]['name']));
                }
            }
            $note .= "   control on this player, last 20s:\n";
            foreach ($tl['control'] as $c) {
                if ($c['on'] === $g && $c['to'] >= $d - 20 && $c['from'] <= $d) {
                    $note .= sprintf("      -%5.1fs to -%4.1fs  %s (%s)\n", $d - $c['from'], max(0, $d - $c['to']), $c['spell'], $c['dr']);
                }
            }
            if ($ownHealer && $ownHealer !== $g) {
                $note .= "   lockout on our healer, last 20s:\n";
                foreach ($tl['control'] as $c) {
                    if ($c['on'] === $ownHealer && in_array($c['dr'], LOCKOUT, true) && $c['to'] >= $d - 20 && $c['from'] <= $d) {
                        $note .= sprintf("      -%5.1fs to -%4.1fs  %s (%s)\n", $d - $c['from'], max(0, $d - $c['to']), $c['spell'], $c['dr']);
                    }
                }
            }
            $note .= "   their offensive cooldowns, last 30s:\n";
            foreach ($tl['commitments'] as $cm) {
                if (in_array($cm['who'], $foes, true) && in_array($cm['kind'], ['offensive', 'mixed'], true) && $cm['t'] >= $d - 30 && $cm['t'] <= $d) {
                    $note .= sprintf("      -%5.1fs  %s (%s)\n", $d - $cm['t'], $cm['spell'], $roster[$cm['who']]['spec']);
                }
            }
            $deathNotes[] = $note;
        }
    }
}

// ---- columns
$pick = function (array $col) use ($records, $named, $with, $cols) {
    return array_values(array_filter($records, function ($r) use ($col, $named, $with, $cols) {
        if ($col['name'] === '*' ? in_array($r['name'], $named, true) : $r['name'] !== $col['name']) {
            return false;
        }
        if ($r['mmr'] < $col['lo'] || $r['mmr'] > $col['hi']) {
            return false;
        }
        if ($col['res'] !== null && $r['won'] !== ($col['res'] === 'W')) {
            return false;
        }

        return ! ($col['name'] === $cols[0]['name'] && $with !== null && ! in_array($with, $r['mates'], true));
    }));
};
$sets = [];
foreach ($cols as $i => $col) {
    $sets[$col['label']] = $pick($col);
}
$minutes = fn (array $set) => max(0.01, array_sum(array_column($set, 'alive')) / 60);
$sumKey = function (array $set, string $k) {
    $out = [];
    foreach ($set as $r) {
        foreach ($r[$k] as $name => $v) {
            $out[$name] = ($out[$name] ?? 0) + (is_array($v) ? ($v['n'] ?? count($v)) : $v);
        }
    }

    return $out;
};
$w = 16;
$head = function (string $title) use ($sets, $w) {
    echo "\n=== {$title}\n";
    printf('   %-34s', '');
    foreach (array_keys($sets) as $lb) {
        printf(" %{$w}s", mb_substr($lb, 0, $w));
    }
    echo "\n";
};
$row = function (string $label, callable $fn, string $fmt = '%.1f') use ($sets, $w) {
    printf('   %-34s', mb_substr($label, 0, 34));
    foreach ($sets as $set) {
        $v = $set ? $fn($set) : null;
        printf(" %{$w}s", $v === null ? '-' : (is_string($v) ? $v : sprintf($fmt, $v)));
    }
    echo "\n";
};
$median = function (array $v) {
    if (! $v) {
        return null;
    }
    sort($v);

    return $v[intdiv(count($v), 2)];
};

echo "SPEC READ — spec {$specId}, {$bracket}, since {$since}\n";
foreach ($sets as $lb => $set) {
    $who = array_count_values(array_column($set, 'name'));
    arsort($who);
    printf("   %-18s %2d games: %s\n", $lb, count($set), implode(', ', array_map(fn ($n, $c) => "$n $c", array_keys($who), $who)));
}

$head('THE SAMPLE');
$row('games', fn ($s) => count($s), '%d');
$row('won - lost', fn ($s) => count(array_filter($s, fn ($r) => $r['won'])).' - '.count(array_filter($s, fn ($r) => $r['won'] === false)));
$row('minutes alive', fn ($s) => $minutes($s));
$row('own team MMR, average', fn ($s) => array_sum(array_column($s, 'mmr')) / count($s), '%d');
$row('their team MMR, average', fn ($s) => array_sum(array_column($s, 'opp')) / count($s), '%d');
$row('own team MMR, range', fn ($s) => min(array_column($s, 'mmr')).'-'.max(array_column($s, 'mmr')));

$head('OUTPUT, per minute alive');
$row('damage onto enemy players (k)', fn ($s) => array_sum(array_column($s, 'dmgTotal')) / $minutes($s) / 1000, '%.0f');
$row('  share on their healer', fn ($s) => 100 * array_sum(array_column($s, 'dmgHealer')) / max(1, array_sum(array_column($s, 'dmgTotal'))), '%.0f%%');
$row('  share on the most-hit target', fn ($s) => 100 * array_sum(array_column($s, 'dmgTop')) / max(1, array_sum(array_column($s, 'dmgTotal'))), '%.0f%%');
$row('damage taken (k)', fn ($s) => array_sum(array_column($s, 'taken')) / $minutes($s) / 1000, '%.0f');
$row('healing on self (k)', fn ($s) => array_sum(array_column($s, 'healSelf')) / $minutes($s) / 1000, '%.0f');
$row('healing on teammates (k)', fn ($s) => array_sum(array_column($s, 'healMates')) / $minutes($s) / 1000, '%.0f');
$row('casts', fn ($s) => array_sum(array_map(fn ($r) => array_sum($r['casts']), $s)) / $minutes($s));
$row('not pressing (gaps over '.GAP.'s, not CC\'d)', fn ($s) => 100 * array_sum(array_column($s, 'idle')) / ($minutes($s) * 60), '%.0f%%');
$row('locked out (stun/silence/disorient/incap)', fn ($s) => 100 * array_sum(array_column($s, 'lockedSec')) / ($minutes($s) * 60), '%.0f%%');

$head('CONTROL AND KICKS');
$row('their healer locked by this player', fn ($s) => 100 * array_sum(array_column($s, 'ccHealerSec')) / ($minutes($s) * 60), '%.0f%%');
$row('their DPS locked by this player (s/min)', fn ($s) => array_sum(array_column($s, 'ccDpsSec')) / $minutes($s));
$row('kicks landed per game', fn ($s) => array_sum(array_column($s, 'kicks')) / count($s), '%.2f');
$row('  on their healer', fn ($s) => array_sum(array_column($s, 'kicksHealer')) / count($s), '%.2f');
$row('own casts kicked per game', fn ($s) => array_sum(array_column($s, 'kicked')) / count($s), '%.2f');
$ccNames = [];
foreach ($sets as $set) {
    foreach ($set as $r) {
        foreach ($r['cc'] as $k => $v) {
            $ccNames[$k] = ($ccNames[$k] ?? 0) + $v['n'];
        }
    }
}
arsort($ccNames);
echo "   --- control landed, per 10 minutes alive (average seconds each)\n";
foreach (array_slice(array_keys($ccNames), 0, 24) as $k) {
    $row('  '.$k, function ($s) use ($k, $minutes) {
        $n = array_sum(array_map(fn ($r) => $r['cc'][$k]['n'] ?? 0, $s));
        $sec = array_sum(array_map(fn ($r) => $r['cc'][$k]['s'] ?? 0, $s));

        return $n ? sprintf('%.1f (%.1fs)', 10 * $n / $minutes($s), $sec / $n) : '0';
    });
}

$head('DEATHS');
$row('games this player died in', fn ($s) => count(array_filter($s, fn ($r) => $r['died'])).' of '.count($s));
$row('  was the first death', fn ($s) => count(array_filter($s, fn ($r) => $r['diedFirst'])), '%d');
$row('share of their team\'s first deaths', fn ($s) => ($n = count(array_filter($s, fn ($r) => $r['teamLostADeath']))) ? sprintf('%d of %d', count(array_filter($s, fn ($r) => $r['diedFirst'])), $n) : '-');

$head('OFFENSIVE COOLDOWNS: pressed with a teammate\'s, and their healer in the '.AFTER_CD.'s after');
$row('offensive cooldowns per game', fn ($s) => array_sum(array_map(fn ($r) => count($r['off']), $s)) / count($s));
$offAll = fn ($s) => array_merge(...array_map(fn ($r) => $r['off'], $s) ?: [[]]);
$row('  a teammate\'s within '.ALIGN.'s', fn ($s) => ($o = $offAll($s)) ? 100 * count(array_filter($o, fn ($x) => $x['together'])) / count($o) : null, '%.0f%%');
$row('  their healer locked 2s+ after it', fn ($s) => ($o = $offAll($s)) ? 100 * count(array_filter($o, fn ($x) => $x['healerLocked'] >= 2)) / count($o) : null, '%.0f%%');
$row('  their healer locked, seconds', fn ($s) => ($o = $offAll($s)) ? array_sum(array_column($o, 'healerLocked')) / count($o) : null);
$row('  self locked out 1s+ after it', fn ($s) => ($o = $offAll($s)) ? 100 * count(array_filter($o, fn ($x) => $x['selfLocked'] >= 1)) / count($o) : null, '%.0f%%');

$head('COOLDOWNS (20s+): uses per game | first press, median seconds | median gap between presses');
$cdNames = [];
foreach ($sets as $set) {
    foreach ($set as $r) {
        foreach ($r['cd'] as $k => $ts) {
            $cdNames[$k] = ($cdNames[$k] ?? 0) + count($ts);
        }
    }
}
arsort($cdNames);
foreach (array_slice(array_keys($cdNames), 0, 26) as $k) {
    $row($k, function ($s) use ($k, $median) {
        $n = 0;
        $first = [];
        $gaps = [];
        foreach ($s as $r) {
            $ts = $r['cd'][$k] ?? [];
            $n += count($ts);
            if ($ts) {
                $first[] = $ts[0];
            }
            for ($i = 1; $i < count($ts); $i++) {
                $gaps[] = $ts[$i] - $ts[$i - 1];
            }
        }

        return $n ? sprintf('%.1f|%s|%s', $n / count($s), round($median($first)), $gaps ? round($median($gaps)) : '-') : '0';
    });
}

$head('DEFENSIVES AND SELF-HEALS: presses per game | median health when pressed | pressed at 50% or lower');
$hpNames = [];
foreach ($sets as $set) {
    foreach ($set as $r) {
        foreach ($r['hpAt'] as $k => $v) {
            $hpNames[$k] = ($hpNames[$k] ?? 0) + count($v);
        }
    }
}
arsort($hpNames);
foreach (array_slice(array_keys($hpNames), 0, 16) as $k) {
    $row($k, function ($s) use ($k, $median) {
        $v = array_merge(...array_map(fn ($r) => $r['hpAt'][$k] ?? [], $s) ?: [[]]);
        $n = array_sum(array_map(fn ($r) => $r['casts'][$k] ?? 0, $s));

        return $v ? sprintf('%.1f|%d%%|%d%%', $n / count($s), $median($v), 100 * count(array_filter($v, fn ($x) => $x <= 50)) / count($v)) : ($n ? sprintf('%.1f|?|?', $n / count($s)) : '0');
    });
}

$head('CASTS PER MINUTE ALIVE');
$castNames = [];
foreach ($sets as $lb => $set) {
    foreach ($sumKey($set, 'casts') as $k => $n) {
        $castNames[$k] = max($castNames[$k] ?? 0, $n / $minutes($set));
    }
}
arsort($castNames);
foreach (array_slice(array_keys($castNames), 0, 45) as $k) {
    $row($k, fn ($s) => array_sum(array_map(fn ($r) => $r['casts'][$k] ?? 0, $s)) / $minutes($s), '%.2f');
}

$head('DAMAGE BY ABILITY: share of own damage (k per minute)');
$dmgNames = [];
foreach ($sets as $set) {
    foreach ($sumKey($set, 'dmg') as $k => $n) {
        $dmgNames[$k] = max($dmgNames[$k] ?? 0, $n / $minutes($set));
    }
}
arsort($dmgNames);
foreach (array_slice(array_keys($dmgNames), 0, 22) as $k) {
    $row($k, function ($s) use ($k, $minutes) {
        $v = array_sum(array_map(fn ($r) => $r['dmg'][$k] ?? 0, $s));

        return sprintf('%.1f%% (%.0f)', 100 * $v / max(1, array_sum(array_column($s, 'dmgTotal'))), $v / $minutes($s) / 1000);
    });
}

$head('HEALING BY ABILITY, k per minute');
$healNames = [];
foreach ($sets as $set) {
    foreach ($sumKey($set, 'heal') as $k => $n) {
        $healNames[$k] = max($healNames[$k] ?? 0, $n / $minutes($set));
    }
}
arsort($healNames);
foreach (array_slice(array_keys($healNames), 0, 10) as $k) {
    $row($k, fn ($s) => array_sum(array_map(fn ($r) => $r['heal'][$k] ?? 0, $s)) / $minutes($s) / 1000, '%.0f');
}

$head('OWN BUFFS: share of time alive');
$buffNames = [];
foreach ($sets as $set) {
    foreach ($sumKey($set, 'buff') as $k => $n) {
        $buffNames[$k] = max($buffNames[$k] ?? 0, $n / ($minutes($set) * 60));
    }
}
arsort($buffNames);
foreach (array_slice(array_keys(array_filter($buffNames, fn ($v) => $v >= 0.02)), 0, 40) as $k) {
    $row($k, fn ($s) => 100 * array_sum(array_map(fn ($r) => $r['buff'][$k] ?? 0, $s)) / ($minutes($s) * 60), '%.0f%%');
}

$head('OWN DEBUFFS ON ENEMY PLAYERS: share of time at least one enemy has it (average number of enemies with it)');
$debNames = [];
foreach ($sets as $set) {
    foreach ($set as $r) {
        foreach ($r['debuff'] as $k => $v) {
            $debNames[$k] = ($debNames[$k] ?? 0) + $v['any'];
        }
    }
}
arsort($debNames);
foreach (array_slice(array_keys($debNames), 0, 22) as $k) {
    $row($k, function ($s) use ($k, $minutes) {
        $any = array_sum(array_map(fn ($r) => $r['debuff'][$k]['any'] ?? 0, $s));
        $sum = array_sum(array_map(fn ($r) => $r['debuff'][$k]['sum'] ?? 0, $s));

        return $any > 0 ? sprintf('%.0f%% (%.2f)', 100 * $any / ($minutes($s) * 60), $sum / ($minutes($s) * 60)) : '0';
    });
}

$head('RESOURCE WHEN PRESSED: median share of the bar | share of presses at 90%+ of the bar');
foreach (array_slice(array_keys($castNames), 0, 14) as $k) {
    $row($k, function ($s) use ($k, $median) {
        $v = array_merge(...array_map(fn ($r) => $r['power'][$k] ?? [], $s) ?: [[]]);
        if (count($v) < 5) {
            return '-';
        }
        $share = array_column($v, 1);

        return sprintf('%s %d%%|%d%%', POWER[$v[0][0]], 100 * $median($share), 100 * count(array_filter($share, fn ($x) => $x >= 0.9)) / count($share));
    });
}

if ($deathNotes) {
    echo "\n=== EVERY DEATH OF {$deathsOf}\n".implode("\n", $deathNotes);
}
