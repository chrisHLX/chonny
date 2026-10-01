<?php

// Was it warranted? The kill read (killread.php) plus, for every game: each Medallion of ours, what it
// broke and what our other players were taking in the seconds of CC it saved; each overlap of our
// defensives, the target's health, incoming damage (absorbs included), time to live at that rate and
// the physical share. The questions behind it: match-review-analysis.md, "Was the trinket warranted".
//   php -d memory_limit=1G warrant.php [--only=HH:MM,...]
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\ArenaMomentService;

const ARCHIVE = 'D:/MindCollector/arena-logs';
const ME = 'Skylake';
const GO_GAP = 10.0;      // offensive casts closer than this are one go
const GO_TAIL = 15.0;     // a go's window runs this long past its last offensive cast
const KILL_LOOKBACK = 30.0;
const DMG_LOOKBACK = 10.0;
const TIGHT_LINK = 5.0;   // links this close (from the chain's end) make a GOOD go
const LOOSE_LINK = 10.0;  // links up to this far still join, but only through an offensive cooldown: a BAD go
const OFFENSIVE_HOLD = 5.0; // an offensive cast counts as 'ending' this long after it, for linking
const PEAK_SECONDS = 6.0; // the burst window: the 6s of a go with the most damage from its DPS
const RESPONSE = 5.0;     // a defensive this soon after a CC is an answer to it
const KILL_LATER = 30.0;  // a go 'converts' if a kill lands in its window or this long after it
const HIGH_XP = 10;
const SPEC_NAMES = ['262' => 'Elemental', '270' => 'Mistweaver', '71' => 'Arms', '1468' => 'Preservation', '269' => 'Windwalker',
    '63' => 'Fire Mage', '265' => 'Affliction', '64' => 'Frost Mage', '65' => 'Holy Paladin', '105' => 'Resto Druid',
    '259' => 'Assassination', '256' => 'Disc Priest', '577' => 'Havoc', '70' => 'Ret', '255' => 'Survival', '258' => 'Shadow Priest',
    '252' => 'Unholy DK', '253' => 'BM Hunter', '264' => 'Resto Shaman', '103' => 'Feral', '72' => 'Fury', '257' => 'Holy Priest'];
// The spec name alone is ambiguous ("Restoration" is a Druid and a Shaman).
const HEALER_NAMES = ['105' => 'Resto Druid', '264' => 'Resto Shaman', '65' => 'Holy Paladin', '257' => 'Holy Priest',
    '256' => 'Discipline', '270' => 'Mistweaver', '1468' => 'Preservation'];
const RACIALS = ['Stoneform', 'Fireblood', 'Will of the Forsaken', 'Gift of the Naaru', 'Shadowmeld', 'Arcane Torrent',
    'War Stomp', 'Quaking Palm', 'Blood Fury', 'Berserking', 'Darkflight', 'Rocket Barrage', 'Bag of Tricks', 'Haymaker',
    'Ancestral Call', 'Escape Artist', 'Every Man for Himself', "Light's Judgment", 'Arcane Pulse', 'Spatial Rift',
    "Regeneratin'", 'Hyper Organic Light Originator', 'Wing Buffet', 'Azerite Surge', 'Sharpen Blade'];       // enemy team Gladiator seasons at or above this = experienced
const CLOCK_SHIFT = 0; // PHP here already renders the in-game clock used in the doc
const LOCKOUT = ['Stun', 'Silence', 'Disorient', 'Incapacitate'];
const DANGER_TTL = 5.0; // seconds to live at the 3s-before rate: at or under this, a defensive was needed
const DANGER_HP = 35.0; // or health at or under this
const PREVENTED_SOLID = 20.0;  // a defensive that removed this % of max health or more did real work
const PREVENTED_LITTLE = 8.0;  // under this, the damage it was pressed for did not come
const TAIL = 3.0;              // their cooldowns with this little left (or none up): the burst was ending
// What each of our defensives removes, from the spells' own effects (current patch): Pain
// Suppression 33206 and Icebound Fortitude 48792 'Modify Damage Taken%' -40/-30, all schools;
// Fortifying Brew 115203 -20; Anti-Magic Zone 145629 -15, magic only. Anti-Magic Shell is an absorb,
// read from its own SPELL_ABSORBED lines. Lichborne and Wraith Walk remove no damage.
const DEFENSIVE_EFFECT = [
    'Pain Suppression' => ['type' => 'pct', 'pct' => 0.40, 'magicOnly' => false],
    'Icebound Fortitude' => ['type' => 'pct', 'pct' => 0.30, 'magicOnly' => false],
    'Fortifying Brew' => ['type' => 'pct', 'pct' => 0.20, 'magicOnly' => false],
    'Anti-Magic Zone' => ['type' => 'pct', 'pct' => 0.15, 'magicOnly' => true],
    'Anti-Magic Shell' => ['type' => 'absorb'],
    // Lichborne 49039 is 6% Leech and no reduction; Vampiric Aura (San'layn, 434100) raises the
    // Leech while it is up. Measured from the logged Leech heals, not a percentage.
    'Lichborne' => ['type' => 'leech'],
];

$detail = array_values(array_filter(array_slice($argv, 1), fn ($a) => $a !== '--strict'));
define('STRICT', in_array('--strict', $argv, true));
// --only=19:26,19:47,... restricts every measure to those games (e.g. one level of play).
$only = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = explode(',', substr($a, 7));
    }
}
$detail = array_values(array_filter($detail, fn ($a) => ! str_starts_with($a, '--only=')));
$svc = app(ArenaMomentService::class);
$ref = new ReflectionClass($svc);
$rosterFn = $ref->getMethod('roster');
$timelineFn = $ref->getMethod('readTimeline');
$ccMap = $ref->getMethod('crowdControl')->invoke($svc);
// How long each defensive lasts, for bait detection. Null = instant or unknown (Medallion).
$durations = App\Models\Spell::query()
    ->where('patch_id', App\Models\Patch::where('is_current', true)->value('id'))
    ->whereNotNull('duration_seconds')->pluck('duration_seconds', 'spell_id')->all();
$baits = [];
$defRows = [];
// The CC a spell's own effects make its target immune to (Mechanic Immunity effects), per external id.
$immunityCache = [];
$immunityOf = function (int $spellId) use (&$immunityCache) {
    return $immunityCache[$spellId] ??= App\Models\Spell::with('effects')
        ->where('patch_id', App\Models\Patch::where('is_current', true)->value('id'))
        ->where('spell_id', $spellId)->get()
        ->flatMap(fn ($s) => app(App\Http\Services\ModuleSpellReferenceService::class)->ccImmunityGrantedBy($s))
        ->unique()->values()->all();
};
$census = [];
$racials = [];
$xpFile = __DIR__.'/experience.json';
$xp = is_file($xpFile) ? json_decode(file_get_contents($xpFile), true) : [];

$games = [];
$all = [];
foreach (glob(ARCHIVE.'/metadata/*.json') as $file) {
    $m = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file)), true);
    if (($m['startInfo']['bracket'] ?? null) !== '3v3') {
        continue;
    }
    $names = array_column(array_filter($m['units'], fn ($u) => str_starts_with($u['id'], 'Player-')), 'name');
    if (! preg_grep('/^Hozzaarr-/', $names) || ! preg_grep('/^'.ME.'-/', $names)) {
        continue;
    }
    $games[] = $m;
}
usort($games, fn ($a, $b) => $a['startTime'] <=> $b['startTime']);

$seconds = function (string $line): ?float {
    if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
        return null;
    }

    return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
};

$overlap = fn ($a0, $a1, $b0, $b1) => max(0, min($a1, $b1) - max($a0, $b0));
$short = fn ($name) => explode('-', $name)[0];

