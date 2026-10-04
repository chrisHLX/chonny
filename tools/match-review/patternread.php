<?php
// Every stored game of one user, pooled by the character that logged it (players[].logger), and
// split by bracket, testing the per-session findings across all of them at once (2026-10-02). The
// question behind it: which of the patterns one session found still hold over every game we have?
// Reads arena_rounds.payload['analysis'] only, so no raw log is parsed.
//
//   php -d memory_limit=1G tools/match-review/patternread.php [--user=2]
//
// A go "followed by a kill" in a lost game is close to impossible by definition (a loss is our
// team dying first), so the wins/losses kill split is shown for reference only: compare the
// conditions (healer locked at our peak, drain, our healer locked at our cooldowns) across ALL goes.
// Output names other players: like the rest of this folder's output, it is not committed.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\ArenaRound;

$pct = fn ($n, $d) => $d > 0 ? sprintf('%d/%d (%d%%)', $n, $d, round(100 * $n / $d)) : '-';
$userId = (int) array_reduce($argv, fn ($c, $a) => str_starts_with($a, '--user=') ? substr($a, 7) : $c, '2');
$rows = ArenaRound::where('user_id', $userId)->orderBy('played_at')->get();

$chars = [];
foreach ($rows as $r) {
    $a = $r->payload['analysis'] ?? null;
    if (! $a) continue;
    $players = collect($a['players'])->keyBy('guid');
    $me = $players->first(fn ($p) => ! empty($p['logger']));
    if (! $me) continue;
    $key = explode('-', $me['name'])[0].' ('.$me['spec'].')';
    $bk = $r->bracket === 'Rated Solo Shuffle' ? 'shuffle' : $r->bracket;
    $chars[$key][$bk][] = [$r, $a, $me, $players];
}

