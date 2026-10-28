<?php
/**
 * Our damage against what an in-game meter counts, per player, for one raw log (2026-10-28).
 *
 *   php tools/match-review/dmgcheck.php D:/MindCollector/arena-logs/raw/{match}.log.gz
 *
 * "Ours" is what reached health: the damage field of every hit onto an enemy player (the card until
 * version 17, RoundAnalysisService::breakdown()). Then what a meter also counts, and the card since:
 * the part of a hit a shield took (the damage line's absorbed field), hits a shield took whole
 * (SPELL_MISSED ABSORB), and damage onto
 * enemy pets, totems and summons. A player's own spells only: pet damage is the same both ways.
 * Healing: effective (amount less overheal) and absorbs, both onto their own team.
 */
$path = $argv[1] ?? exit("Give a raw log path.\n");
$in = gzopen($path, 'r');
$team = []; $name = []; $me = null; $rows = [];
$swingOnly = []; $landed = [];   // melee logged as SWING_DAMAGE alone counts too, as in the breakdown
// A swing a shield took whole can be logged as a miss, a damage line of 0, or both: the miss counts only alone.
$swingMissed = []; $swingSeen = [];
$add = function ($who, $k, $v) use (&$rows) { $rows[$who][$k] = ($rows[$who][$k] ?? 0) + $v; };
while (($line = gzgets($in)) !== false) {
    $body = substr($line, strpos($line, '  ') + 2);
    $event = strtok($body, ',');
    $f = str_getcsv(rtrim($body));
    if ($event === 'ARENA_MATCH_START') { echo 'Bracket: ', $f[3] ?? '?', "\n"; }
    if ($event === 'COMBATANT_INFO') { $team[$f[1]] = $f[2]; continue; }
    if (! in_array($event, ['SWING_DAMAGE', 'SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'RANGE_DAMAGE', 'SWING_DAMAGE_LANDED', 'SPELL_MISSED', 'SPELL_PERIODIC_MISSED', 'SWING_MISSED', 'RANGE_MISSED', 'SPELL_HEAL', 'SPELL_PERIODIC_HEAL', 'SPELL_ABSORBED'], true)) continue;
    $src = $f[1]; $dst = $f[5];
    if (str_starts_with($src, 'Player-')) { $name[$src] = explode('-', $f[2])[0]; }
    if (str_starts_with($dst, 'Player-')) { $name[$dst] = explode('-', $f[6])[0]; }
    if ($event === 'SPELL_ABSORBED') {
        $caster = $f[count($f) - 10] ?? ''; $amt = (int) ($f[count($f) - 3] ?? 0);
        if (isset($team[$caster], $team[$dst]) && $team[$caster] === $team[$dst]) $add($caster, 'absorbs', $amt);
        continue;
    }
    if (! isset($team[$src])) continue;
    // The logging player: their own flags carry affiliation "mine" (0x1).
    if ($me === null && (hexdec($f[3]) & 0x1) && str_starts_with($src, 'Player-')) { $me = $src; }
    // A target that is not a player (a pet, totem or summon) is on a side by its reaction to the
    // logging player: hostile (0x40) is the other team, friendly (0x10) the logger's.
    $dstTeam = $team[$dst] ?? null;
    if ($dstTeam === null && $me !== null && $dst !== '0000000000000000') {
        $flags = hexdec($f[7] ?? '0');
        $other = array_values(array_diff(array_unique($team), [$team[$me]]))[0] ?? null;
        $dstTeam = ($flags & 0x40) ? $other : (($flags & 0x10) ? $team[$me] : null);
    }
    if (str_contains($event, 'HEAL')) {
        if (($team[$dst] ?? null) === $team[$src]) {
            $amt = (int) $f[count($f) - 5]; $over = (int) $f[count($f) - 3];
            $add($src, 'heal', $amt - $over); $add($src, 'overheal', $over);
        }
        continue;
    }
    $swing = str_starts_with($event, 'SWING');
    $enemyPlayer = isset($team[$dst]) && $team[$dst] !== $team[$src];
    $at = strtok($line, ' ').'|'.substr($line, strpos($line, ' ') + 1, 15).'|'.$src.'|'.$dst;
    if (str_contains($event, 'MISSED')) {
        $i = array_search('ABSORB', $f, true);
        if ($i !== false && is_numeric($f[$i + 2] ?? null) && $enemyPlayer) {
            if ($swing) { $swingMissed[$at] = [$src, (int) $f[$i + 2]]; } else { $add($src, 'missedAbsorb', (int) $f[$i + 2]); }
        }
        continue;
    }
    $swingSeen[$at] = true;
    $amount = (int) $f[count($f) - ($swing ? 10 : 11)];
    $absorbed = (int) $f[count($f) - ($swing ? 4 : 5)];
    $key = strtok($line, ' ').'|'.substr($line, strpos($line, ' ') + 1, 15).'|'.$src.'|'.$dst.'|'.$amount;
    if ($event === 'SWING_DAMAGE') {
        if ($enemyPlayer) { $swingOnly[$key] = [$src, $amount, $absorbed]; }
        continue;
    }
    if ($event === 'SWING_DAMAGE_LANDED') { $landed[$key] = true; }
    if ($enemyPlayer) { $add($src, 'ours', $amount); $add($src, 'absorbed', $absorbed); }
    elseif (! isset($team[$dst]) && $dstTeam !== null && $dstTeam !== $team[$src]) { $add($src, 'nonPlayer', $amount + $absorbed); }
}
foreach (array_diff_key($swingOnly, $landed) as [$src, $amount, $absorbed]) { $add($src, 'ours', $amount); $add($src, 'absorbed', $absorbed); }
foreach (array_diff_key($swingMissed, $swingSeen) as [$src, $amount]) { $add($src, 'missedAbsorb', $amount); }
$k = fn ($n) => $n >= 1e6 ? round($n / 1e6, 2).'M' : round($n / 1e3).'k';
printf("%-14s %8s %9s %9s %10s %9s | %9s %9s %9s\n", 'player', 'ours', '+shield', '+whole', 'pets etc', 'meter?', 'heal', 'absorbs', 'overheal');
foreach ($rows as $g => $r) {
    if (! str_starts_with($g, 'Player-')) continue;
    $np = $r['nonPlayer'] ?? 0;
    $meter = ($r['ours'] ?? 0) + ($r['absorbed'] ?? 0) + ($r['missedAbsorb'] ?? 0);
    printf("%-14s %8s %9s %9s %10s %9s | %9s %9s %9s\n", mb_substr($name[$g] ?? $g, 0, 14), $k($r['ours'] ?? 0), '+'.$k($r['absorbed'] ?? 0), '+'.$k($r['missedAbsorb'] ?? 0), '('.$k($np).')', $k($meter),
        $k($r['heal'] ?? 0), $k($r['absorbs'] ?? 0), $k($r['overheal'] ?? 0));
}
echo "meter? = ours + the shield's part of hits + hits a shield took whole. 'pets etc' is damage onto the other team's pets, totems and summons, shown apart.\n";