foreach ($games as $m) {
    $clock = date('H:i', intdiv($m['startTime'], 1000) + CLOCK_SHIFT);
    if ($only !== null && ! in_array($clock, $only, true)) {
        continue;
    }
    $lines = gzfile(ARCHIVE."/raw/{$m['id']}.log.gz");
    $lines = array_map('rtrim', $lines);
    $roster = $rosterFn->invoke($svc, $m);
    $tl = $timelineFn->invoke($svc, $lines, $roster);

    $meId = array_key_first(array_filter($roster, fn ($r) => str_starts_with($r['name'], ME.'-')));
    $us = $roster[$meId]['side'];
    $sideOf = fn ($guid) => isset($roster[$guid]) ? ($roster[$guid]['side'] === $us ? 'us' : 'them') : null;
    $specIdOf = array_column($m['units'], 'spec', 'id');
    $label = fn ($guid) => HEALER_NAMES[$specIdOf[$guid] ?? ''] ?? $roster[$guid]['spec'];
    $healers = [];
    foreach ($roster as $g => $r) {
        if ($r['healer']) {
            $healers[$sideOf($g)] = $g;
        }
    }

    // Second pass: damage with spell names, pet owners, the same t0 readTimeline uses.
    $t0 = null;
    $hp = [];
    $maxHp = [];
    $debuffOff = [];
    $debOpen = [];
    $immune = [];
    $casts = [];
    $buffSrc = [];
    $owner = [];
    $dmg = [];
    $heal = [];
    $buffs = [];      // every BUFF aura on a roster player: [name, on, from, to]
    $openBuff = [];
    $interrupts = []; // SPELL_INTERRUPT: [t, src, dst, spell, interrupted]
    foreach ($lines as $line) {
        $s = $seconds($line);
        if ($s === null) {
            continue;
        }
        $t0 ??= $s;
        $body = explode('  ', $line, 2)[1] ?? '';
        $event = strtok($body, ',');
        if ($event === 'SPELL_MISSED') {
            $f = str_getcsv($body);
            if (isset($roster[$f[5]]) && ($f[12] ?? '') === 'IMMUNE') {
                $immune[] = ['t' => round($s - $t0, 2), 'dst' => $f[5], 'spellId' => (int) $f[9], 'spell' => $f[10]];
            }

            continue;
        }
        if ($event === 'SPELL_INTERRUPT') {
            $f = str_getcsv($body);
            if (isset($roster[$f[5]])) {
                $interrupts[] = ['t' => round($s - $t0, 2), 'src' => $f[1], 'dst' => $f[5], 'spell' => $f[10], 'interrupted' => $f[count($f) - 2] ?? ''];
            }

            continue;
        }
        if (($event === 'SPELL_AURA_REMOVED' || $event === 'SPELL_AURA_APPLIED') && str_contains($body, ',DEBUFF')) {
            $f = str_getcsv($body);
            if (isset($roster[$f[5]])) {
                $kk = $f[5].'|'.$f[10];
                if ($event === 'SPELL_AURA_APPLIED') {
                    $debOpen[$kk] = round($s - $t0, 2);
                } else {
                    $debuffOff[] = ['t' => round($s - $t0, 2), 'dst' => $f[5], 'spell' => $f[10], 'from' => $debOpen[$kk] ?? null];
                    unset($debOpen[$kk]);
                }
            }
        }
        if (($event === 'SPELL_AURA_APPLIED' || $event === 'SPELL_AURA_REMOVED') && str_contains($body, ',BUFF')) {
            $f = str_getcsv($body);
            if (isset($roster[$f[5]])) {
                $k = $f[5].'|'.$f[10];
                if ($event === 'SPELL_AURA_APPLIED') {
                    $openBuff[$k] = round($s - $t0, 2);
                    $buffSrc[$k] = $f[1];
                } elseif (isset($openBuff[$k])) {
                    $buffs[] = ['name' => $f[10], 'on' => $f[5], 'src' => $buffSrc[$k] ?? null, 'from' => $openBuff[$k], 'to' => round($s - $t0, 2)];
                    unset($openBuff[$k]);
                }
            }

            continue;
        }
        if ($event === 'SPELL_SUMMON') {
            $f = str_getcsv($body);
            $owner[$f[5]] = $f[1];

            continue;
        }
        if ($event === 'SPELL_CAST_SUCCESS') {
            $f = str_getcsv($body);
            if (isset($roster[$f[1]])) {
                $casts[] = ['t' => round($s - $t0, 2), 'src' => $f[1], 'dst' => $f[5], 'spell' => $f[10]];
            }
            if (! str_starts_with($f[1], 'Player-') && str_starts_with($f[13] ?? '', 'Player-')) {
                $owner[$f[1]] ??= $f[13];
            }

            continue;
        }
        // Healing: effective = amount - overheal (offsets from the END, see "What the combat log
        // actually says"). Absorbs credited to the shield's caster. `hot` marks periodic healing.
        if ($event === 'SPELL_HEAL' || $event === 'SPELL_PERIODIC_HEAL') {
            $f = str_getcsv($body);
            $n = count($f);
            if (isset($roster[$f[5]]) && ($f[12] ?? null) === $f[5] && is_numeric($f[14] ?? null) && (int) ($f[15] ?? 0) > 0) {
                $hp[$f[5]][] = [round($s - $t0, 2), round(100 * (int) $f[14] / (int) $f[15], 1)];
            }
            if (isset($roster[$f[5]]) && is_numeric($f[$n - 5] ?? null) && is_numeric($f[$n - 3] ?? null)) {
                $heal[] = ['t' => round($s - $t0, 2), 'src' => $f[1], 'dst' => $f[5],
                    'amount' => max(0, (int) $f[$n - 5] - (int) $f[$n - 3]), 'hot' => $event === 'SPELL_PERIODIC_HEAL', 'spell' => $f[10]];
            }

            continue;
        }
        if ($event === 'SPELL_ABSORBED') {
            $f = str_getcsv($body);
            $n = count($f);
            if (isset($roster[$f[5]]) && is_numeric($f[$n - 3] ?? null)) {
                $heal[] = ['t' => round($s - $t0, 2), 'src' => $f[$n - 10], 'dst' => $f[5],
                    'amount' => (int) $f[$n - 3], 'hot' => false, 'spell' => 'absorb: '.$f[$n - 5]];
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
        $base = $swing ? 9 : 12;
        $cur = $f[$base + 2] ?? null;
        $max = $f[$base + 3] ?? null;
        if (is_numeric($cur) && is_numeric($max) && $max > 0 && ($f[$base] ?? null) === $f[5]) {
            $hp[$f[5]][] = [round($s - $t0, 2), round(100 * $cur / $max, 1)];
            $maxHp[$f[5]] = (int) $max;
        }
        $dmg[] = [
            't' => round($s - $t0, 2), 'src' => $f[1], 'dst' => $f[5], 'amount' => (int) $amount,
            'spell' => $swing ? 'Melee' : $f[10],
            'phys' => $swing || (hexdec(str_replace('0x', '', $f[11] ?? '0')) === 1),
            'hpAfter' => is_numeric($cur) && is_numeric($max) && $max > 0 ? round(100 * $cur / $max, 1) : null,
        ];
    }
    $credit = function ($src) use ($roster, $owner) {
        $seen = 0;
        while (! isset($roster[$src]) && isset($owner[$src]) && $seen++ < 3) {
            $src = $owner[$src];
        }

        return $src;
    };

    // ---- intervals
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
    $cross = function (array $a, array $b) use ($overlap) {
        $s = 0;
        foreach ($a as $x) {
            foreach ($b as $y) {
                $s += $overlap($x[0], $x[1], $y[0], $y[1]);
            }
        }

        return $s;
    };

    // Lockout per player: stun, silence, disorient, incapacitate. Merged, so a stun under a
    // silence counts once.
    $locked = [];
    foreach ($roster as $g => $r) {
        $locked[$g] = $union(array_map(fn ($c) => [$c['from'], $c['to']],
            array_filter($tl['control'], fn ($c) => $c['on'] === $g && in_array($c['dr'], LOCKOUT, true))));
    }

    // ---- categories. Every 45s+ cast is exactly one of: offensive, mixed, defensive (the
    // hand-reviewed labels), control (has a dr_category), or utility (everything else). A go is
    // built from offensive and mixed casts ONLY; control and utility inside its window are
    // recorded per side, because they decide what the go achieves.
    foreach ($tl['commitments'] as &$c) {
        $c['cat'] = in_array($c['kind'], ['offensive', 'mixed', 'defensive'], true) ? $c['kind']
            : (isset($ccMap[$c['spellId']]) ? 'control' : 'utility');
        if ($c['cat'] === 'utility') {
            $census[$c['spell']] = ($census[$c['spell']] ?? 0) + 1;
        }
    }
    unset($c);
    foreach ($tl['commitments'] as $c) {
        if (in_array($c['spell'], RACIALS, true)) {
            $racials[$c['spell'].' ('.$roster[$c['who']]['spec'].', '.$sideOf($c['who']).')'][] = $clock;
        }
    }
    $goCasts = [];
    $goes = ['us' => [], 'them' => []];
    foreach (['us', 'them'] as $side) {
        $goCasts[$side] = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $side
            && in_array($c['cat'], ['offensive', 'mixed'], true)));
    }
    // THE GO (Chriso, 2026-09-28): a chain of events meant to force resources or get a kill.
    // Built as a CHAIN: offensive casts and the CC that actually LANDED on the enemy (stun,
    // silence, disorient, incapacitate auras, whatever the spell's cooldown), in time order. A link
    // joins if it starts within TIGHT_LINK of the chain's END so far (a CC ends when its aura
    // ends; an offensive cast "ends" OFFENSIVE_HOLD after it). A link up to LOOSE_LINK away still
    // joins, but ONLY through an offensive cooldown: the new link is one, or the chain's end was
    // set by one. CC-to-CC never links loosely. A chain is a go only if it holds an offensive
    // cooldown. A go whose every link is within TIGHT_LINK is GOOD execution; one that needed a
    // loose link is BAD execution of a real go. CC links are labelled by who they landed on: the
    // enemy healer, the go's target (the enemy we damaged most in its window), or CROSS CC (anyone
    // else). Utilities are never links. (--strict: offensive casts only.)
    $goes = ['us' => [], 'them' => []];
    foreach (['us', 'them'] as $side) {
        $events = array_map(fn ($c) => ['t' => $c['t'], 'end' => $c['t'] + OFFENSIVE_HOLD, 'spell' => $c['spell'], 'cat' => 'offensive', 'on' => null], $goCasts[$side]);
        if (! STRICT) {
            foreach ($tl['control'] as $c) {
                $by = $credit($c['by']);
                if (! in_array($c['dr'], LOCKOUT, true) || $sideOf($c['on']) === $side || ($sideOf($by) ?? null) !== $side) {
                    continue;
                }
                $events[] = ['t' => $c['from'], 'end' => $c['to'], 'cat' => 'control', 'spell' => $c['spell'], 'on' => $c['on']];
            }
        }
        usort($events, fn ($x, $y) => $x['t'] <=> $y['t']);
        $chains = [];
        $cur = [];
        $end = -INF;
        $endByOffensive = false;
        foreach ($events as $e) {
            $gap = $e['t'] - $end;
            $loose = $e['cat'] === 'offensive' || $endByOffensive;
            if ($cur && ($gap > LOOSE_LINK || ($gap > TIGHT_LINK && ! $loose))) {
                $chains[] = $cur;
                $cur = [];
                $end = -INF;
                $endByOffensive = false;
            }
            $e['gap'] = $cur ? round(max(0, $e['t'] - $end), 1) : 0.0;
            $cur[] = $e;
            if ($e['end'] >= $end) {
                $end = $e['end'];
                $endByOffensive = $e['cat'] === 'offensive';
            }
        }
        if ($cur) {
            $chains[] = $cur;
        }
        $other = $side === 'us' ? 'them' : 'us';
        foreach ($chains as $ch) {
            $offs = array_values(array_filter($ch, fn ($e) => $e['cat'] === 'offensive'));
            if (! $offs) {
                continue;
            }
            $from = $ch[0]['t'];
            $to = max(end($offs)['t'] + GO_TAIL, max(array_column($ch, 'end')));
            // The go's target: the enemy this side damaged most inside the window.
            $hit = [];
            foreach ($dmg as $x) {
                if ($x['t'] >= $from && $x['t'] <= $to && $sideOf($x['dst']) === $other && $sideOf($credit($x['src'])) === $side) {
                    $hit[$x['dst']] = ($hit[$x['dst']] ?? 0) + $x['amount'];
                }
            }
            arsort($hit);
            $target = array_key_first($hit);
            foreach ($ch as &$e) {
                if ($e['cat'] === 'control') {
                    $e['role'] = ($healers[$other] ?? null) === $e['on'] ? 'healer' : ($e['on'] === $target ? 'target' : 'cross');
                    $e['spell'] .= ' > '.$e['role'];
                }
            }
            unset($e);
            $maxGap = max(array_column($ch, 'gap'));
            $goes[$side][] = [
                'from' => $from,
                'to' => $to,
                'casts' => $ch,
                'target' => $target ? $short($roster[$target]['name']) : null,
                'maxGap' => $maxGap,
                'quality' => $maxGap <= TIGHT_LINK ? 'good' : 'bad',
                'chain' => implode('', array_map(fn ($e) => ($e['gap'] > 0 ? " -{$e['gap']}s-> " : ($e === $ch[0] ? '' : ', '))
                    .$e['spell'].($e['cat'] === 'control' ? '*' : ''), $ch)),
            ];
        }
    }
    $goTime = [];
    foreach ($goes as $side => $list) {
        $goTime[$side] = $union(array_map(fn ($go) => [$go['from'], $go['to']], $list));
    }

    $deaths = array_map(fn ($d) => $d + ['side' => $sideOf($d['who'])], $tl['deaths']);
    $won = $m['result'] === 3;
    $dps = fn ($side) => array_keys(array_filter($roster, fn ($r, $g) => $sideOf($g) === $side && ! $r['healer'], ARRAY_FILTER_USE_BOTH));

    $goRows = [];
    foreach ($goes as $side => $list) {
        $other = $side === 'us' ? 'them' : 'us';
        foreach ($list as $go) {
            $from = $go['from'];
            $to = $go['to'];
            $defs = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $other
                && $c['kind'] === 'defensive' && $c['t'] >= $from && $c['t'] <= $to));
            $kill = array_values(array_filter($deaths, fn ($d) => $d['side'] === $other && $d['t'] >= $from && $d['t'] <= $to));
            $w = [[$from, $to]];
            $inWin = fn ($who, $cat) => array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $who
                && $c['cat'] === $cat && $c['t'] >= $from && $c['t'] <= $to));
            $later = array_values(array_filter($deaths, fn ($d) => $d['side'] === $other && $d['t'] >= $from && $d['t'] <= $to + KILL_LATER));
            $dh = $healers[$other] ?? null;
            $hh = $dh ? array_filter($heal, fn ($x) => $credit($x['src']) === $dh && $x['t'] >= $from && $x['t'] <= $to) : [];
            $hTotal = array_sum(array_column($hh, 'amount'));
            $hHot = array_sum(array_column(array_filter($hh, fn ($x) => $x['hot']), 'amount'));
            // Drain: their defensives cast BEFORE this go and still on cooldown when it starts.
            $drained = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $other
                && $c['cat'] === 'defensive' && $c['t'] < $from && $from < $c['t'] + $c['cooldown']));
            $ourDmg = array_sum(array_column(array_filter($dmg, fn ($x) => $sideOf($x['dst']) === $other
                && $sideOf($credit($x['src'])) === $side && $x['t'] >= $from && $x['t'] <= $to), 'amount'));
            $specTag = fn ($c) => $c['spell'].' ('.($roster[$c['who']]['healer'] ? $label($c['who']) : $roster[$c['who']]['spec']).')';
            // How the DEFENDING side answered with CC: lockout auras it landed on the attackers
            // inside the window, and the share of the window the attacking DPS / healer spent locked.
            $ansCc = array_filter($tl['control'], fn ($c) => in_array($c['dr'], LOCKOUT, true) && $sideOf($c['on']) === $side
                && ($sideOf($credit($c['by'])) ?? null) === $other && $c['from'] >= $from && $c['from'] <= $to);
            $atkHealerId = $healers[$side] ?? null;
            $atkDps = $dps($side);
            $winLen = max(1, $to - $from);
            // TIMING: was the attacking healer locked out as the go's cooldowns went off (from 1s
            // before the first offensive cast to 4s after), and how much CC hit the attacking DPS
            // in that same stretch? 19:54 turned on this: Scatter Shot on our healer 11.8-14.8s
            // while Army, Zenith and Dark Transformation went at 13.5-13.8s.
            $tOff = null;
            foreach ($go['casts'] as $e) {
                if ($e['cat'] === 'offensive') {
                    $tOff = $e['t'];
                    break;
                }
            }
            $burst = [[$tOff - 1, $tOff + 4]];
            // PEAK BURST: the 6s inside the go with the most damage from the attacking DPS (pets
            // credited), what landed in it, and whether the defending healer was locked out or
            // kicked during it. The question (Chriso, 2026-09-28): do the DK and Monk land their
            // damage abilities close together, on top of CC or a kick on their healer?
            $burstHits = array_values(array_filter($dmg, function ($x) use ($credit, $sideOf, $roster, $side, $other, $from, $to) {
                $who = $credit($x['src']);

                return $x['t'] >= $from && $x['t'] <= $to && $sideOf($x['dst']) === $other
                    && $sideOf($who) === $side && isset($roster[$who]) && ! $roster[$who]['healer'];
            }));
            usort($burstHits, fn ($a, $b) => $a['t'] <=> $b['t']);
            $peak = ['sum' => 0, 'from' => $from];
            $j = 0;
            $run = 0;
            for ($i = 0; $i < count($burstHits); $i++) {
                $run += $burstHits[$i]['amount'];
                while ($burstHits[$i]['t'] - $burstHits[$j]['t'] > PEAK_SECONDS) {
                    $run -= $burstHits[$j]['amount'];
                    $j++;
                }
                if ($run > $peak['sum']) {
                    $peak = ['sum' => $run, 'from' => $burstHits[$j]['t']];
                }
            }
            $peakWin = [[$peak['from'], $peak['from'] + PEAK_SECONDS]];
            $peakAbilities = [];
            foreach ($burstHits as $x) {
                if ($x['t'] >= $peak['from'] && $x['t'] <= $peak['from'] + PEAK_SECONDS) {
                    $k = $short($roster[$credit($x['src'])]['name']).': '.$x['spell'];
                    $peakAbilities[$k] = ($peakAbilities[$k] ?? 0) + $x['amount'];
                }
            }
            arsort($peakAbilities);
            // Each DPS player's share of the peak: a JOINT peak is one where both did 25%+.
            $peakBy = [];
            foreach ($peakAbilities as $k => $v) {
                $pl = explode(': ', $k)[0];
                $peakBy[$pl] = ($peakBy[$pl] ?? 0) + $v;
            }
            $peakJoint = count($peakBy) >= 2 && min($peakBy) / max(1, array_sum($peakBy)) >= 0.25;
            // The same peak without Touch of Death: a 740k finish on a target at 3-9% is not burst.
            $noTod = array_sum(array_filter($peakAbilities, fn ($v, $k) => ! str_ends_with($k, 'Touch of Death'), ARRAY_FILTER_USE_BOTH));
            $defHealer = $healers[$other] ?? null;
            $peakKicks = array_filter($interrupts, fn ($x) => $x['t'] >= $peak['from'] && $x['t'] <= $peak['from'] + PEAK_SECONDS
                && $sideOf($credit($x['src'])) === $side);
            $goRows[] = [
                'peakDamage' => $peak['sum'],
                'peakJoint' => $peakJoint,
                'peakNoTod' => $noTod,
                'peakFrom' => round($peak['from'] - $from, 1),
                'peakAbilities' => $peakAbilities,
                'peakGoShare' => array_sum(array_column($burstHits, 'amount')) > 0 ? $peak['sum'] / array_sum(array_column($burstHits, 'amount')) : 0,
                'peakHealerLocked' => $defHealer ? round($cross($locked[$defHealer], $peakWin), 1) : 0,
                'peakHealerKicked' => count(array_filter($peakKicks, fn ($x) => $x['dst'] === $defHealer)),
                'peakKicks' => count($peakKicks),
                'healerLockedAtCds' => $atkHealerId ? $cross($locked[$atkHealerId], $burst) > 0 : false,
                'dpsLockedAtCds' => array_sum(array_map(fn ($pl) => $cross($locked[$pl], $burst), $atkDps)),
                'ansCcDps' => count(array_filter($ansCc, fn ($c) => $c['on'] !== $atkHealerId)),
                'ansCcHealer' => count(array_filter($ansCc, fn ($c) => $c['on'] === $atkHealerId)),
                'dpsLockedShare' => array_sum(array_map(fn ($pl) => $cross($locked[$pl], [[$from, $to]]), $atkDps)) / ($winLen * max(1, count($atkDps))),
                'healerLockedShare' => $atkHealerId ? $cross($locked[$atkHealerId], [[$from, $to]]) / $winLen : 0,
                'chain' => $go['chain'],
                'maxGap' => $go['maxGap'],
                'healerCcInChain' => count(array_filter($go['casts'], fn ($e) => ($e['role'] ?? null) === 'healer')),
                'crossCcInChain' => count(array_filter($go['casts'], fn ($e) => ($e['role'] ?? null) === 'cross')),
                'quality' => $go['quality'],
                'target' => $go['target'],
                'drained' => count($drained),
                'sustain' => $ourDmg > 0 ? $hTotal / $ourDmg : null,
                'defUtilTagged' => array_map($specTag, $inWin($other, 'utility')),
                'atkUtil' => array_column($inWin($side, 'utility'), 'spell'),
                'defUtil' => array_column($inWin($other, 'utility'), 'spell'),
                'atkCtrl' => array_column($inWin($side, 'control'), 'spell'),
                'defCtrl' => array_column($inWin($other, 'control'), 'spell'),
                'killLater' => (bool) $later,
                'defHealerSpec' => $dh ? $label($dh) : null,
                'defHealerHps' => $to > $from ? $hTotal / ($to - $from) : 0,
                'defHealerHot' => $hTotal > 0 ? $hHot / $hTotal : null,
                'side' => $side, 'from' => $from, 'to' => $to,
                'casts' => implode(', ', array_map(fn ($c) => $c['spell'].($c['cat'] === 'control' ? '*' : ''), $go['casts'])),
                'firstDef' => $defs ? round($defs[0]['t'] - $from, 1) : null,
                'defs' => count($defs),
                'defNames' => implode(', ', array_map(fn ($c) => $c['spell'], $defs)),
                'kill' => $kill ? $short($roster[$kill[0]['who']]['name']) : null,
                'atkHealer' => isset($healers[$side]) ? round($cross($locked[$healers[$side]], $w), 1) : 0,
                'defHealer' => isset($healers[$other]) ? round($cross($locked[$healers[$other]], $w), 1) : 0,
            ];
        }
    }

    // ---- game-level: what share of each side's go time was each healer / the attacking DPS locked out
    $pct = fn ($part, $whole) => $whole > 0 ? round(100 * $part / $whole) : null;
    $g = ['time' => $clock, 'won' => $won, 'dur' => $m['durationInSeconds']];
    foreach (['us', 'them'] as $side) {
        $other = $side === 'us' ? 'them' : 'us';
        $gt = $len($goTime[$side]);
        $rows = array_values(array_filter($goRows, fn ($r) => $r['side'] === $side));
        $d = $dps($side);
        $dpsLocked = array_sum(array_map(fn ($p) => $cross($locked[$p], $goTime[$side]), $d));
        $g[$side] = [
            'goes' => count($rows),
            'goTime' => round($gt),
            'kills' => count(array_filter($rows, fn ($r) => $r['kill'])),
            'defsPerGo' => $rows ? round(array_sum(array_column($rows, 'defs')) / count($rows), 1) : null,
            'goesForcingDef' => count(array_filter($rows, fn ($r) => $r['defs'] > 0)),
            // During THIS side's goes:
            'ownHealerLockedPct' => isset($healers[$side]) ? $pct($cross($locked[$healers[$side]], $goTime[$side]), $gt) : null,
            'enemyHealerLockedPct' => isset($healers[$other]) ? $pct($cross($locked[$healers[$other]], $goTime[$side]), $gt) : null,
            'ownDpsLockedPct' => $pct($dpsLocked, $gt * max(1, count($d))),
            'healerLockedTotal' => isset($healers[$side]) ? round($len($locked[$healers[$side]])) : null,
        ];
    }

    // ---- BAIT: attacker CC outside any go that drew a defensive (within RESPONSE seconds), with no
    // offensive cooldown from the attacker until that defensive had expired. A CC inside a go's
    // window is part of the go, never bait. An instant defensive (Medallion) has expired at once,
    // so drawing one with CC outside a go is bait unless an anchor lands at the same moment.
    foreach (['us', 'them'] as $side) {
        $other = $side === 'us' ? 'them' : 'us';
        $anchors = array_column($goCasts[$side], 't');
        foreach ($tl['commitments'] as $c) {
            if ($sideOf($c['who']) !== $side || $c['cat'] !== 'control') {
                continue;
            }
            // Inside either side's go it is not bait: in our own go it is part of the chain, in
            // theirs it is an answer to their go (19:54 was first misread as bait this way).
            $inGo = false;
            foreach ([$side, $other] as $sd) {
                foreach ($goes[$sd] as $go) {
                    $inGo = $inGo || ($c['t'] >= $go['from'] && $c['t'] <= $go['to']);
                }
            }
            if ($inGo) {
                continue;
            }
            $resp = array_values(array_filter($tl['commitments'], fn ($d) => $sideOf($d['who']) === $other
                && $d['cat'] === 'defensive' && $d['t'] >= $c['t'] && $d['t'] <= $c['t'] + RESPONSE));
            if (! $resp) {
                continue;
            }
            $expires = max(array_map(fn ($d) => $d['t'] + (float) ($durations[$d['spellId']] ?? 0), $resp));
            $nextAnchor = min(array_filter($anchors, fn ($t) => $t > $c['t']) ?: [INF]);
            // A CC that leads straight to a kill is a kill setup, not bait.
            $killed = array_filter($deaths, fn ($d) => $d['side'] === $other && $d['t'] >= $c['t'] && $d['t'] <= max($expires, $c['t'] + RESPONSE));
            if ($killed) {
                continue;
            }
            // Several CCs drawing the SAME defensive are one bait: extend the previous entry.
            $drewKey = implode('|', array_map(fn ($d) => $d['spell'].'@'.$d['t'], $resp));
            $last = end($baits);
            if ($last && $last['game'] === $clock && $last['side'] === $side && $last['drewKey'] === $drewKey) {
                $baits[count($baits) - 1]['cc'] .= ' + '.$c['spell'];

                continue;
            }
            $baits[] = [
                'drewKey' => $drewKey,
                'game' => $clock, 'won' => $won, 'side' => $side, 'cc' => $c['spell'], 't' => $c['t'],
                'drew' => implode(', ', array_map(fn ($d) => $d['spell'], $resp)),
                'bait' => $nextAnchor >= $expires,
                'goAfter' => is_finite($nextAnchor) ? round($nextAnchor - $c['t'], 1) : null,
                'expiresIn' => round($expires - $c['t'], 1),
            ];
        }
    }

    // ---- the kill read, for the first real death
    $kill = null;
    if ($deaths) {
        $d = $deaths[0];
        $killer = $d['side'] === 'us' ? 'them' : 'us';
        $win = array_values(array_filter($dmg, fn ($x) => $x['dst'] === $d['who'] && $x['t'] <= $d['t'] + 0.05 && $x['t'] >= $d['t'] - DMG_LOOKBACK));
        $shares = [];
        $total = 0;
        foreach ($win as $x) {
            $who = $credit($x['src']);
            $key = isset($roster[$who]) ? $short($roster[$who]['name']) : 'other/pets';
            $shares[$key] = ($shares[$key] ?? 0) + $x['amount'];
            $total += $x['amount'];
        }
        arsort($shares);
        $kb = end($win) ?: null;
        $before = null;
        foreach ($win as $x) {
            if ($x === $kb) {
                break;
            }
            $before = $x['hpAfter'] ?? $before;
        }
        // The go: the killer's go (anchored, with its utility and CC) whose window holds the death.
        $run = [];
        foreach ($goes[$killer] as $go) {
            if ($go['from'] <= $d['t'] && $go['to'] >= $d['t'] - 0.1) {
                $run = $go['casts'];
            }
        }
        $healer = $healers[$d['side']] ?? null;
        $hcc = 'no lockout in last 10s';
        if ($healer === $d['who']) {
            $hcc = 'was the kill';
        } elseif ($healer) {
            foreach ($locked[$healer] as [$a, $b]) {
                if ($a <= $d['t'] && $b >= $d['t'] - 0.1) {
                    $hcc = 'LOCKED OUT at the death';
                } elseif ($b < $d['t'] && $b >= $d['t'] - DMG_LOOKBACK && $hcc === 'no lockout in last 10s') {
                    $hcc = sprintf('lockout ended %.0fs before', $d['t'] - $b);
                }
            }
        }
        $defs = array_values(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $d['side']
            && $c['kind'] === 'defensive' && $c['t'] >= $d['t'] - KILL_LOOKBACK && $c['t'] <= $d['t']));
        $kill = [
            'died' => $short($roster[$d['who']]['name']).' ('.$roster[$d['who']]['spec'].')',
            'side' => $d['side'], 't' => $d['t'],
            'goStart' => $run ? round($d['t'] - $run[0]['t'], 1) : null,
            'go' => implode(', ', array_map(fn ($c) => $c['spell'].($c['cat'] === 'control' ? '*' : ''), $run)),
            'kb' => $kb ? "{$kb['spell']} ".number_format($kb['amount']).($before !== null ? " at {$before}%" : '') : '?',
            'shares' => implode(', ', array_map(fn ($k, $v) => "$k ".round(100 * $v / max(1, $total)).'%', array_keys($shares), $shares)),
            'healer' => $hcc,
            'defs' => implode(', ', array_map(fn ($c) => $c['spell'].' ('.$short($roster[$c['who']]['name']).', '.round($d['t'] - $c['t']).'s)', $defs)),
        ];
    }
    $g['kill'] = $kill;
    $firstDeath = $deaths[0]['t'] ?? INF;
    $spent = fn ($sd) => count(array_filter($tl['commitments'], fn ($c) => $sideOf($c['who']) === $sd && $c['cat'] === 'defensive' && $c['t'] <= $firstDeath));
    $g['defsSpent'] = ['us' => $spent('us'), 'them' => $spent('them')];
    // Both MMRs from ARENA_MATCH_END: fields 3 and 4, ours is the one the metadata recorded.
    $g['mmr'] = [$m['playerTeamRating'] ?? null, null];
    foreach (array_reverse($lines) as $ln) {
        if (str_contains($ln, 'ARENA_MATCH_END,')) {
            $e = explode(',', explode('  ', $ln, 2)[1]);
            $g['mmr'][1] = (int) $e[3] === (int) $g['mmr'][0] ? (int) $e[4] : (int) $e[3];
            break;
        }
    }
    // LEVEL OF PLAY (guides-from-play.md): from all six players. Gladiator = every player has a
    // Gladiator season; an unknown player (no public profile) makes the game borderline.
    $gl = array_map(fn ($r) => $xp[$r['name']] ?? null, array_values($roster));
    $unknown = count(array_filter($gl, fn ($x) => $x === null || isset($x['error'])));
    $allGlad = count(array_filter($gl, fn ($x) => ($x['glad_seasons'] ?? 0) > 0)) === count($gl);
    $g['level'] = $allGlad ? 'Gladiator' : ($unknown > 0 && count(array_filter($gl, fn ($x) => ($x['glad_seasons'] ?? 0) > 0)) + $unknown === count($gl) ? 'Gladiator?' : 'mixed');

    // DAMAGE BY ABILITY, ours onto them, pets credited to owners.
    $g['abilities'] = [];
    foreach ($dmg as $x) {
        $who = $credit($x['src']);
        if ($sideOf($who) === 'us' && $sideOf($x['dst']) === 'them') {
            $key = $short($roster[$who]['name']).': '.$x['spell'];
            $g['abilities'][$key] = ($g['abilities'][$key] ?? 0) + $x['amount'];
        }
    }

    // OVERLAPPING DEFENSIVES: two defensive buffs on the same player at once for 1s or more.
    // A defensive is anything this game's commitments labelled defensive, matched by name.
    $defNames = array_unique(array_column(array_filter($tl['commitments'], fn ($c) => $c['cat'] === 'defensive'), 'spell'));
    $g['overlaps'] = ['us' => [], 'them' => []];
    $db = array_values(array_filter($buffs, fn ($b) => in_array($b['name'], $defNames, true)));
    for ($i = 0; $i < count($db); $i++) {
        for ($j = $i + 1; $j < count($db); $j++) {
            if ($db[$i]['on'] !== $db[$j]['on'] || $db[$i]['name'] === $db[$j]['name']) {
                continue;
            }
            $o = $overlap($db[$i]['from'], $db[$i]['to'], $db[$j]['from'], $db[$j]['to']);
            if ($o >= 1.0 && $db[$i]['from'] <= $firstDeath) {
                $g['overlaps'][$sideOf($db[$i]['on'])][] = sprintf('%s + %s on %s at %.0fs (%.0fs)',
                    $db[$i]['name'], $db[$j]['name'], $short($roster[$db[$i]['on']]['name']), max($db[$i]['from'], $db[$j]['from']), $o);
            }
        }
    }

    // INTERRUPTS: who kicked whom, and whether it landed inside the kicker's go.
    $g['interrupts'] = ['us' => [], 'them' => []];
    foreach ($interrupts as $x) {
        $sd = $sideOf($credit($x['src']));
        if ($sd === null) {
            continue;
        }
        $inGo = false;
        foreach ($goes[$sd] as $go) {
            $inGo = $inGo || ($x['t'] >= $go['from'] && $x['t'] <= $go['to']);
        }
        $onHealer = ($healers[$sideOf($x['dst'])] ?? null) === $x['dst'];
        $g['interrupts'][$sd][] = ['onHealer' => $onHealer, 'inGo' => $inGo, 'text' => sprintf('%.0fs %s > %s (%s)', $x['t'], $x['spell'], $onHealer ? 'healer' : $short($roster[$x['dst']]['name']), $x['interrupted'])];
    }

    $g['enemy'] = [];
    foreach ($roster as $gg => $r) {
        if ($sideOf($gg) !== 'them') {
            continue;
        }
        $x = $xp[$r['name']] ?? null;
        $sid = $specIdOf[$gg] ?? '';
        $who = SPEC_NAMES[$sid] ?? $r['spec'];
        if ($x === null || isset($x['error'])) {
            $cell = "$who (no profile)";
        } elseif (($x['glad_seasons'] ?? 0) > 0) {
            $cell = "$who {$x['glad_seasons']}x Glad ".($x['exp_3v3'] ?? '?');
        } else {
            $rank = $x['best_rank'] ? explode(':', $x['best_rank'])[0] : 'no rank';
            $rank = $rank === 'Legend' ? 'Legend (Shuffle)' : $rank;
            $cell = "$who $rank ".($x['exp_3v3'] ?? '?');
        }
        $g['enemy'][] = ['healer' => $r['healer'], 'cell' => $cell];
    }
    usort($g['enemy'], fn ($a, $b) => $b['healer'] <=> $a['healer']);
    // OVERCOMMITMENT: defensives a side spent before the first death while the OTHER side was not
    // in a go (outside every go window of theirs). 19:54: five of ours before their first
    // offensive cooldown, at 63-74% health, two of them to break CC.
    foreach (['us' => 'them', 'them' => 'us'] as $sd => $opp) {
        $g['defsOutsideTheirGo'][$sd] = count(array_filter($tl['commitments'], function ($c) use ($sd, $opp, $sideOf, $goes, $firstDeath) {
            if ($sideOf($c['who']) !== $sd || $c['cat'] !== 'defensive' || $c['t'] > $firstDeath) {
                return false;
            }
            foreach ($goes[$opp] as $go) {
                if ($c['t'] >= $go['from'] && $c['t'] <= $go['to']) {
                    return false;
                }
            }

            return true;
        }));
    }
    $g['ourGoes'] = count(array_filter($goRows, fn ($r) => $r['side'] === 'us'));
    $g['ourGoesKill'] = count(array_filter($goRows, fn ($r) => $r['side'] === 'us' && $r['killLater']));
    $g['goRows'] = $goRows;
    $g['enemyGlad'] = array_sum(array_map(fn ($gg) => $xp[$roster[$gg]['name']]['glad_seasons'] ?? 0,
        array_keys(array_filter($roster, fn ($r, $gg) => $sideOf($gg) === 'them', ARRAY_FILTER_USE_BOTH))));
    $all[] = $g;

    // ==== WARRANT: was the trinket / the second defensive needed?
    $hpAt = function ($who, $t) use ($hp) {
        $v = null;
        foreach ($hp[$who] ?? [] as [$tt, $p]) {
            if ($tt > $t) {
                break;
            }
            $v = $p;
        }

        return $v;
    };
    $minHp = function ($who, $a, $b) use ($hp, $hpAt) {
        $v = $hpAt($who, $a);
        foreach ($hp[$who] ?? [] as [$tt, $p]) {
            if ($tt >= $a && $tt <= $b) {
                $v = $v === null ? $p : min($v, $p);
            }
        }

        return $v;
    };
    $dmgIn = fn ($who, $a, $b) => array_sum(array_column(array_filter($dmg, fn ($x) => $x['dst'] === $who && $x['t'] >= $a && $x['t'] <= $b
        && $sideOf($credit($x['src'])) !== $sideOf($who)), 'amount'))
        + array_sum(array_column(array_filter($heal, fn ($x) => $x['dst'] === $who && $x['t'] >= $a && $x['t'] <= $b
        && str_starts_with($x['spell'], 'absorb: ')), 'amount'));
    // Seconds to live at a given rate: current health / incoming per second.
    $ttl = function ($who, $t, $rate) use ($hpAt, $maxHp) {
        $p = $hpAt($who, $t);
        if ($p === null || $rate <= 0 || ! isset($maxHp[$who])) {
            return '-';
        }

        return round($p / 100 * $maxHp[$who] / $rate, 1).'s';
    };
    $theirGoAt = function ($t) use ($goes) {
        foreach ($goes['them'] as $go) {
            if ($t >= $go['from'] && $t <= $go['to']) {
                return sprintf('%.0f-%.0fs', $go['from'], $go['to']);
            }
        }

        return 'no';
    };
    $k = fn ($n) => round($n / 1000).'k';
    $ourPlayers = array_keys(array_filter($roster, fn ($r, $gg) => $sideOf($gg) === 'us', ARRAY_FILTER_USE_BOTH));
    $ourDeaths = array_values(array_filter($deaths, fn ($d) => $d['side'] === 'us'));
    printf("%s %s  (our deaths: %s)\n", $clock, $won ? 'WON ' : 'LOST', implode(', ', array_map(fn ($d) => $short($roster[$d['who']]['name']).' '.round($d['t'], 1).'s', $ourDeaths)) ?: 'none');

    $priest = $healers['us'] ?? null;
    $med = array_column(array_filter($tl['commitments'], fn ($c) => $c['who'] === $priest && $c['spell'] === "Gladiator's Medallion"), 't');
    foreach ($ourDeaths as $d) {
        $on = array_filter($debuffOff, fn ($x) => $x['dst'] === $priest && ($x['from'] ?? INF) <= $d['t'] && $x['t'] >= $d['t'] - 0.1 && ($x['t'] - $x['from']) < 60);
        $lastMed = array_filter($med, fn ($m) => $m <= $d['t']);
        printf("  DEATH %s at %.1fs | priest's debuffs at the death: %s | priest Medallion: %s\n", $short($roster[$d['who']]['name']), $d['t'],
            implode(', ', array_map(fn ($x) => sprintf('%s (%.1f-%.1f)', $x['spell'], $x['from'], $x['t']), $on)) ?: 'none',
            $lastMed ? sprintf('used at %.1fs (%.0fs before)', max($lastMed), $d['t'] - max($lastMed)) : 'never used');
    }
    foreach ($tl['commitments'] as $c) {
        if ($c['spell'] !== "Gladiator's Medallion" || $sideOf($c['who']) !== 'us') {
            continue;
        }
        $t = $c['t'];
        $who = $c['who'];
        $broke = array_values(array_filter($tl['control'], fn ($x) => $x['on'] === $who && $x['from'] <= $t + 0.1 && $x['to'] >= $t - 0.3 && $x['to'] <= $t + 0.6));
        $nom = null;
        $remain = null;
        $ccText = 'nothing found';
        if ($broke) {
            $b = $broke[0];
            $nom = (float) (App\Models\Spell::query()->where('patch_id', App\Models\Patch::where('is_current', true)->value('id'))
                ->where('name', $b['spell'])->max(DB::raw('COALESCE(pvp_duration_seconds, duration_seconds)')) ?? 0);
            $prior = count(array_filter($tl['control'], fn ($x) => $x['on'] === $who && $x['dr'] === $b['dr'] && $x['from'] < $b['from'] - 0.05 && $x['to'] >= $b['from'] - 18));
            $eff = $nom * (0.5 ** $prior);
            $remain = max(0, $eff - ($t - $b['from']));
            $ccText = sprintf('%s (%s, by %s) after %.1fs of it; nominal %ss, DR x%s -> ~%.1fs left',
                $b['spell'], $b['dr'], isset($roster[$credit($b['by'])]) ? $short($roster[$credit($b['by'])]['name']) : '?',
                $t - $b['from'], $nom ?: '?', 0.5 ** $prior, $remain);
        }
        $win = [$t, $t + max(2.0, $remain ?? 2.0)];
        printf("  MEDALLION %s at %.1fs | broke: %s | their go: %s\n", $short($roster[$who]['name']), $t, $ccText, $theirGoAt($t));
        printf("     debuffs off him at that moment: %s\n", implode(', ', array_map(fn ($x) => sprintf('%s (on since %.1fs)', $x['spell'], $x['from'] ?? -1), array_filter($debuffOff, fn ($x) => $x['dst'] === $who && abs($x['t'] - $t) <= 0.4))) ?: 'none');
        foreach ($ourPlayers as $p) {
            if ($p === $who) {
                continue;
            }
            $r3 = $dmgIn($p, $t - 3, $t) / 3;
            $rw = $dmgIn($p, $win[0], $win[1]) / max(0.5, $win[1] - $win[0]);
            printf("     %-11s hp at trinket %s%% (max %s), low in saved window %s%% | incoming 3s before %s/s (ttl %s), in window %s/s (ttl %s)\n",
                $short($roster[$p]['name']), $hpAt($p, $t) ?? '?', $k($maxHp[$p] ?? 0), $minHp($p, $win[0], $win[1] + 1) ?? '?',
                $k($r3), $ttl($p, $t, $r3), $k($rw), $ttl($p, $t, $rw));
        }
        $next = array_values(array_filter($casts, fn ($x) => $x['src'] === $who && $x['t'] > $t && $x['t'] <= $win[1] + 0.5));
        $healed = array_sum(array_column(array_filter($heal, fn ($x) => $credit($x['src']) === $who && $x['t'] >= $win[0] && $x['t'] <= $win[1]), 'amount'));
        printf("     trinketer pressed in the window: %s | healing done in it %s\n", implode(', ', array_map(fn ($x) => $x['spell'].'@'.round($x['t'] - $t, 1), array_slice($next, 0, 8))) ?: 'nothing', $k($healed));
        $recc = array_values(array_filter($tl['control'], fn ($x) => $x['on'] === $who && in_array($x['dr'], LOCKOUT, true) && $x['from'] > $t + 0.5 && $x['from'] <= $t + 20));
        printf("     next lockout on him: %s | next death of ours: %s\n",
            $recc ? sprintf('%s at +%.1fs for %.1fs', $recc[0]['spell'], $recc[0]['from'] - $t, $recc[0]['to'] - $recc[0]['from']) : 'none within 20s',
            ($nd = array_values(array_filter($ourDeaths, fn ($d) => $d['t'] > $t))) ? sprintf('%s at +%.0fs', $short($roster[$nd[0]['who']]['name']), $nd[0]['t'] - $t) : 'none');
    }

    $db = array_values(array_filter($buffs, fn ($b) => in_array($b['name'], $defNames, true) && $sideOf($b['on']) === 'us'));
    for ($i = 0; $i < count($db); $i++) {
        for ($j = $i + 1; $j < count($db); $j++) {
            if ($db[$i]['on'] !== $db[$j]['on'] || $db[$i]['name'] === $db[$j]['name']) {
                continue;
            }
            $o = $overlap($db[$i]['from'], $db[$i]['to'], $db[$j]['from'], $db[$j]['to']);
            if ($o < 1.0 || $db[$i]['from'] > $firstDeath) {
                continue;
            }
            [$first, $second] = $db[$i]['from'] <= $db[$j]['from'] ? [$db[$i], $db[$j]] : [$db[$j], $db[$i]];
            $on = $first['on'];
            $t2 = $second['from'];
            $oEnd = min($first['to'], $second['to']);
            $ownr = fn ($b) => isset($roster[$credit($b['src'])]) ? $short($roster[$credit($b['src'])]['name']) : '?';
            printf("  OVERLAP on %s: %s (%s, %.1fs in) + %s (%s) at %.1fs for %.1fs | their go: %s\n",
                $short($roster[$on]['name']), $first['name'], $ownr($first), $t2 - $first['from'], $second['name'], $ownr($second), $t2, $o, $theirGoAt($t2));
            printf("     hp at 2nd %s%%, low in 3s before %s%%, low during overlap %s%%, low in 3s after %s%% | taken 3s before %s/s, during overlap %s/s\n",
                $hpAt($on, $t2) ?? '?', $minHp($on, $t2 - 3, $t2) ?? '?', $minHp($on, $t2, $oEnd) ?? '?', $minHp($on, $oEnd, $oEnd + 3) ?? '?',
                $k($dmgIn($on, $t2 - 3, $t2) / 3), $k($dmgIn($on, $t2, $oEnd) / max(0.5, $oEnd - $t2)));
            $physShare = function ($a, $b) use ($dmg, $on, $sideOf, $credit) {
                $w = array_filter($dmg, fn ($x) => $x['dst'] === $on && $x['t'] >= $a && $x['t'] <= $b && $sideOf($credit($x['src'])) !== $sideOf($on));
                $tot = array_sum(array_column($w, 'amount'));

                return $tot > 0 ? round(100 * array_sum(array_column(array_filter($w, fn ($x) => $x['phys']), 'amount')) / $tot).'%' : '-';
            };
            printf("     at the 3s-before rate he had %s to live; target max hp %s | physical share of damage: 3s before %s, during %s\n",
                $ttl($on, $t2, $dmgIn($on, $t2 - 3, $t2) / 3), $k($maxHp[$on] ?? 0), $physShare($t2 - 3, $t2), $physShare($t2, $oEnd));
        }
    }

    // EVERY DEFENSIVE: why it might have been pressed, from what the log can show. A press can have
    // several reasons; the verdict takes the strongest. Reasons, strongest first:
    //   danger   - the target had DANGER_TTL seconds or less to live at the 3s-before rate, or was
    //              at DANGER_HP% or lower
    //   cc       - a lockout came off the target as it was pressed, or a CC hit the target IMMUNE
    //              while its buff was up (SPELL_MISSED ... IMMUNE: observed, not inferred)
    //   insure   - the target was in their own go (an offensive cast 3s before to 8s after) and the
    //              spell grants CC immunity by its own effects: insurance for the burst, whether or
    //              not a CC came
    //   focus    - their go was running and the target was the one it was hitting hardest
    //   alone    - our healer was locked out as it was pressed
    //   none     - nothing above: the log shows no reason for it
    foreach ($tl['commitments'] as $c) {
        if ($c['cat'] !== 'defensive' || $c['spell'] === "Gladiator's Medallion") {
            continue;
        }
        $t = $c['t'];
        $side = $sideOf($c['who']);
        $cast = array_values(array_filter($casts, fn ($x) => $x['src'] === $c['who'] && $x['spell'] === $c['spell'] && abs($x['t'] - $t) < 0.1));
        $on = $cast && isset($roster[$cast[0]['dst']]) ? $cast[0]['dst'] : $c['who'];
        $buf = array_values(array_filter($buffs, fn ($b) => $b['on'] === $on && $b['name'] === $c['spell'] && abs($b['from'] - $t) < 0.6));
        $until = $buf ? $buf[0]['to'] : $t + (float) ($durations[$c['spellId']] ?? 8);
        $rate = $dmgIn($on, $t - 3, $t) / 3;
        $p = $hpAt($on, $t);
        $p = $p === null ? null : min(100.0, $p);
        $life = ($p !== null && $rate > 0 && isset($maxHp[$on])) ? $p / 100 * $maxHp[$on] / $rate : INF;
        $reasons = [];
        if ($life <= DANGER_TTL || ($p !== null && $p <= DANGER_HP)) {
            $reasons['danger'] = sprintf('%s%%, %s to live', $p ?? '?', is_finite($life) ? round($life, 1).'s' : '-');
        }
        $broke = array_filter($tl['control'], fn ($x) => $x['on'] === $on && in_array($x['dr'], LOCKOUT, true) && $x['from'] < $t && abs($x['to'] - $t) <= 0.4);
        $ate = array_filter($immune, fn ($x) => $x['dst'] === $on && $x['t'] >= $t && $x['t'] <= $until && in_array($ccMap[$x['spellId']] ?? null, LOCKOUT, true));
        if ($broke || $ate) {
            $reasons['cc'] = implode(', ', array_merge(array_map(fn ($x) => 'broke '.$x['spell'], $broke), array_map(fn ($x) => 'immune to '.$x['spell'], $ate)));
        }
        $grants = array_values(array_filter($immunityOf($c['spellId']), fn ($m) => ! str_starts_with($m, 'Unknown')));
        $ownGo = array_filter($goCasts[$side] ?? [], fn ($x) => $x['who'] === $on && $x['t'] >= $t - 3 && $x['t'] <= $t + 8);
        if ($ownGo && $grants) {
            $reasons['insure'] = 'in own go ('.implode(', ', array_unique(array_column($ownGo, 'spell'))).'), grants '.implode('/', $grants).' immunity';
        }
        $opp = $side === 'us' ? 'them' : 'us';
        // Their offensive cooldowns actually running (cast, and inside the spell's own duration;
        // 12s when the data has none), while their go was on him.
        $up = array_unique(array_column(array_filter($goCasts[$opp] ?? [], fn ($x) => $x['t'] <= $t + 0.5
            && $t <= $x['t'] + (float) ($durations[$x['spellId']] ?? 12)), 'spell'));
        foreach ($goes[$opp] as $go) {
            if ($up && $t >= $go['from'] && $t <= $go['to'] && $go['target'] === $short($roster[$on]['name'])) {
                $reasons['focus'] = 'their go on him, up: '.implode(', ', $up);
            }
        }
        $h = $healers[$side] ?? null;
        if ($h && $h !== $on && $h !== $c['who'] && $cross($locked[$h], [[$t - 0.1, $t + 0.1]]) > 0) {
            $reasons['alone'] = 'healer locked out';
        }
        $verdict = array_key_first($reasons) ?? 'none';

        // WAS THE REASON VALID? Two halves.
        // 1. Did it do the job its reason claims? Replay the buff window with the damage the
        //    defensive removed added back: each enemy hit it covered grows by r/(1-r) (a % reduction,
        //    DEFENSIVE_EFFECT), or the defensive's own logged absorbs are added back (an absorb). The
        //    shadow health is the logged health minus everything added back so far, checked to 3s
        //    after the buff ends, because the missing health does not come back on its own. The
        //    healer's response to a lower bar is not modelled, so "would have died" is at the
        //    healing that actually happened.
        $valid = null;
        $prevented = 0;
        $shadowLow = null;
        $effect = DEFENSIVE_EFFECT[$c['spell']] ?? null;
        if ($side === 'us' && isset($maxHp[$on])) {
            $events = [];
            foreach ($dmg as $x) {
                if ($x['dst'] === $on && $x['t'] >= $t && $x['t'] <= $until + 3 && $sideOf($credit($x['src'])) !== $side) {
                    $covered = $effect && $effect['type'] === 'pct' && $x['t'] <= $until && ($effect['magicOnly'] ? ! $x['phys'] : true);
                    $events[] = [$x['t'], $covered ? $x['amount'] * $effect['pct'] / (1 - $effect['pct']) : 0, $x['hpAfter']];
                }
            }
            foreach ($heal as $x) {
                if ($x['dst'] === $on && $x['t'] >= $t && $x['t'] <= $until
                    && $x['spell'] === 'absorb: '.$c['spell']) {
                    $events[] = [$x['t'], $x['amount'], null];
                }
            }
            // A Leech defensive (Lichborne with Vampiric Aura): the Leech healing on him during it,
            // above his own Leech rate over the 10s before, is what it bought. Added back as damage.
            if ($effect && $effect['type'] === 'leech') {
                $leech = fn ($a, $b) => array_sum(array_column(array_filter($heal, fn ($x) => $x['dst'] === $on && $x['spell'] === 'Leech'
                    && $x['t'] >= $a && $x['t'] <= $b), 'amount'));
                $during = $leech($t, $until);
                $keep = $during > 0 ? max(0, 1 - $leech($t - 10, $t) / 10 * ($until - $t) / $during) : 0;
                foreach ($heal as $x) {
                    if ($x['dst'] === $on && $x['spell'] === 'Leech' && $x['t'] >= $t && $x['t'] <= $until) {
                        $events[] = [$x['t'], $x['amount'] * $keep, null];
                    }
                }
            }
            usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($events as [$et, $extra, $pct]) {
                $prevented += $extra;
                $cur = $pct ?? $hpAt($on, $et);
                if ($cur === null) {
                    continue;
                }
                $now = $cur / 100 * $maxHp[$on] - $prevented;
                $shadowLow = $shadowLow === null ? $now : min($shadowLow, $now);
            }
            $prevPct = 100 * $prevented / $maxHp[$on];
            $wouldDie = $shadowLow !== null && $shadowLow <= 0;
            $ateLock = (bool) array_filter($ate);
            $landedTheirCc = $broke && (isset($reasons['focus']) || isset($reasons['danger']) || $ownGo);
            // When the damage stops, WHY did it stop? The enemy's damage onto our whole team, and the
            // share of it on this target, 3s before against the buff window. A visible defensive is
            // often answered by a swap, which is the defensive working, not being wasted.
            $theirOut = function ($a, $b) use ($dmg, $credit, $sideOf, $side) {
                $all = 0;
                $per = [];
                foreach ($dmg as $x) {
                    if ($x['t'] >= $a && $x['t'] <= $b && $sideOf($x['dst']) === $side && $sideOf($credit($x['src'])) !== $side) {
                        $all += $x['amount'];
                        $per[$x['dst']] = ($per[$x['dst']] ?? 0) + $x['amount'];
                    }
                }

                return [$all / max(0.5, $b - $a), $per];
            };
            [$outBefore, $perBefore] = $theirOut($t - 3, $t);
            [$outDuring, $perDuring] = $theirOut($t, $until);
            $shareBefore = $outBefore > 0 ? ($perBefore[$on] ?? 0) / 3 / $outBefore : 0;
            $shareDuring = $outDuring > 0 ? ($perDuring[$on] ?? 0) / max(0.5, $until - $t) / $outDuring : 0;
            $after = match (true) {
                $outBefore <= 0 => '',
                $outDuring < 0.5 * $outBefore => sprintf('; their damage fell to %d%%', 100 * $outDuring / $outBefore),
                $shareBefore >= 0.5 && $shareDuring < 0.5 * $shareBefore => sprintf('; they swapped (%d%% of their damage on him, then %d%%)', 100 * $shareBefore, 100 * $shareDuring),
                default => '',
            };
            $swapped = str_contains($after, 'swapped');
            $justified = isset($reasons['danger']);
            // How long their offensive cooldowns still had to run when it went on. Damage that stops
            // right after a press with their cooldowns expiring is the burst ending, not the press
            // working: the defensive covered the tail of the go, not the go.
            $left = array_map(fn ($x) => $x['t'] + (float) ($durations[$x['spellId']] ?? 12) - $t,
                array_filter($goCasts[$opp] ?? [], fn ($x) => $x['t'] <= $t + 0.5 && $t <= $x['t'] + (float) ($durations[$x['spellId']] ?? 12)));
            $cdLeft = $left ? max($left) : null;
            $after .= $cdLeft === null ? '; none of their cooldowns up' : sprintf('; their cooldowns had %.0fs left', $cdLeft);
            // The job each reason claims, and whether it was done. A press in real danger is judged
            // on what could be known when it was pressed: if the damage then stopped, it is still
            // right. A pre-emptive press is judged on whether the damage or the CC came.
            $valid = match (true) {
                $wouldDie => 'VALID: would have died without it',
                $ateLock => 'VALID: a lockout hit it immune',
                $prevPct >= PREVENTED_SOLID => sprintf('VALID: removed %d%% of his health', $prevPct),
                (bool) $broke && $landedTheirCc => 'VALID: broke CC during a go',
                $swapped => 'VALID: forced a swap',
                $justified && ($cdLeft ?? 0) <= TAIL => sprintf('LATE: in danger, but their burst was ending (%d%% removed)', $prevPct),
                $justified => sprintf('VALID AT PRESS: in danger, then the damage stopped (%d%% removed)', $prevPct),
                (bool) $broke => 'WEAK: broke CC in a quiet moment',
                $verdict === 'insure' => 'WEAK: immunity unused, no CC came',
                $effect === null => 'WEAK: no damage reduction, no CC answered',
                $prevPct < PREVENTED_LITTLE => sprintf('WEAK: the damage did not come (%d%% removed)', $prevPct),
                default => sprintf('PARTLY: removed %d%% of his health', $prevPct),
            }.$after;
        }
        // 2. What did it cost? Until it was back, did the same player fall to DANGER_HP or die?
        $cost = null;
        if ($side === 'us') {
            $back = $t + $c['cooldown'];
            $died = array_values(array_filter($deaths, fn ($d) => $d['who'] === $on && $d['t'] > $until && $d['t'] < $back));
            $low = null;
            foreach ($hp[$on] ?? [] as [$ht, $hv]) {
                if ($ht > $until + 3 && $ht < $back && $hv <= DANGER_HP) {
                    $low = $ht;
                    break;
                }
            }
            $cost = $died ? sprintf('DIED at +%.0fs, it was back at +%.0fs', $died[0]['t'] - $t, $c['cooldown'])
                : ($low !== null ? sprintf('low again at +%.0fs while on cooldown', $low - $t) : 'no cost seen');
        }
        $defRows[] = ['game' => $clock, 'won' => $won, 'side' => $side, 'who' => $short($roster[$c['who']]['name']),
            'spell' => $c['spell'], 'verdict' => $verdict, 'beforeDeath' => $t <= $firstDeath,
            'valid' => $valid ? strtok($valid, ':') : null, 'cost' => $cost ? strtok($cost, ' ') : null];
        if ($side === 'us') {
            printf("  DEF %5.1fs %-20s %s -> %-11s | %-7s | %s\n", $t, $c['spell'], $short($roster[$c['who']]['name']), $short($roster[$on]['name']),
                strtoupper($verdict), implode(' | ', array_map(fn ($k, $v) => "$k: $v", array_keys($reasons), $reasons)) ?: sprintf('%s%%, %s to live, %s/s incoming', $p ?? '?', is_finite($life) ? round($life).'s' : '-', $k($rate)));
            printf("        %s | lowest without it %s | cost: %s\n", $valid ?? '-',
                $shadowLow === null ? '-' : round(100 * $shadowLow / $maxHp[$on]).'%', $cost);
        }
    }
    echo "\n";

    continue;

    // ---- output
    $enemy = implode(' / ', array_map(fn ($r) => $r['spec'], array_filter($roster, fn ($r, $gg) => $sideOf($gg) === 'them', ARRAY_FILTER_USE_BOTH)));
    printf("%s %s %3ds vs %s\n", $clock, $won ? 'WON ' : 'LOST', $m['durationInSeconds'], $enemy);
    foreach (['us' => 'OUR ', 'them' => 'THEIR'] as $side => $label) {
        $x = $g[$side];
        printf("   %s goes %d (%ss), forced a defensive %d/%d, defs/go %s, kills %d | during these goes: attacking healer locked %s%%, defending healer locked %s%%, attacking DPS locked %s%%\n",
            $label, $x['goes'], $x['goTime'], $x['goesForcingDef'], $x['goes'], $x['defsPerGo'] ?? '-', $x['kills'],
            $x['ownHealerLockedPct'] ?? '-', $x['enemyHealerLockedPct'] ?? '-', $x['ownDpsLockedPct'] ?? '-');
    }
    printf("   healer lockout, whole game: ours %ss, theirs %ss\n", $g['us']['healerLockedTotal'], $g['them']['healerLockedTotal']);
    if ($kill) {
        printf("   DEATH %s [%s] at %ss | go %ss before [%s] | KB %s | healer: %s\n        dmg last 10s: %s\n        defensives 30s: %s\n",
            $kill['died'], $kill['side'], $kill['t'], $kill['goStart'] ?? '-', $kill['go'] ?: 'none', $kill['kb'], $kill['healer'], $kill['shares'], $kill['defs'] ?: 'none');
    }
    if (in_array($clock, $detail, true)) {
        echo "   --- goes\n";
        usort($goRows, fn ($a, $b) => $a['from'] <=> $b['from']);
        foreach ($goRows as $r) {
            printf("   %-4s %5.1f-%5.1f  [%s]  defs %d%s%s | attacking healer locked %ss, defending healer locked %ss\n",
                $r['side'], $r['from'], $r['to'], $r['chain'], $r['defs'],
                $r['defs'] ? " ({$r['defNames']}; first at +{$r['firstDef']}s)" : '',
                $r['kill'] ? " KILL {$r['kill']}" : '', $r['atkHealer'], $r['defHealer']);
        }
        echo "   --- lockout on healers\n";
        foreach ($tl['control'] as $c) {
            if (! in_array($c['on'], $healers, true) || ! in_array($c['dr'], LOCKOUT, true)) {
                continue;
            }
            printf("   %5.1f-%5.1f %s (%s) on %s by %s\n", $c['from'], $c['to'], $c['spell'], $c['dr'], $short($roster[$c['on']]['name']), isset($roster[$c['by']]) ? $short($roster[$c['by']]['name']) : '?');
        }
    }
    echo "\n";
}

