<?php

// Kick priorities against the archive (2026-10-16). data/kick-priorities/priorities.json is
// drafted from Skill Capped's per-spec "Important Casts" lists. A published list is a claim; the
// archive says which of those casts players actually press and actually get stopped on. For every
// spec in the file, and every listed spell, this counts: casts begun (SPELL_CAST_START), casts
// that finished (SPELL_CAST_SUCCESS, channels included), and casts an interrupt stopped
// (SPELL_INTERRUPT's stopped spell). It also lists the spec's most-stopped casts the guide leaves
// out. Counts only, no names: the evidence file it writes is committed and the site reads it.
//
//   php -d memory_limit=2G tools/match-review/kickpriority.php [--since=2026-08-18] [--write]
//
// --write updates data/kick-priorities/evidence.json. It never edits priorities.json: a spell the
// archive never shows stopped is a proposal for the player, printed below.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const ARCHIVE = 'D:/MindCollector/arena-logs';
$root = dirname(__DIR__, 2);
$opt = fn (string $k, ?string $d = null) => array_reduce($argv, fn ($c, $a) => str_starts_with($a, "--{$k}=") ? substr($a, strlen($k) + 3) : $c, $d);
$since = $opt('since', '2026-08-18');   // Season 2 opened the week of 18 Aug 2026
$write = in_array('--write', $argv, true);

$file = json_decode(file_get_contents("{$root}/data/kick-priorities/priorities.json"), true);
$specKey = App\Models\Specialization::with('gameClass')->get()
    ->mapWithKeys(fn ($s) => [(string) $s->external_spec_id => $s->gameClass->slug.'/'.$s->slug])->all();

