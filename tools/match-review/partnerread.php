<?php

// A teammate read: one partner's play set against the logging player's other damage partners, in
// the same team games (3v3, 2v2), split by phase of the game. Written 2026-10-07 for Chriso's read
// of Rel (Doubletapz, Cindogz): good inside a structured go, lost under pressure or when play is
// unstructured, weak on defence.
//
//   php -d memory_limit=3G tools/match-review/partnerread.php --partner=Doubletapz,Cindogz --label=Rel
//
// Per round, from the raw slice (RoundAnalysisService for the goes, deaths and defensives; the
// raw lines for when each cast happened):
//  - deaths: died, died first in a loss, and at that death his own defensives still ready;
//  - defensives: health at the press, and whether it was needed (warrant);
//  - their goes aimed at him: answered with his own defensive inside the go, and how often he died;
//  - activity: casts a minute alive inside our goes, inside their goes, and between goes;
//  - peel: his crowd control on their players while they were going.
// Output names the partner groups only; other players are pooled.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$partner = array_map('trim', explode(',', $opt('partner', 'Doubletapz,Cindogz')));
$label = $opt('label', 'Rel');

$arena = app(App\Http\Services\ArenaLogService::class);
$analysis = app(App\Http\Services\RoundAnalysisService::class);
$ccMap = app(App\Http\Services\ArenaMomentService::class)->crowdControl();
$healers = [65, 105, 256, 257, 264, 270, 1468];
$metaDir = dirname($arena->metadataPath('x'));

$rows = [];
foreach (glob($metaDir.'/*.json') as $file) {
    $meta = json_decode(file_get_contents($file), true);
    if (! in_array($meta['startInfo']['bracket'] ?? '', ['3v3', '2v2'], true)) {
        continue;
    }
    $logger = collect($meta['units'])->first(fn ($u) => (int) ($u['affiliation'] ?? 0) === 1);
    if (! $logger) {
        continue;
    }
    $mates = collect($meta['units'])->filter(fn ($u) => str_starts_with($u['id'], 'Player-') && ($u['reaction'] ?? 0) == 1
        && $u['id'] !== $logger['id'] && ! in_array((int) $u['spec'], $healers, true) && (int) $u['spec'] > 0);
    if ($mates->isEmpty()) {
        continue;
    }
    $raw = @gzdecode((string) @file_get_contents($arena->rawLogPath($meta['id'])));
    if (! $raw) {
        continue;
    }
    $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($raw)) ?: []));
    try {
        $a = $analysis->analyse($lines, $meta);
    } catch (Throwable) {
        continue;
    }
    if (! $a) {
        continue;
    }

    // Cast times per player, and their crowd control with its target.
    $t0 = null;
    $casts = [];
    $cc = [];
    foreach ($lines as $line) {
        if (! str_contains($line, 'SPELL_CAST_SUCCESS')) {
            continue;
        }
        if (! preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+):(\d+)\.(\d+)#', $line, $m)) {
            continue;
        }
        $s = mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[1], (int) $m[2], (int) $m[3]) + (float) ('0.'.$m[7]);
        $f = str_getcsv(explode('  ', $line, 2)[1] ?? '');
        $t0 ??= $s;
        $t = $s - $t0;
        $casts[$f[1]][] = $t;
        if (isset($ccMap[(int) ($f[9] ?? 0)])) {
            $cc[$f[1]][] = [$t, $f[5]];
        }
    }
    // The analysis clock starts at the slice's first timestamped line; casts start at the first
    // cast. The gap is small (gates open within a second or two) and is ignored.

    $sideOf = collect($a['players'])->mapWithKeys(fn ($p) => [$p['guid'] => $p['side']]);
    $ourGoes = array_values(array_filter($a['goes'], fn ($g) => $g['side'] === 'us'));
    $theirGoes = array_values(array_filter($a['goes'], fn ($g) => $g['side'] === 'them'));
    $in = fn (float $t, array $goes) => collect($goes)->contains(fn ($g) => $t >= $g['from'] && $t <= $g['to']);
    $span = fn (array $goes, float $alive) => array_sum(array_map(fn ($g) => max(0, min($g['to'], $alive) - min($g['from'], $alive)), $goes));
    $ourDeaths = array_values(array_filter($a['deaths'], fn ($d) => $d['side'] === 'us'));

    foreach ($mates as $u) {
        $g = $u['id'];
        $name = explode('-', $u['name'])[0];
        $alive = (float) ($a['breakdown'][$g]['alive'] ?? 0);
        if ($alive < 30) {
            continue;
        }
        $mine = $casts[$g] ?? [];
        $phase = ['ours' => 0, 'theirs' => 0, 'between' => 0];
        foreach ($mine as $t) {
            if ($t > $alive) {
                continue;
            }
            $phase[$in($t, $theirGoes) ? 'theirs' : ($in($t, $ourGoes) ? 'ours' : 'between')]++;
        }
        $tTheirs = $span($theirGoes, $alive);
        $tOurs = $span($ourGoes, $alive);
        $tBetween = max(1, $alive - $tTheirs - $tOurs);

        $onHim = array_values(array_filter($theirGoes, fn ($go) => ($go['target'] ?? null) === $g));
        $defs = array_values(array_filter($a['defensives']['us']['rows'] ?? [], fn ($d) => $d['who'] === $g));
        $answered = count(array_filter($onHim, fn ($go) => collect($defs)->contains(fn ($d) => $d['t'] >= $go['from'] && $d['t'] <= $go['to'])));
        $peel = count(array_filter($cc[$g] ?? [], fn ($c) => $in($c[0], $theirGoes) && ($sideOf[$c[1]] ?? null) === 'them'));
        $first = $ourDeaths[0] ?? null;
        $readyAtDeath = null;
        $lockedAtDeath = null;
        $healerAtDeath = null;
        if ($first && $first['who'] === $g && isset($first['answers']['rows'])) {
            $readyAtDeath = count(array_filter($first['answers']['rows'], fn ($r) => $r['who'] === $g && in_array($r['kind'], ['defensive', 'trinket'], true) && $r['state'] === 'ready'));
            // Locked out in the last 3 seconds before he died: the buttons were there, he could not press them.
            $iv = $first['answers']['lockout'][$g]['longest'] ?? null;
            $lockedAtDeath = $iv && $iv[1] >= $first['t'] - 3;
            $healerAtDeath = $first['healer']['state'] ?? null;
        }

        $rows[] = [
            'group' => in_array($name, $partner, true) ? $label : 'Other partners',
            'name' => $name,
            'spec' => (int) $u['spec'],
            'won' => $a['won'],
            'diedFirstInLoss' => ! $a['won'] && $first && $first['who'] === $g,
            'lost' => ! $a['won'],
            'readyAtDeath' => $readyAtDeath,
            'lockedAtDeath' => $lockedAtDeath,
            'healerAtDeath' => $healerAtDeath,
            'defs' => count($defs),
            'defHp' => array_values(array_filter(array_column($defs, 'hp'), 'is_numeric')),
            'defNeeded' => count(array_filter($defs, fn ($d) => ! empty($d['needed']))),
            'goesOnHim' => count($onHim),
            'answered' => $answered,
            'killedInGo' => count(array_filter($onHim, fn ($go) => ($go['kill'] ?? null) === $g)),
            'cpmOurs' => $tOurs > 0 ? $phase['ours'] / ($tOurs / 60) : null,
            'cpmTheirs' => $tTheirs > 0 ? $phase['theirs'] / ($tTheirs / 60) : null,
            'cpmBetween' => $phase['between'] / ($tBetween / 60),
            'peelPerTheirGo' => count($theirGoes) ? $peel / count($theirGoes) : null,
            'damagePerMin' => array_sum(array_column($a['breakdown'][$g]['damage'] ?? [], 'amount')) / ($alive / 60),
        ];
    }
}