// ---- every defensive's verdict, per player, wins against losses
echo "=== DEFENSIVES BY VERDICT (danger > cc > insure > focus > alone > none)\n";
foreach ([true => 'WINS', false => 'LOSSES'] as $w => $lb) {
    $rows = array_filter($defRows, fn ($r) => $r['won'] === (bool) $w && $r['side'] === 'us');
    $by = [];
    foreach ($rows as $r) {
        $by[$r['who']][$r['verdict']] = ($by[$r['who']][$r['verdict']] ?? 0) + 1;
    }
    echo "  $lb\n";
    foreach ($by as $who => $v) {
        printf("    %-12s %s\n", $who, implode(', ', array_map(fn ($k, $n) => "$k $n", array_keys($v), $v)));
    }
    $theirs = array_count_values(array_column(array_filter($defRows, fn ($r) => $r['won'] === (bool) $w && $r['side'] === 'them'), 'verdict'));
    printf("    %-12s %s\n", '(theirs)', implode(', ', array_map(fn ($k, $n) => "$k $n", array_keys($theirs), $theirs)));
}
echo "\n=== WAS THE REASON VALID (ours), and what it cost\n";
foreach ([true => 'WINS', false => 'LOSSES'] as $w => $lb) {
    echo "  $lb\n";
    $rows = array_filter($defRows, fn ($r) => $r['won'] === (bool) $w && $r['side'] === 'us' && $r['valid'] !== null);
    foreach (array_unique(array_column($rows, 'who')) as $who) {
        $mine = array_filter($rows, fn ($r) => $r['who'] === $who);
        $v = array_count_values(array_column($mine, 'valid'));
        $c = array_count_values(array_column($mine, 'cost'));
        printf("    %-12s %s | cost: %s\n", $who, implode(', ', array_map(fn ($k, $n) => "$k $n", array_keys($v), $v)),
            implode(', ', array_map(fn ($k, $n) => "$k $n", array_keys($c), $c)));
    }
}
echo "\n=== BY REASON: how often each reason held up (ours, all games)\n";
$ours = array_filter($defRows, fn ($r) => $r['side'] === 'us' && $r['valid'] !== null);
foreach (array_unique(array_column($ours, 'verdict')) as $reason) {
    $v = array_count_values(array_column(array_filter($ours, fn ($r) => $r['verdict'] === $reason), 'valid'));
    printf("    %-8s %s\n", $reason, implode(', ', array_map(fn ($k, $n) => "$k $n", array_keys($v), $v)));
}
exit;
// ---- wins against losses
echo "=== AVERAGES\n";
foreach ([true => 'WINS', false => 'LOSSES'] as $w => $label) {
    $set = array_values(array_filter($all, fn ($g) => $g['won'] === (bool) $w));
    $avg = function ($side, $k) use ($set) {
        $v = array_filter(array_map(fn ($g) => $g[$side][$k], $set), fn ($x) => $x !== null);

        return $v ? round(array_sum($v) / count($v), 1) : '-';
    };
    printf("%-6s n=%d\n", $label, count($set));
    foreach (['us' => 'our', 'them' => 'their'] as $side => $who) {
        printf("   %-5s goes %s, go-time %ss, share forcing a def %s, defs/go %s | in these goes: attacking healer locked %s%%, defending healer locked %s%%, attacking DPS locked %s%% | healer locked whole game %ss\n",
            $who, $avg($side, 'goes'), $avg($side, 'goTime'),
            round(100 * array_sum(array_map(fn ($g) => $g[$side]['goesForcingDef'], $set)) / max(1, array_sum(array_map(fn ($g) => $g[$side]['goes'], $set)))).'%',
            $avg($side, 'defsPerGo'), $avg($side, 'ownHealerLockedPct'), $avg($side, 'enemyHealerLockedPct'), $avg($side, 'ownDpsLockedPct'), $avg($side, 'healerLockedTotal'));
    }
}