foreach ($chars as $name => $brackets) {
    foreach ($brackets as $bk => $games) {
        $n = count($games);
        if ($n < 5) continue;
        $w = collect($games)->filter(fn ($g) => $g[1]['won'])->count();
        echo "\n================ {$name} — {$bk}: {$n} games, {$w}-".($n - $w)."\n";
        $s = array_fill_keys(['gL', 'gLk', 'gF', 'gFk', 'cdL', 'cdLk', 'cdF', 'cdFk', 'd0', 'd0k', 'd2', 'd2k', 'goesW', 'goesL', 'killW', 'killL',
            'deathsL', 'healLockedL', 'meFirstL', 'medUpL', 'medN', 'outW', 'defW', 'outL', 'defL', 'lockW', 'lockL', 'minW', 'minL',
            'ovW', 'ovL', 'kickHealer', 'kicks', 'myCcGo', 'myCcGoK', 'noMyCcGo', 'noMyCcGoK', 'myDeath', 'myDeathNoBig'], 0);
        $myDeathDefs = [];
        foreach ($games as [$r, $a, $me, $players]) {
            $side = $me['side'];
            $won = $a['won'];
            $mins = ($r->payload['metadata']['durationInSeconds'] ?? 0) / 60;
            $healer = $players->first(fn ($p) => $p['side'] === $side && $p['healer']);
            foreach (collect($a['goes'])->where('side', $side) as $g) {
                $k = $g['kill'] || $g['killLater'];
                $won ? $s['goesW']++ : $s['goesL']++;
                if ($won) { $s['killW'] += $k; } else { $s['killL'] += $k; }
                $locked = ($g['peak']['healerLocked'] ?? 0) >= 2 || ! empty($g['peak']['healerKicked']);
                if ($locked) { $s['gL']++; $s['gLk'] += $k; } else { $s['gF']++; $s['gFk'] += $k; }
                if ($g['ownHealerLockedAtCds']) { $s['cdL']++; $s['cdLk'] += $k; } else { $s['cdF']++; $s['cdFk'] += $k; }
                if (($g['drained'] ?? 0) >= 2) { $s['d2']++; $s['d2k'] += $k; } else { $s['d0']++; $s['d0k'] += $k; }
                if (in_array($me['guid'], $g['healerCcBy'] ?? [], true)) { $s['myCcGo']++; $s['myCcGoK'] += $k; } else { $s['noMyCcGo']++; $s['noMyCcGoK'] += $k; }
            }
            $deaths = collect($a['deaths'])->sortBy('t');
            $ourDeath = $deaths->first(fn ($d) => $d['side'] === $side);
            if (! $won && $ourDeath) {
                $s['deathsL']++;
                $s['healLockedL'] += ($ourDeath['healer']['state'] ?? '') === 'locked' || ($ourDeath['healer']['endedAgo'] ?? 99) <= 1;
                $s['meFirstL'] += $ourDeath['who'] === $me['guid'];
            }
            // my own deaths: which of my defensives went out in the 30s before
            foreach ($deaths->where('who', $me['guid']) as $d) {
                $s['myDeath']++;
                $mine = collect($a['defensives'][$side]['rows'] ?? [])->where('who', $me['guid'])->filter(fn ($x) => $x['t'] <= $d['t'] && $x['t'] >= $d['t'] - 30)->pluck('spell')->all();
                $myDeathDefs[] = ($won ? 'W ' : 'L ').substr((string) $r->played_at, 5, 11).' at '.round($d['t']).'s: '.($mine ? implode(', ', $mine) : 'NOTHING pressed in 30s');
                if (! $mine) $s['myDeathNoBig']++;
            }
            if ($ourDeath) {
                $last = collect($a['defensives'][$side]['rows'] ?? [])->where('who', $me['guid'])->where('spell', "Gladiator's Medallion")->filter(fn ($x) => $x['t'] <= $ourDeath['t'])->max('t');
                $s['medN']++;
                $s['medUpL'] += ($last === null || $ourDeath['t'] - $last >= 120);
            }
            $mineRows = collect($a['defensives'][$side]['rows'] ?? [])->where('who', $me['guid']);
            if ($won) { $s['defW'] += $mineRows->count(); $s['outW'] += $mineRows->where('outside', true)->count(); $s['lockW'] += $a['lockout'][$me['guid']] ?? 0; $s['minW'] += $mins; }
            else { $s['defL'] += $mineRows->count(); $s['outL'] += $mineRows->where('outside', true)->count(); $s['lockL'] += $a['lockout'][$me['guid']] ?? 0; $s['minL'] += $mins; }
        }
        echo "OUR GOES  followed by a kill: in wins ".$pct($s['killW'], $s['goesW']).", in losses ".$pct($s['killL'], $s['goesL'])."\n";
        echo "  their healer locked 2s+/kicked in our hardest 6s: ".$pct($s['gLk'], $s['gL'])." killed | free: ".$pct($s['gFk'], $s['gF'])." killed  [share of goes landing on a FREE healer: ".$pct($s['gF'], $s['gL'] + $s['gF'])."]\n";
        echo "  our healer locked as our cooldowns went: ".$pct($s['cdLk'], $s['cdL'])." killed | free: ".$pct($s['cdFk'], $s['cdF'])." killed\n";
        echo "  2+ of their defensives already down: ".$pct($s['d2k'], $s['d2'])." killed | 0-1: ".$pct($s['d0k'], $s['d0'])." killed\n";
        echo "  MY CC on their healer in the go: ".$pct($s['myCcGoK'], $s['myCcGo'])." killed | not: ".$pct($s['noMyCcGoK'], $s['noMyCcGo'])." killed\n";
        echo "LOSSES  our healer locked (or within 1s) at our first death: ".$pct($s['healLockedL'], $s['deathsL'])." | I died first: ".$pct($s['meFirstL'], $s['deathsL'])."\n";
        echo "  my Medallion up at our first death (any game with one): ".$pct($s['medUpL'], $s['medN'])."\n";
        echo "ME  defensives outside their goes: wins ".$pct($s['outW'], $s['defW']).", losses ".$pct($s['outL'], $s['defL'])."\n";
        printf("  my lockout per minute: wins %.1fs, losses %.1fs\n", $s['lockW'] / max(1, $s['minW']), $s['lockL'] / max(1, $s['minL']));
        echo "  my deaths: {$s['myDeath']}, with none of my defensives in the 30s before: {$s['myDeathNoBig']}\n";
        foreach (array_slice($myDeathDefs, 0, 12) as $l) echo "     $l\n";
    }
}
