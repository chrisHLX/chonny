<?php
// One player's games split into groups by who they played with, read side by side from the stored
// per-game analysis (arena_rounds.payload['analysis']), so no raw log is parsed. The question behind
// it (2026-10-01): "I won with one partner and lost with everyone else; was it me?" A group is a
// date plus, optionally, a partner who must or must not be in the game.
//
//   php -d memory_limit=1G tools/match-review/sessionread.php --user=2 --me=Skylake \
//       --group="With Rastic:2026-09-30:+Rastic" --group="LFG 30 Sep:2026-09-30:-Rastic" \
//       --group="LFG 1 Oct:2026-10-01" [--bracket=3v3] [--detail]
//
//   --group=Label:YYYY-MM-DD[:+Name|-Name]   +Name: that partner was in it; -Name: they were not.
//   --detail   print every go of every game as its chain of presses.
//
// Output names other players: like the rest of this folder's output, it is not committed.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Services\PlayerExperienceService;
use App\Models\ArenaRound;

$opt = fn (string $k, ?string $default = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $default);
$userId = (int) $opt('user', '2');
$meName = $opt('me', 'Skylake');
$bracket = $opt('bracket', '3v3');
$detail = in_array('--detail', $argv, true);
$defs = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--group=')) {
        $p = explode(':', substr($a, 8));
        $defs[] = ['label' => $p[0], 'date' => $p[1], 'partner' => $p[2] ?? null];
    }
}
if (! $defs) {
    exit("Give at least one --group=Label:YYYY-MM-DD[:+Name|-Name]\n");
}

$xpSvc = app(PlayerExperienceService::class);
$short = fn ($n) => explode('-', $n)[0];
$xp = fn ($n) => (($x = $xpSvc->cached($n)) && ($x['found'] ?? false)) ? $x : null;
$xpCell = fn ($n) => ($x = $xp($n)) ? (($x['gladSeasons'] > 0 ? $x['gladSeasons'].'xG ' : '').($x['exp3v3'] ?? '?')) : '?';

$groups = array_fill_keys(array_column($defs, 'label'), []);
$rounds = ArenaRound::where('user_id', $userId)->whereIn(\DB::raw('date(played_at)'), array_unique(array_column($defs, 'date')))
    ->when($bracket !== 'all', fn ($q) => $q->where('bracket', $bracket))->orderBy('played_at')->get();

foreach ($rounds as $r) {
    $a = $r->payload['analysis'] ?? null;
    $players = collect($a['players'] ?? [])->keyBy('guid');
    $me = $players->first(fn ($p) => str_starts_with($p['name'], $meName.'-'));
    if (! $a || ! $me) {
        continue;
    }
    $mine = $me['guid'];
    $side = $me['side'];
    $partners = $players->where('side', $side)->reject(fn ($p) => $p['guid'] === $mine);
    $date = substr((string) $r->played_at, 0, 10);
    $label = null;
    foreach ($defs as $d) {
        if ($d['date'] !== $date) {
            continue;
        }
        $has = $d['partner'] ? $partners->contains(fn ($p) => str_starts_with($p['name'], substr($d['partner'], 1).'-')) : null;
        if ($d['partner'] === null || ($d['partner'][0] === '+' && $has) || ($d['partner'][0] === '-' && ! $has)) {
            $label = $d['label'];
            break;
        }
    }
    if ($label === null) {
        continue;
    }

    $ourGoes = collect($a['goes'])->where('side', $side);
    $theirGoes = collect($a['goes'])->reject(fn ($x) => $x['side'] === $side);
    $deaths = collect($a['deaths'])->sortBy('t');
    $ourDeath = $deaths->first(fn ($d) => $d['side'] === $side);
    $myRows = collect($a['defensives'][$side]['rows'] ?? [])->where('who', $mine);
    $before = fn (string $spell) => $ourDeath ? $myRows->where('spell', $spell)->filter(fn ($x) => $x['t'] <= $ourDeath['t'])->max('t') : null;
    $lastPs = $before('Pain Suppression');
    $lastMed = $before("Gladiator's Medallion");

    $groups[$label][] = [
        'time' => substr((string) $r->played_at, 11, 5),
        'won' => $a['won'],
        'dur' => $r->payload['metadata']['durationInSeconds'] ?? 0,
        'mmr' => ($a['mmr']['us'] ?? '?').'/'.($a['mmr']['them'] ?? '?'),
        'partners' => $partners->map(fn ($p) => $p['spec'].' ('.$xpCell($p['name']).')')->implode(', '),
        'enemy' => $players->reject(fn ($p) => $p['side'] === $side)->sortByDesc('healer')->map(fn ($p) => $p['spec'].' ('.$xpCell($p['name']).')')->implode(', '),
        'themGlad' => $players->reject(fn ($p) => $p['side'] === $side)->sum(fn ($p) => $xp($p['name'])['gladSeasons'] ?? 0),
        'ourGoes' => $ourGoes->count(),
        'ourKills' => $ourGoes->filter(fn ($x) => $x['kill'] || $x['killLater'])->count(),
        'theirGoes' => $theirGoes->count(),
        'theirKills' => $theirGoes->filter(fn ($x) => $x['kill'] || $x['killLater'])->count(),
        'firstOurs' => ($f = $deaths->first()) && $f['side'] === $side,
        'meDiedFirst' => ($f = $deaths->first()) && $f['who'] === $mine,
        'deathSpec' => $ourDeath ? ($players[$ourDeath['who']]['spec'] ?? '?') : null,
        // At our first death: was I under lockout, or out of it under 3s.
        'meLockedAtDeath' => $ourDeath ? ($ourDeath['healer']['state'] === 'locked' || ($ourDeath['healer']['endedAgo'] ?? 99) <= 3) : null,
        'psAgo' => $lastPs !== null ? $ourDeath['t'] - $lastPs : null,
        // Medallion is a 120s cooldown; "up" means not pressed in the 120s before the death.
        'medUp' => $ourDeath ? ($lastMed === null || $ourDeath['t'] - $lastMed >= 120) : null,
        'myHealerCc' => $ourGoes->filter(fn ($x) => in_array($mine, $x['healerCcBy'] ?? [], true))->count(),
        'theirHealerLockedAtPeak' => $ourGoes->filter(fn ($x) => ($x['peak']['healerLocked'] ?? 0) >= 2)->count(),
        'meLockedAtOurCds' => $ourGoes->filter(fn ($x) => $x['ownHealerLockedAtCds'])->count(),
        'meLockedAtTheirPeak' => $theirGoes->filter(fn ($x) => ($x['peak']['healerLocked'] ?? 0) >= 2)->count(),
        'myLockout' => $a['lockout'][$mine] ?? 0,
        'chains' => $ourGoes->map(fn ($x) => 'US   '.round($x['from']).'s '.($x['kill'] || $x['killLater'] ? 'KILL ' : '').$x['chain'])
            ->merge($theirGoes->map(fn ($x) => 'THEM '.round($x['from']).'s '.($x['kill'] || $x['killLater'] ? 'KILL ' : '').'(me locked '.$x['peak']['healerLocked'].'s at their peak) '.$x['chain']))->all(),
    ];
}