// ---- defensives forced -> a kill later?
$ours = [];
foreach ($all as $g) {
    foreach ($g['goRows'] as $r) {
        if ($r['side'] === 'us') {
            $ours[] = $r + ['won' => $g['won'], 'enemyGlad' => $g['enemyGlad'], 'game' => $g['time']];
        }
    }
}
echo "\n=== OUR GOES: defensives forced -> kill in the window or ".KILL_LATER."s after\n";
foreach (['0' => [0, 0], '1' => [1, 1], '2' => [2, 2], '3+' => [3, 99]] as $label => [$lo, $hi]) {
    $b = array_filter($ours, fn ($r) => $r['defs'] >= $lo && $r['defs'] <= $hi);
    printf("   %-3s defensives: %2d goes, %2d followed by a kill (%s%%)\n", $label, count($b),
        count(array_filter($b, fn ($r) => $r['killLater'])), $b ? round(100 * count(array_filter($b, fn ($r) => $r['killLater'])) / count($b)) : '-');
}

echo "\n=== OUR GOES: their defensives ALREADY on cooldown when the go started -> kill in the window or ".KILL_LATER."s after\n";
foreach (['0' => [0, 0], '1' => [1, 1], '2' => [2, 2], '3+' => [3, 99]] as $lb => [$lo, $hi]) {
    $b = array_filter($ours, fn ($r) => $r['drained'] >= $lo && $r['drained'] <= $hi);
    printf("   %-3s on cooldown: %2d goes, %2d followed by a kill (%s%%) | defs forced in the go %.1f\n", $lb, count($b),
        count(array_filter($b, fn ($r) => $r['killLater'])), $b ? round(100 * count(array_filter($b, fn ($r) => $r['killLater'])) / count($b)) : '-',
        $b ? array_sum(array_column($b, 'defs')) / count($b) : 0);
}