$median = function (array $v) {
    $v = array_values(array_filter($v, fn ($x) => $x !== null));
    sort($v);

    return $v ? $v[intdiv(count($v) - 1, 2)] : null;
};
$report = function (string $title, $set) use ($median) {
    $n = $set->count();
    $losses = $set->where('lost', true)->count();
    $deaths = $set->filter(fn ($r) => $r['readyAtDeath'] !== null);
    $goes = $set->sum('goesOnHim');
    printf("\n== %s: %d rounds, %d players, won %.0f%%\n", $title, $n, $set->pluck('name')->unique()->count(), $n ? 100 * $set->where('won', true)->count() / $n : 0);
    printf("  died first in a loss          %d of %d losses (%.0f%%)\n", $set->where('diedFirstInLoss', true)->count(), $losses, $losses ? 100 * $set->where('diedFirstInLoss', true)->count() / $losses : 0);
    printf("  own defensives still ready     %.1f on average at those deaths (%d deaths with an answer sheet)\n", $deaths->avg('readyAtDeath') ?? 0, $deaths->count());
    printf("  their goes aimed at him        %d; answered with his own defensive inside the go %.0f%%; died in it %.0f%%\n", $goes, $goes ? 100 * $set->sum('answered') / $goes : 0, $goes ? 100 * $set->sum('killedInGo') / $goes : 0);
    printf("  health at his defensive press  median %s%% (%d presses, needed %.0f%%)\n", $median($set->flatMap(fn ($r) => $r['defHp'])->all()) ?? '-', $set->sum('defs'), $set->sum('defs') ? 100 * $set->sum('defNeeded') / $set->sum('defs') : 0);
    // Casts a minute by phase was tried and dropped (2026-10-07): short windows and automatic
    // casts (Soul Fragment) gave 180-660 a minute between goes, which measures nothing.
    $firsts = $set->filter(fn ($r) => $r['lockedAtDeath'] !== null);
    $free = $firsts->where('lockedAtDeath', false);
    printf("  at those deaths: locked out %d of %d; when free, %.1f own defensives still ready\n", $firsts->where('lockedAtDeath', true)->count(), $firsts->count(), $free->avg('readyAtDeath') ?? 0);
    printf("  our healer at those deaths: %s\n", $firsts->countBy('healerAtDeath')->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));
    printf("  crowd control on them during their goes: %.2f per go (median)\n", $median($set->pluck('peelPerTheirGo')->all()));
};

$all = collect($rows);
$report($label.' (all)', $all->where('group', $label));
foreach ($all->where('group', $label)->groupBy('spec') as $spec => $set) {
    $report("{$label} as spec {$spec}", $set);
    $report("other partners of spec {$spec}", $all->where('group', 'Other partners')->where('spec', $spec));
}
$report('Other damage partners (all)', $all->where('group', 'Other partners'));
foreach ($all->where('group', 'Other partners')->groupBy('name')->filter(fn ($s) => $s->count() >= 15) as $name => $set) {
    $report("partner {$name}", $set);
}