$pct = fn ($n, $d) => $d > 0 ? round(100 * $n / $d).'%' : '-';
foreach ($groups as $label => $games) {
    $c = collect($games);
    if ($c->isEmpty()) {
        echo "\n=== {$label}: no games\n";

        continue;
    }
    $won = $c->where('won', true)->count();
    $lost = $c->where('won', false);
    echo "\n=== {$label}: ".$c->count()." games, {$won}-".$lost->count()."\n";
    foreach ($games as $x) {
        printf("%s %s %3ds mmr %-9s | with %s\n      vs %s\n      goes us %d (kill %d) them %d (kill %d) | our first death %s | me locked at it %s | last PS %s | medallion %s\n",
            $x['time'], $x['won'] ? 'W' : 'L', $x['dur'], $x['mmr'], $x['partners'], $x['enemy'],
            $x['ourGoes'], $x['ourKills'], $x['theirGoes'], $x['theirKills'], $x['deathSpec'] ?? '-',
            $x['meLockedAtDeath'] === null ? '-' : ($x['meLockedAtDeath'] ? 'yes' : 'no'),
            $x['psAgo'] === null ? ($x['deathSpec'] ? 'none' : '-') : round($x['psAgo']).'s before',
            $x['medUp'] === null ? '-' : ($x['medUp'] ? 'up' : 'spent'));
        if ($detail) {
            foreach ($x['chains'] as $ch) {
                echo "         {$ch}\n";
            }
        }
    }
    $og = $c->sum('ourGoes');
    $tg = $c->sum('theirGoes');
    $mins = $c->sum('dur') / 60;
    $withDeath = $c->filter(fn ($x) => $x['deathSpec'] !== null);
    echo "--- {$label}\n";
    printf("  won %s | enemy Gladiator seasons per game %.1f | %.0f minutes\n", $pct($won, $c->count()), $c->avg('themGlad'), $mins);
    printf("  our goes %d, followed by a kill %s | their goes %d, killed one of us %s\n", $og, $pct($c->sum('ourKills'), $og), $tg, $pct($c->sum('theirKills'), $tg));
    printf("  first death ours %s | %s died first %d times\n", $pct($c->where('firstOurs', true)->count(), $c->count()), $meName, $c->where('meDiedFirst', true)->count());
    printf("  our goes: my CC on their healer %s, their healer locked 2s+ at our peak %s, me locked as our cooldowns went %s\n",
        $pct($c->sum('myHealerCc'), $og), $pct($c->sum('theirHealerLockedAtPeak'), $og), $pct($c->sum('meLockedAtOurCds'), $og));
    printf("  their goes: me locked 2s+ at their peak %s | my lockout %.1fs a minute\n", $pct($c->sum('meLockedAtTheirPeak'), $tg), $c->sum('myLockout') / max(1, $mins));
    printf("  at our first death (%d games): me locked or just out %s | my Medallion up %s | last Pain Suppression, median %s s before\n",
        $withDeath->count(), $pct($withDeath->where('meLockedAtDeath', true)->count(), $withDeath->count()),
        $pct($withDeath->where('medUp', true)->count(), $withDeath->count()),
        ($m = $withDeath->pluck('psAgo')->filter(fn ($v) => $v !== null)->median()) !== null ? round($m) : '-');
}