// ---- utilities in our goes, by enemy experience and by result
$report = function (string $title, array $rows) {
    $n = count($rows);
    if ($n === 0) {
        return;
    }
    $sum = fn ($k) => array_sum(array_map(fn ($r) => count($r[$k]), $rows));
    printf("   %-34s goes %2d | their defs/go %.1f, their utilities/go %.1f, their CC/go %.1f | our utilities/go %.1f, our CC/go %.1f | kill later %d%%\n",
        $title, $n, array_sum(array_column($rows, 'defs')) / $n, $sum('defUtil') / $n, $sum('defCtrl') / $n,
        $sum('atkUtil') / $n, $sum('atkCtrl') / $n, round(100 * count(array_filter($rows, fn ($r) => $r['killLater'])) / $n));
};
echo "\n=== OUR GOES: what each side brought besides the go itself\n";
$report('enemy Glad seasons < '.HIGH_XP, array_filter($ours, fn ($r) => $r['enemyGlad'] < HIGH_XP));
$report('enemy Glad seasons >= '.HIGH_XP, array_filter($ours, fn ($r) => $r['enemyGlad'] >= HIGH_XP));
$report('games won', array_filter($ours, fn ($r) => $r['won']));
$report('games lost', array_filter($ours, fn ($r) => ! $r['won']));