// Every listed name resolves to each external id the spell data holds under it, and casts are
// counted by id: a client in another language writes the name in its own language (Polymorph
// arrives as 变形术), never the id.
// The spell data names some copies with a suffix ("Hex (desc=Frog)", "Sleep Walk (desc=Green)"),
// and some cast ids are not in it at all (Polymorph's Turtle, Pig and Black Cat): an id the log
// writes under a listed name joins that name after the scan.
$base = fn (string $name) => preg_replace('/ \(desc=[^)]*\)$/', '', $name);
$idsOf = [];
foreach ($file['specs'] as $tiers) {
    foreach (array_merge($tiers['high'], $tiers['medium'], $tiers['low']) as $name) {
        $idsOf[$name] ??= App\Models\Spell::where('name', $name)->orWhere('name', 'like', $name.' (desc=%')
            ->pluck('spell_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}
$englishName = fn (int $id, string $logged) => $base(App\Models\Spell::where('spell_id', $id)->value('name') ?? $logged);

// spec key => spell id => counts; spec key => every stopped spell id (for what the list leaves out)
$count = [];
$stoppedAll = [];
$loggedName = [];
$games = 0;
foreach (glob(ARCHIVE.'/metadata/*.json') as $mf) {
    $m = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($mf)), true);
    if (! in_array($m['startInfo']['bracket'] ?? '', ['3v3', '2v2', 'Solo Shuffle', 'Rated Solo Shuffle'], true)
        || date('Y-m-d', intdiv($m['startTime'], 1000)) < $since || ! is_file(ARCHIVE."/raw/{$m['id']}.log.gz")) {
        continue;
    }
    $spec = [];
    foreach ($m['units'] as $u) {
        if (str_starts_with($u['id'], 'Player-') && isset($specKey[(string) ($u['spec'] ?? '')])) {
            $spec[$u['id']] = $specKey[(string) $u['spec']];
        }
    }
    $games++;
    $gz = gzopen(ARCHIVE."/raw/{$m['id']}.log.gz", 'r');
    while (($line = gzgets($gz)) !== false) {
        $isStart = str_contains($line, 'SPELL_CAST_START,');
        $isDone = ! $isStart && str_contains($line, 'SPELL_CAST_SUCCESS,');
        $isInt = ! $isStart && ! $isDone && str_contains($line, 'SPELL_INTERRUPT,');
        if (! ($isStart || $isDone || $isInt)) {
            continue;
        }
        $f = str_getcsv(rtrim(explode('  ', $line, 2)[1] ?? ''));
        if ($isInt) {
            $who = $spec[$f[5]] ?? null;        // the player whose cast was stopped
            $id = (int) ($f[12] ?? 0);
            if ($who && $id) {
                $count[$who][$id]['stopped'] = ($count[$who][$id]['stopped'] ?? 0) + 1;
                $stoppedAll[$who][$id] = ($stoppedAll[$who][$id] ?? 0) + 1;
                $loggedName[$id] ??= $f[13] ?? '?';
            }

            continue;
        }
        $who = $spec[$f[1]] ?? null;
        if ($who) {
            $k = $isStart ? 'begun' : 'done';
            $count[$who][(int) $f[9]][$k] = ($count[$who][(int) $f[9]][$k] ?? 0) + 1;
            $loggedName[(int) $f[9]] ??= $f[10];
        }
    }
    gzclose($gz);
}

foreach ($loggedName as $id => $logged) {
    if (isset($idsOf[$logged]) && ! in_array($id, $idsOf[$logged], true)) {
        $idsOf[$logged][] = $id;
    }
}

$evidence = ['about' => 'Counted by tools/match-review/kickpriority.php from every archived game since '.$since.'. begun = casts started with a cast time; done = casts that finished (channels included); stopped = casts an interrupt stopped. No names.',
    'since' => $since, 'games' => $games, 'builtAt' => date('c'), 'specs' => []];

echo "Archive since {$since}: {$games} games\n";
foreach ($file['specs'] as $key => $tiers) {
    echo "\n== {$key}\n";
    $listed = [];
    foreach (['high', 'medium', 'low'] as $tier) {
        foreach ($tiers[$tier] as $name) {
            $begun = $done = $stopped = 0;
            foreach ($idsOf[$name] as $id) {
                $begun += $count[$key][$id]['begun'] ?? 0;
                $done += $count[$key][$id]['done'] ?? 0;
                $stopped += $count[$key][$id]['stopped'] ?? 0;
                $listed[$id] = true;
            }
            $evidence['specs'][$key]['listed'][$name] = ['tier' => $tier, 'ids' => $idsOf[$name], 'begun' => $begun, 'done' => $done, 'stopped' => $stopped];
            if (! $idsOf[$name]) {
                echo "  !! {$name}: no spell of that name in the spell data\n";
            }
            $flag = $begun + $done === 0 ? 'NEVER CAST in the archive' : ($stopped === 0 ? ($begun + $done >= 30 ? 'never stopped in '.($begun + $done).' casts' : 'never stopped (few casts)') : '');
            printf("  %-7s %-26s begun %5d  done %5d  stopped %4d  %s\n", $tier, $name, $begun, $done, $stopped, $flag);
        }
    }
    // grouped by English name: one ability is often several ids (rule 3)
    $missing = [];
    foreach ($stoppedAll[$key] ?? [] as $id => $n) {
        if (isset($listed[$id])) {
            continue;
        }
        $name = $englishName($id, $loggedName[$id] ?? '?');
        $missing[$name]['ids'][] = $id;
        $missing[$name]['stopped'] = ($missing[$name]['stopped'] ?? 0) + $n;
        $missing[$name]['begun'] = ($missing[$name]['begun'] ?? 0) + ($count[$key][$id]['begun'] ?? 0);
        $missing[$name]['done'] = ($missing[$name]['done'] ?? 0) + ($count[$key][$id]['done'] ?? 0);
    }
    uasort($missing, fn ($a, $b) => $b['stopped'] <=> $a['stopped']);
    foreach (array_slice(array_filter($missing, fn ($x) => $x['stopped'] >= 5), 0, 6, true) as $name => $x) {
        printf("  NOT LISTED %-21s begun %5d  done %5d  stopped %4d\n", $name, $x['begun'], $x['done'], $x['stopped']);
        $evidence['specs'][$key]['notListed'][$name] = $x;
    }
}

if ($write) {
    file_put_contents("{$root}/data/kick-priorities/evidence.json", json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    echo "\nwrote data/kick-priorities/evidence.json\n";
}