$tally = function (array $rows, string $k) {
    $t = [];
    foreach ($rows as $r) {
        foreach ($r[$k] as $s) {
            $t[$s] = ($t[$s] ?? 0) + 1;
        }
    }
    arsort($t);

    return implode(', ', array_map(fn ($s, $n) => "$s $n", array_keys($t), $t));
};
foreach (['won' => true, 'lost' => false] as $label => $w) {
    $rows = array_filter($ours, fn ($r) => $r['won'] === $w);
    printf("   their utilities in our goes, games %s: %s\n", $label, $tally($rows, 'defUtilTagged') ?: 'none');
    printf("   our utilities in our goes, games %s:   %s\n", $label, $tally($rows, 'atkUtil') ?: 'none');
}

// ---- the enemy healer inside our goes
echo "\n=== ENEMY HEALER INSIDE OUR GOES (effective healing + absorbs they cast, per second)\n";
$bySpec = [];
foreach ($ours as $r) {
    if ($r['defHealerSpec']) {
        $bySpec[$r['defHealerSpec']][] = $r;
    }
}
uasort($bySpec, fn ($a, $b) => count($b) <=> count($a));
foreach ($bySpec as $spec => $rows) {
    $hots = array_filter(array_column($rows, 'defHealerHot'), fn ($x) => $x !== null);
    $sus = array_filter(array_column($rows, 'sustain'), fn ($x) => $x !== null);
    printf("   %-14s goes %2d, games %s | HPS %6s | healed %3s%% of our damage | HoT share %3s%% | their defs/go %.1f | kill later %d%%\n",
        $spec, count($rows), implode(' ', array_unique(array_column($rows, 'game'))),
        number_format(array_sum(array_column($rows, 'defHealerHps')) / count($rows)),
        $sus ? round(100 * array_sum($sus) / count($sus)) : '-',
        $hots ? round(100 * array_sum($hots) / count($hots)) : '-',
        array_sum(array_column($rows, 'defs')) / count($rows),
        round(100 * count(array_filter($rows, fn ($r) => $r['killLater'])) / count($rows)));
}

echo "\n=== UTILITY BUCKET (45s+ casts with no offensive/defensive label and no dr_category), both sides\n";
arsort($census);
echo '   '.implode(', ', array_map(fn ($s, $n) => "$s $n", array_keys($census), $census))."\n";

echo "\n=== RACIAL CASTS (only those on a 45s+ cooldown reach the timeline)\n";
foreach ($racials as $k => $games) {
    printf("   %s: %d, games %s\n", $k, count($games), implode(' ', array_unique($games)));
}

echo '
=== BAIT: CC outside a go that drew a defensive
';
foreach (['us' => 'OUR CC', 'them' => 'THEIR CC'] as $side => $lb) {
    $rows = array_values(array_filter($baits, fn ($b) => $b['side'] === $side));
    printf('   %s: %d drew a defensive outside a go; %d were bait (no offensive cooldown until it expired)
',
        $lb, count($rows), count(array_filter($rows, fn ($b) => $b['bait'])));
    foreach ($rows as $b) {
        printf('      %s %s %6.1fs %-20s drew %-40s | defensive up %ss, next offensive %s | %s
',
            $b['game'], $b['won'] ? 'W' : 'L', $b['t'], $b['cc'], $b['drew'], $b['expiresIn'],
            $b['goAfter'] !== null ? '+'.$b['goAfter'].'s' : 'never', $b['bait'] ? 'BAIT' : 'go came while it was up');
    }
}

echo "\n=== EXECUTION: good goes (every link within ".TIGHT_LINK.'s) and bad goes (needed a link up to '.LOOSE_LINK."s, through an offensive cooldown)\n";
foreach (['us' => 'our', 'them' => 'their'] as $sd => $who) {
    foreach (['good', 'bad'] as $q) {
        foreach (['won' => true, 'lost' => false] as $wl => $w) {
            $b = [];
            foreach ($all as $g) {
                foreach ($g['goRows'] as $r) {
                    if ($r['side'] === $sd && $r['quality'] === $q && $g['won'] === $w) {
                        $b[] = $r;
                    }
                }
            }
            printf("   %-5s %-4s goes, games %-4s %2d | healer CC %.1f, cross CC %.1f per go | defs forced/go %.1f | followed by a kill %s%%\n", $who, $q, $wl, count($b),
                $b ? array_sum(array_column($b, 'healerCcInChain')) / count($b) : 0,
                $b ? array_sum(array_column($b, 'crossCcInChain')) / count($b) : 0,
                $b ? array_sum(array_column($b, 'defs')) / count($b) : 0,
                $b ? round(100 * count(array_filter($b, fn ($r) => $r['killLater'])) / count($b)) : '-');
        }
    }
}
echo "\n=== ALL GOES, loosest links first\n";
$every = [];
foreach ($all as $g) {
    foreach ($g['goRows'] as $r) {
        $every[] = $r + ['game' => $g['time'], 'won' => $g['won']];
    }
}
usort($every, fn ($a, $b) => $b['maxGap'] <=> $a['maxGap']);
foreach (array_slice($every, 0, 10) as $r) {
    printf("   %s %s %-4s %5.1fs  defs %d%s  %s\n", $r['game'], $r['won'] ? 'W' : 'L', $r['side'], $r['from'], $r['defs'], $r['kill'] ? " KILL {$r['kill']}" : '', $r['chain']);
}

// ---- THE REVIEW TABLE: the first product. One row per game.
echo '
=== REVIEW TABLE
';
echo '| Game | Level | Result | MMR (us / them) | Enemy team (healer first; Gladiator seasons, or best rank if none; highest 3v3) | Our goes (followed by a kill) | Defensives spent before the first death (us / them) | First death | Our healer at that death |
';
echo '|---|---|---|---|---|---|---|---|---|
';
foreach ($all as $g) {
    $k = $g['kill'];
    printf('| %s | %s | %s | %s / %s | %s | %d (%d) | %d / %d | %s | %s |
', $g['time'], $g['level'], $g['won'] ? 'W' : 'L', $g['mmr'][0] ?? '?', $g['mmr'][1] ?? '?',
        implode(' Â· ', array_column($g['enemy'], 'cell')), $g['ourGoes'], $g['ourGoesKill'],
        $g['defsSpent']['us'], $g['defsSpent']['them'],
        $k ? ($k['side'] === 'us' ? 'ours: ' : 'theirs: ').$k['died'].' to '.explode(' at ', $k['kb'])[0] : '-',
        $k && $k['side'] === 'us' ? $k['healer'] : '-');
}

// ---- HOW THEY ANSWERED OUR GOES: defensives (eat it) against CC (peel it)
echo '
=== HOW THEY ANSWERED OUR GOES: defensives spent vs CC landed on us, per go
';
printf('   %-6s %-2s %5s | %-8s | %-14s %-16s | %-11s %-13s | %s
', 'game', '', 'goes', 'defs/go', 'CC on DPS/go', 'CC on healer/go', 'DPS locked', 'healer locked', 'followed by a kill');
$agg = ['W' => [], 'L' => []];
foreach ($all as $g) {
    $rows = array_values(array_filter($g['goRows'], fn ($r) => $r['side'] === 'us'));
    if (! $rows) {
        continue;
    }
    $n = count($rows);
    $avg = fn ($k) => array_sum(array_column($rows, $k)) / $n;
    $wl = $g['won'] ? 'W' : 'L';
    printf('   %-6s %-2s %5d | %8.1f | %14.1f %16.1f | %10d%% %12d%% | %d of %d
', $g['time'], $wl, $n, $avg('defs'),
        $avg('ansCcDps'), $avg('ansCcHealer'), round(100 * $avg('dpsLockedShare')), round(100 * $avg('healerLockedShare')),
        count(array_filter($rows, fn ($r) => $r['killLater'])), $n);
    foreach ($rows as $r) {
        $agg[$wl][] = $r + ['game' => $g['time']];
    }
}
foreach (['W' => 'games won', 'L' => 'games lost', 'L-' => 'games lost without 19:47'] as $k => $lb) {
    $rows = $k === 'L-' ? array_filter($agg['L'], fn ($r) => $r['game'] !== '19:47') : $agg[$k];
    $n = max(1, count($rows));
    printf('   %-26s goes %2d | defs/go %.1f | CC on DPS/go %.1f, on healer/go %.1f | DPS locked %d%%, healer locked %d%% of the go
', $lb, count($rows),
        array_sum(array_column($rows, 'defs')) / $n, array_sum(array_column($rows, 'ansCcDps')) / $n, array_sum(array_column($rows, 'ansCcHealer')) / $n,
        round(100 * array_sum(array_column($rows, 'dpsLockedShare')) / $n), round(100 * array_sum(array_column($rows, 'healerLockedShare')) / $n));
}

echo '
=== TIMING: our healer locked out as our cooldowns went off (1s before to 4s after the first offensive cast)
';
foreach (['W' => 'games won', 'L' => 'games lost', 'L-' => 'games lost without 19:47'] as $k => $lb) {
    $rows = $k === 'L-' ? array_filter($agg['L'], fn ($r) => $r['game'] !== '19:47') : $agg[$k];
    $n = max(1, count($rows));
    $hl = array_filter($rows, fn ($r) => $r['healerLockedAtCds']);
    printf('   %-26s goes %2d | healer locked at our cooldowns in %2d (%d%%), those followed by a kill: %d | DPS locked in that stretch %.1fs per go | goes where healer was free: %d, followed by a kill %d
',
        $lb, count($rows), count($hl), round(100 * count($hl) / $n), count(array_filter($hl, fn ($r) => $r['killLater'])),
        array_sum(array_column($rows, 'dpsLockedAtCds')) / $n,
        count($rows) - count($hl), count(array_filter($rows, fn ($r) => ! $r['healerLockedAtCds'] && $r['killLater'])));
}
foreach ($all as $g) {
    foreach ($g['goRows'] as $r) {
        if ($r['side'] === 'us' && $r['healerLockedAtCds']) {
            printf('      %s %s  %5.1fs  %s
', $g['time'], $g['won'] ? 'W' : 'L', $r['from'], mb_substr($r['chain'], 0, 150));
        }
    }
}

echo '
=== OVERCOMMITMENT: defensives spent before the first death while the other side was NOT in a go
';
foreach ($all as $g) {
    printf('   %s %s | ours %d of %d spent outside their goes | theirs %d of %d outside ours
', $g['time'], $g['won'] ? 'W' : 'L',
        $g['defsOutsideTheirGo']['us'], $g['defsSpent']['us'], $g['defsOutsideTheirGo']['them'], $g['defsSpent']['them']);
}
foreach (['W' => true, 'L' => false] as $k => $w) {
    $set = array_filter($all, fn ($g) => $g['won'] === $w);
    $o = array_sum(array_map(fn ($g) => $g['defsOutsideTheirGo']['us'], $set));
    $t = array_sum(array_map(fn ($g) => $g['defsSpent']['us'], $set));
    $o2 = array_sum(array_map(fn ($g) => $g['defsOutsideTheirGo']['them'], $set));
    $t2 = array_sum(array_map(fn ($g) => $g['defsSpent']['them'], $set));
    printf('   %s: ours %d of %d (%d%%) outside their goes | theirs %d of %d (%d%%) outside ours
', $w ? 'WINS  ' : 'LOSSES', $o, $t, round(100 * $o / max(1, $t)), $o2, $t2, round(100 * $o2 / max(1, $t2)));
}

// ---- LEVEL OF PLAY
echo "\n=== LEVEL OF PLAY (guides-from-play.md: Gladiator = all six players have a Gladiator season; ? = an unknown player)\n";
foreach (['Gladiator', 'Gladiator?', 'mixed'] as $lv) {
    $set = array_values(array_filter($all, fn ($g) => $g['level'] === $lv));
    printf("   %-11s %2d games (%d won, %d lost): %s\n", $lv, count($set), count(array_filter($set, fn ($g) => $g['won'])),
        count(array_filter($set, fn ($g) => ! $g['won'])), implode(' ', array_map(fn ($g) => $g['time'].($g['won'] ? 'W' : 'L'), $set)));
}

// ---- DAMAGE BY ABILITY
$abilityTable = function (array $set, string $title) {
    $t = [];
    foreach ($set as $g) {
        foreach ($g['abilities'] as $k => $v) {
            $t[$k] = ($t[$k] ?? 0) + $v;
        }
    }
    arsort($t);
    $total = max(1, array_sum($t));
    echo "   $title (".count($set).' games, '.number_format($total)." total)\n";
    foreach (array_slice($t, 0, 12, true) as $k => $v) {
        printf("      %-45s %12s  %4.1f%%\n", $k, number_format($v), 100 * $v / $total);
    }
};
echo "\n=== DAMAGE BY ABILITY, ours onto them (pets credited to owners; Touch of Death finishes included, see note in the analysis)\n";
$abilityTable($all, 'all games');
$abilityTable(array_filter($all, fn ($g) => $g['level'] === 'Gladiator'), 'Gladiator-level games');

// ---- WHAT OUR KILLING GOES HAD IN COMMON
echo "\n=== OUR GOES: what the ones followed by a kill had, against the ones that were not\n";
$feature = function (array $r) {
    $f = [];
    foreach (explode(' -', preg_replace('/-[0-9.]+s->/', ',', $r['chain'])) as $part) {
        foreach (array_map('trim', explode(',', $part)) as $link) {
            if ($link === '') {
                continue;
            }
            $link = rtrim($link, '*');
            $f[preg_replace('/ > (target|cross)$/', ' > dps', $link)] = true;
        }
    }
    $f['[healer CC in the chain]'] = $r['healerCcInChain'] > 0;
    $f['[good go (tight)]'] = $r['quality'] === 'good';
    $f['[2+ of their defensives already down]'] = $r['drained'] >= 2;

    return array_keys(array_filter($f));
};
$kills = array_values(array_filter($ours, fn ($r) => $r['killLater']));
$nokill = array_values(array_filter($ours, fn ($r) => ! $r['killLater']));
$share = function (array $rows) use ($feature) {
    $c = [];
    foreach ($rows as $r) {
        foreach ($feature($r) as $f) {
            $c[$f] = ($c[$f] ?? 0) + 1;
        }
    }

    return array_map(fn ($n) => $n / max(1, count($rows)), $c);
};
$a = $share($kills);
$b = $share($nokill);
$keys = array_unique(array_merge(array_keys($a), array_keys($b)));
usort($keys, fn ($x, $y) => (($a[$y] ?? 0) - ($b[$y] ?? 0)) <=> (($a[$x] ?? 0) - ($b[$x] ?? 0)));
printf("   %-48s %8s %8s\n", 'in the go', 'kill ('.count($kills).')', 'none ('.count($nokill).')');
foreach ($keys as $k) {
    if (($a[$k] ?? 0) + ($b[$k] ?? 0) < 0.15) {
        continue;
    }
    printf("   %-48s %7d%% %7d%%\n", mb_substr($k, 0, 48), round(100 * ($a[$k] ?? 0)), round(100 * ($b[$k] ?? 0)));
}

// ---- OVERLAPPING DEFENSIVES
echo "\n=== OVERLAPPING DEFENSIVES before the first death (two on one player at once, 1s+)\n";
foreach ([true => 'WINS', false => 'LOSSES'] as $w => $lb) {
    $set = array_filter($all, fn ($g) => $g['won'] === (bool) $w);
    printf("   %s: ours %d in %d games, theirs %d\n", $lb, array_sum(array_map(fn ($g) => count($g['overlaps']['us']), $set)), count($set),
        array_sum(array_map(fn ($g) => count($g['overlaps']['them']), $set)));
}
foreach ($all as $g) {
    foreach (['us', 'them'] as $sd) {
        foreach ($g['overlaps'][$sd] as $o) {
            printf("      %s %s %-4s %s\n", $g['time'], $g['won'] ? 'W' : 'L', $sd, $o);
        }
    }
}

// ---- INTERRUPTS
echo "\n=== INTERRUPTS\n";
foreach ([true => 'WINS', false => 'LOSSES'] as $w => $lb) {
    $set = array_filter($all, fn ($g) => $g['won'] === (bool) $w);
    foreach (['us', 'them'] as $sd) {
        $x = array_merge(...array_values(array_map(fn ($g) => $g['interrupts'][$sd], $set)));
        printf("   %-6s %-4s kicks %2d | on the healer %2d | inside own go %2d\n", $lb, $sd, count($x),
            count(array_filter($x, fn ($i) => $i['onHealer'])), count(array_filter($x, fn ($i) => $i['inGo'])));
    }
}
foreach ($all as $g) {
    foreach (['us', 'them'] as $sd) {
        foreach ($g['interrupts'][$sd] as $i) {
            printf("      %s %s %-4s %s%s\n", $g['time'], $g['won'] ? 'W' : 'L', $sd, $i['text'], $i['inGo'] ? '  [in go]' : '');
        }
    }
}

// ---- PEAK BURST: the 6s with the most DK + Monk damage in each of our goes
echo "\n=== PEAK BURST: the ".PEAK_SECONDS."s of each of our goes with the most DPS damage, and their healer during it\n";
$peakRow = function (string $lb, array $rows) {
    $n = max(1, count($rows));
    $locked = array_filter($rows, fn ($r) => $r['peakHealerLocked'] >= 2.0);
    $kicked = array_filter($rows, fn ($r) => $r['peakHealerKicked'] > 0);
    $either = array_filter($rows, fn ($r) => $r['peakHealerLocked'] >= 2.0 || $r['peakHealerKicked'] > 0);
    printf("   %-30s goes %2d | peak %6sk in 6s (%d%% of the go's DPS damage) | healer locked 2s+ during it: %2d (%d%%) | healer kicked in it: %d | either: %d%% | abilities in the peak %.1f\n",
        $lb, count($rows), number_format(array_sum(array_column($rows, 'peakDamage')) / $n / 1000),
        round(100 * array_sum(array_column($rows, 'peakGoShare')) / $n), count($locked), round(100 * count($locked) / $n), count($kicked),
        round(100 * count($either) / $n), array_sum(array_map(fn ($r) => count($r['peakAbilities']), $rows)) / $n);
};
$peakRow('goes followed by a kill', array_filter($ours, fn ($r) => $r['killLater']));
$peakRow('goes not followed by a kill', array_filter($ours, fn ($r) => ! $r['killLater']));
$peakRow('goes in games won', array_filter($ours, fn ($r) => $r['won']));
$peakRow('goes in games lost', array_filter($ours, fn ($r) => ! $r['won']));
foreach ([[true, 'JOINT peak (DK and Monk each 25%+)'], [false, 'one player peak']] as [$al, $lb]) {
    $rows = array_filter($ours, fn ($r) => $r['peakJoint'] === $al);
    printf('   %-42s goes %2d, followed by a kill %d%% | peak without Touch of Death %sk
', $lb, count($rows),
        $rows ? round(100 * count(array_filter($rows, fn ($r) => $r['killLater'])) / count($rows)) : 0,
        $rows ? number_format(array_sum(array_column($rows, 'peakNoTod')) / count($rows) / 1000) : 0);
}
foreach ([[true, true, 'JOINT peak AND their healer locked/kicked'], [true, false, 'joint peak, healer free'], [false, true, 'one player, healer locked/kicked'], [false, false, 'one player, healer free']] as [$jt, $al, $lb]) {
    $rows = array_filter($ours, fn ($r) => $r['peakJoint'] === $jt && ($r['peakHealerLocked'] >= 2.0 || $r['peakHealerKicked'] > 0) === $al);
    printf('   %-42s goes %2d, followed by a kill %d%%
', $lb, count($rows),
        $rows ? round(100 * count(array_filter($rows, fn ($r) => $r['killLater'])) / count($rows)) : 0);
}
foreach ([[true, 'peak WITH healer locked 2s+ or kicked'], [false, 'peak with their healer FREE']] as [$al, $lb]) {
    $rows = array_filter($ours, fn ($r) => ($r['peakHealerLocked'] >= 2.0 || $r['peakHealerKicked'] > 0) === $al);
    printf("   %-38s goes %2d, followed by a kill %d%%\n", $lb, count($rows),
        $rows ? round(100 * count(array_filter($rows, fn ($r) => $r['killLater'])) / count($rows)) : 0);
}
echo "   --- every go: game, go start, peak starts (s into the go), peak damage, their healer locked / kicked in it, kill after?, what landed\n";
foreach ($ours as $r) {
    $top = array_slice($r['peakAbilities'], 0, 5, true);
    printf("   %s %s %6.1fs  +%4.1fs %6sk  locked %3.1fs kicked %d  %-4s  %s\n", $r['game'], $r['won'] ? 'W' : 'L', $r['from'], $r['peakFrom'],
        number_format($r['peakDamage'] / 1000), $r['peakHealerLocked'], $r['peakHealerKicked'], $r['killLater'] ? 'KILL' : '',
        implode(', ', array_map(fn ($k, $v) => $k.' '.round($v / 1000).'k', array_keys($top), $top)));
}
