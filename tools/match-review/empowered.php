<?php

/**
 * Empowered casts for every spec (docs/class-mechanics.md). Which abilities carry a spec's output,
 * which of its own buffs change their hits, which press gives each buff, and what stacking them does.
 *
 *   php -d memory_limit=3G tools/match-review/empowered.php scan <dir>     # every raw log -> per-spec hit files in <dir> (about 15 min)
 *   php -d memory_limit=3G tools/match-review/empowered.php report <dir>   # proposes candidates, with each tooltip, into <dir>/candidates.txt
 *   php -d memory_limit=3G tools/match-review/empowered.php build <dir>    # data/empowered-casts/{class}/{spec}.json from reviewed.json
 *   php tools/match-review/empowered.php spike "<Name-Realm-US>" <match> "<Ability>"   # one game, every cast of one ability, hit by hit
 *
 * The scan and the report never decide anything. A buff reaches a page only when it is approved in
 * data/empowered-casts/reviewed.json, the same split as tagaudit.php: measuring proposes, a person or
 * a reviewing session reads the tooltip and decides. Without that, Find Minerals is a ×1.18 buff.
 *
 * <dir> holds working files and is not committed: it can hold player names.
 *
 * Reads the raw archive, so it runs on the PC and bakes a committed artifact (rule 14).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('REPO', dirname(__DIR__, 2));
const ARCHIVE = 'D:/MindCollector/arena-logs';
const HEALERS = [65, 105, 256, 257, 264, 270, 1468];
const MIN_ON = 60;        // hits with a buff up, and hits without it
const MIN_PLAYERS = 3;    // players seen with a buff up
const MIN_LIFT = 1.07;    // below this a buff is lost among the noise of a pooled comparison
const HALF_LIFT = 1.03;   // each half of the players must show it too

$mode = $argv[1] ?? '';
$dir = rtrim($argv[2] ?? '', '/\\');

function secs(string $ts): float
{
    static $cache = [];
    [$whole, $frac] = explode('.', $ts, 2);
    $cache[$whole] ??= strtotime($whole);

    return $cache[$whole] + ((int) substr($frac, 0, 3)) / 1000;
}

function median(array $a)
{
    sort($a);

    return $a ? $a[intdiv(count($a), 2)] : null;
}

function bootLaravel(): void
{
    require REPO.'/vendor/autoload.php';
    $app = require REPO.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

if ($mode === 'scan') {
    scan($dir);
} elseif ($mode === 'report' || $mode === 'build') {
    bootLaravel();
    $mode === 'report' ? report($dir) : build($dir);
} elseif ($mode === 'spike') {
    spike($argv[2], $argv[3], $argv[4]);
} else {
    fwrite(STDERR, "scan|report|build <dir>, or spike <player> <match> <ability>\n");
    exit(1);
}

function scan(string $dir): void
{
    @mkdir($dir, 0777, true);
    array_map('unlink', glob($dir.'/*.hits') ?: []);
    $handles = [];
    $meta = [];    // per spec: abilities, sets, games, buff facts
    $read = 0;
    foreach (glob(ARCHIVE.'/metadata/*.json') as $mf) {
        $m = json_decode(file_get_contents($mf), true);
        $rawPath = ARCHIVE.'/raw/'.($m['id'] ?? '').'.log.gz';
        if (! is_file($rawPath)) {
            continue;
        }
        $raw = @gzdecode(file_get_contents($rawPath));
        if ($raw === false) {
            continue;
        }
        $players = [];
        foreach ($m['units'] ?? [] as $u) {
            $spec = (int) ($u['spec'] ?? 0);
            if ($spec > 0 && str_starts_with($u['id'] ?? '', 'Player-')) {
                $players[$u['id']] = ['name' => $u['name'], 'spec' => $spec];
            }
        }
        $state = [];
        foreach ($players as $g => $p) {
            $spec = $p['spec'];
            $meta[$spec] ??= ['abilities' => [], 'ids' => [], 'setIds' => ['' => 0], 'sets' => [[]], 'games' => [], 'applied' => [], 'links' => [], 'spent' => [], 'lengths' => [], 'buffIds' => [], 'seconds' => [], 'rounds' => 0];
            $meta[$spec]['games'][] = [$p['name'], $m['id']];
            $meta[$spec]['rounds']++;
            $state[$g] = ['spec' => $spec, 'game' => count($meta[$spec]['games']) - 1, 'up' => [], 'set' => 0, 'snap' => [], 'press' => [], 'made' => [], 'open' => [], 'damp' => 0];
        }
        $teams = [];
        $start = null;
        $last = 0.0;
        foreach (explode("\n", $raw) as $line) {
            $p = strpos($line, '  ');
            if ($p === false) {
                continue;
            }
            $body = rtrim(substr($line, $p + 2));
            if (str_starts_with($body, 'COMBATANT_INFO,')) {
                $x = explode(',', $body, 4);
                $teams[$x[1]] = $x[2];

                continue;
            }
            if (! str_starts_with($body, 'SPELL_') && ! str_starts_with($body, 'RANGE_')) {
                continue;
            }
            $f = str_getcsv($body, ',', '"', '');
            $ev = $f[0];
            $src = $f[1] ?? '';
            $dst = $f[5] ?? '';
            if (! isset($state[$src]) && ! isset($state[$dst])) {
                continue;
            }
            $n = count($f);
            $name = $f[10] ?? '';
            $t = secs(substr($line, 0, $p));
            $start ??= $t;
            $last = $t;

            if (str_starts_with($ev, 'SPELL_AURA_')) {
                $tail = array_slice($f, -2);
                if ($name === 'Dampening' && isset($state[$dst])) {
                    $state[$dst]['damp'] = str_contains($ev, 'DOSE') ? (int) end($f) : max(1, $state[$dst]['damp']);
                }
                // A debuff's hits were decided when it went on.
                if (isset($state[$src]) && in_array('DEBUFF', $tail, true) && ($ev === 'SPELL_AURA_APPLIED' || $ev === 'SPELL_AURA_REFRESH')) {
                    $state[$src]['snap'][$name.'|'.$dst] = $state[$src]['set'];
                }
                if (isset($state[$dst]) && in_array('BUFF', $tail, true)) {
                    $st = &$state[$dst];
                    $sp = &$meta[$st['spec']];
                    $changed = false;
                    if ($ev === 'SPELL_AURA_APPLIED' || $ev === 'SPELL_AURA_APPLIED_DOSE') {
                        if (! isset($st['up'][$name])) {
                            $st['up'][$name] = $t;
                            $changed = true;
                        }
                        $sp['buffIds'][$name] = (int) $f[9];
                        if ($src === $dst) {
                            $sp['applied'][$name] = ($sp['applied'][$name] ?? 0) + 1;
                            $st['made'][$name] = $t;
                            $st['open'][$name] = true;
                            foreach ($st['press'] as $press => $pt) {
                                if ($t - $pt <= 0.5) {
                                    $sp['links'][$name][$press] = ($sp['links'][$name][$press] ?? 0) + 1;
                                    unset($st['open'][$name]);
                                }
                            }
                        }
                    } elseif ($ev === 'SPELL_AURA_REMOVED' && isset($st['up'][$name])) {
                        $sp['lengths'][$name][] = round($t - $st['up'][$name], 1);
                        $sp['seconds'][$name] = ($sp['seconds'][$name] ?? 0) + ($t - $st['up'][$name]);
                        unset($st['up'][$name]);
                        $changed = true;
                    }
                    if ($changed) {
                        $names = array_keys($st['up']);
                        sort($names);
                        $key = implode('|', $names);
                        if (! isset($sp['setIds'][$key])) {
                            $sp['setIds'][$key] = count($sp['sets']);
                            $sp['sets'][] = $names;
                        }
                        $st['set'] = $sp['setIds'][$key];
                    }
                    unset($st, $sp);
                }

                continue;
            }
            if (! isset($state[$src])) {
                continue;
            }
            $st = &$state[$src];
            if ($ev === 'SPELL_CAST_SUCCESS') {
                $st['press'][$name] = $t;
                foreach ($st['open'] as $buff => $_) {
                    if ($t - ($st['made'][$buff] ?? -99) <= 0.5) {
                        $meta[$st['spec']]['links'][$buff][$name] = ($meta[$st['spec']]['links'][$buff][$name] ?? 0) + 1;
                        unset($st['open'][$buff]);
                    }
                }
                unset($st);

                continue;
            }
            $kind = null;
            if (str_starts_with($dst, 'Player-')) {
                $foe = ($teams[$dst] ?? null) !== ($teams[$src] ?? null);
                if ($foe && ($ev === 'SPELL_DAMAGE' || $ev === 'SPELL_PERIODIC_DAMAGE' || $ev === 'RANGE_DAMAGE')) {
                    $kind = 'd';
                    $pre = (int) $f[$n - 10];
                    $done = (int) $f[$n - 11] + (int) $f[$n - 5];
                    $crit = $f[$n - 4] === '1';
                } elseif ($foe && $ev === 'SPELL_MISSED' && ($at = array_search('ABSORB', $f, true)) !== false) {
                    // Fully absorbed: two fields on is what the shield took, three on is before mitigation.
                    $kind = 'd';
                    $pre = (int) ($f[$at + 3] ?? 0);
                    $done = (int) ($f[$at + 2] ?? 0);
                    $crit = false;
                } elseif (! $foe && ($ev === 'SPELL_HEAL' || $ev === 'SPELL_PERIODIC_HEAL')) {
                    // Gross, with Dampening undone: healing that lands is capped by missing health.
                    $kind = 'h';
                    $pre = (int) ((int) $f[$n - 5] / max(0.05, 1 - $st['damp'] / 100));
                    $done = (int) $f[$n - 5] - (int) $f[$n - 3];
                    $crit = $f[$n - 1] === '1';
                }
            }
            if ($kind === null || $pre <= 0) {
                unset($st);

                continue;
            }
            $sp = &$meta[$st['spec']];
            $periodic = str_contains($ev, 'PERIODIC');
            $set = $periodic ? ($st['snap'][$name.'|'.$dst] ?? $st['set']) : $st['set'];
            $a = $sp['ids'][$name] ??= count($sp['abilities']);
            if ($a === count($sp['abilities'])) {
                $sp['abilities'][] = $name;
            }
            $spec = $st['spec'];
            $handles[$spec] ??= fopen("{$dir}/{$spec}.hits", 'a');
            fwrite($handles[$spec], "{$st['game']}\t{$a}\t{$kind}\t{$pre}\t".($crit ? 1 : 0)."\t{$set}\t{$done}\n");
            unset($st, $sp);
        }
        unset($raw);
        if (++$read % 100 === 0) {
            fwrite(STDERR, "$read games\n");
        }
    }
    foreach ($meta as $spec => $sp) {
        unset($sp['ids'], $sp['setIds']);
        foreach ($sp['lengths'] as $b => $l) {
            $sp['lengths'][$b] = ['n' => count($l), 'median' => median($l)];
        }
        file_put_contents("{$dir}/{$spec}.json", json_encode($sp, JSON_UNESCAPED_UNICODE));
    }
    echo "scanned $read games, ".count($meta)." specs\n";
}

function readHits(string $dir, int $spec): array
{
    $out = [];
    $h = fopen("{$dir}/{$spec}.hits", 'r');
    while (($l = fgets($h)) !== false) {
        $out[] = array_map(fn ($v, $i) => $i === 2 ? $v : (int) $v, $x = explode("\t", rtrim($l)), array_keys($x));
    }
    fclose($h);

    return $out;
}

/** Each buff's multiplier on one ability, the others held apart, within each game. */
function fit(array $rows, array $sets, array $games, ?array $only = null): array
{
    $byGame = [];
    foreach ($rows as $h) {
        $byGame[$h[0]][] = log($h[3]);
    }
    $mean = array_map(fn ($v) => array_sum($v) / count($v), $byGame);
    $seen = [];
    $who = [];
    foreach ($rows as $h) {
        foreach ($sets[$h[5]] as $b) {
            $seen[$b] = ($seen[$b] ?? 0) + 1;
            $who[$b][$games[$h[0]][0]] = true;
        }
    }
    $n = count($rows);
    $feat = array_keys(array_filter($seen, fn ($c, $b) => $c >= MIN_ON && $n - $c >= MIN_ON, ARRAY_FILTER_USE_BOTH));
    if ($only !== null) {
        $feat = array_values(array_intersect($feat, $only));
    }
    $flag = array_flip($feat);
    $y = [];
    $x = [];
    foreach ($rows as $i => $h) {
        $y[$i] = log($h[3]) - $mean[$h[0]];
        $x[$i] = array_values(array_filter($sets[$h[5]], fn ($b) => isset($flag[$b])));
    }
    // Residuals kept up to date, so one pass costs the hits a buff touches, not every hit: a healer
    // has 400,000 of them and the plain version never finished.
    $beta = array_fill_keys($feat, 0.0);
    $idx = array_fill_keys($feat, []);
    foreach ($x as $i => $list) {
        foreach ($list as $q) {
            $idx[$q][] = $i;
        }
    }
    $resid = $y;
    $rsum = array_sum($y);
    for ($iter = 0; $iter < 12; $iter++) {
        foreach ($feat as $b) {
            $n1 = count($idx[$b]);
            $n0 = $n - $n1;
            if (! $n1 || ! $n0) {
                continue;
            }
            $old = $beta[$b];
            $s1 = 0.0;
            foreach ($idx[$b] as $i) {
                $s1 += $resid[$i];
            }
            $s1 += $old * $n1;
            $s0 = $rsum + $old * $n1 - $s1;
            $new = $s1 / $n1 - $s0 / $n0;
            $delta = $new - $old;
            foreach ($idx[$b] as $i) {
                $resid[$i] -= $delta;
            }
            $rsum -= $delta * $n1;
            $beta[$b] = $new;
        }
    }
    $out = [];
    foreach ($beta as $b => $v) {
        $out[$b] = ['x' => round(exp($v), 3), 'n' => $seen[$b], 'players' => count($who[$b] ?? [])];
    }

    return $out;
}

function specRows(string $dir, int $spec): ?array
{
    if (! is_file("{$dir}/{$spec}.json") || ! is_file("{$dir}/{$spec}.hits")) {
        return null;
    }
    $sp = json_decode(file_get_contents("{$dir}/{$spec}.json"), true);
    $hits = readHits($dir, $spec);
    $kind = in_array($spec, HEALERS, true) ? 'h' : 'd';
    $hits = array_values(array_filter($hits, fn ($h) => $h[2] === $kind));
    $total = [];
    foreach ($hits as $h) {
        $total[$h[1]] = ($total[$h[1]] ?? 0) + $h[6];
    }
    arsort($total);

    return [$sp, $hits, $total, $kind];
}

function report(string $dir): void
{
    $kit = kitNames();
    $out = fopen($dir.'/candidates.txt', 'w');
    foreach (glob($dir.'/*.json') as $f) {
        $spec = (int) basename($f, '.json');
        $row = specRows($dir, $spec);
        if (! $row || ! $spec) {
            continue;
        }
        [$sp, $hits, $total, $kind] = $row;
        $label = specLabel($spec);
        $all = array_sum($total) ?: 1;
        $players = count(array_unique(array_column($sp['games'], 0)));
        fwrite($out, "\n=== {$spec} {$label} : ".count($sp['games'])." rounds, {$players} players, ".count($hits)." hits\n");
        $found = [];
        foreach (array_slice(array_keys($total), 0, 6) as $a) {
            $name = $sp['abilities'][$a];
            $rows = array_values(array_filter($hits, fn ($h) => $h[1] === $a && ! $h[4]));
            if (count($rows) < 2 * MIN_ON) {
                continue;
            }
            $fx = fit($rows, $sp['sets'], $sp['games']);
            fwrite($out, sprintf("  %-28s %2d%% of output, %d hits\n", $name, $total[$a] / $all * 100, count($rows)));
            foreach ($fx as $b => $e) {
                if ($e['x'] >= MIN_LIFT && $e['players'] >= MIN_PLAYERS && isset($sp['applied'][$b]) && $b !== $name) {
                    $found[$b][$name] = $e['x'];
                }
            }
        }
        // Each half of the players must show it too.
        $names = array_values(array_unique(array_column($sp['games'], 0)));
        sort($names);
        $half = array_flip(array_filter($names, fn ($k) => $k % 2 === 0, ARRAY_FILTER_USE_KEY));
        uasort($found, fn ($a, $b) => max($b) <=> max($a));
        foreach ($found as $b => $on) {
            arsort($on);
            $ab = array_key_first($on);
            $a = array_search($ab, $sp['abilities'], true);
            $split = [[], []];
            foreach ($hits as $h) {
                if ($h[1] === $a && ! $h[4]) {
                    $split[isset($half[$sp['games'][$h[0]][0]]) ? 0 : 1][] = $h;
                }
            }
            $xs = [];
            foreach ($split as $part) {
                $xs[] = fit($part, $sp['sets'], $sp['games'])[$b]['x'] ?? null;
            }
            $inKit = in_array($b, $kit[$spec] ?? [], true);
            $held = array_filter($xs, fn ($v) => $v !== null && $v >= HALF_LIFT);
            $tag = ($inKit ? 'KIT ' : 'item').' '.(count($held) === 2 ? 'both' : 'ONE ');
            $links = $sp['links'][$b] ?? [];
            arsort($links);
            $press = array_key_first($links);
            $rate = $press ? round($links[$press] / max(1, $sp['applied'][$b]) * 100) : 0;
            fwrite($out, sprintf("  [%s] %-30s %s  <- %s %d%% of %d; held %ss; halves %s\n      %s\n", $tag, $b, json_encode($on, JSON_UNESCAPED_UNICODE), $press ?: '-', $rate, $sp['applied'][$b], $sp['lengths'][$b]['median'] ?? '?', json_encode($xs), tooltip($b)));
        }
    }
    fclose($out);
    echo "wrote {$dir}/candidates.txt\n";
}

function specLabel(int $spec): string
{
    $s = Illuminate\Support\Facades\DB::table('specializations as s')->join('classes as c', 'c.id', '=', 's.class_id')->where('s.external_spec_id', $spec)->select('s.name as spec', 'c.name as class')->first();

    return $s ? "{$s->spec} {$s->class}" : (string) $spec;
}

function tooltip(string $name): string
{
    $db = Illuminate\Support\Facades\DB::class;
    $t = $db::table('spellbook_snapshot_entries')->where('name', $name)->whereNotNull('resolved_description')->where('resolved_description', '!=', '')->value('resolved_description')
        ?: $db::table('spells')->where('name', $name)->where('description', '!=', '')->value('description');

    return mb_substr(preg_replace('/\s+/', ' ', strip_tags((string) $t)), 0, 190);
}

/** Every name a spec's talents and spells carry, and what their tooltips mention. */
function kitNames(): array
{
    $db = Illuminate\Support\Facades\DB::class;
    $out = [];
    $specs = $db::table('specializations')->whereNotNull('external_spec_id')->get();
    foreach ($specs as $s) {
        $names = $db::table('spell_class_availability as a')->join('spells as x', 'x.id', '=', 'a.spell_id')
            ->where(fn ($q) => $q->where('a.spec_id', $s->id)->orWhere(fn ($q2) => $q2->whereNull('a.spec_id')->where('a.class_id', $s->class_id)))
            ->pluck('x.name');
        $talents = $db::table('talent_trees as t')->join('talent_nodes as n', 'n.talent_tree_id', '=', 't.id')->join('talent_node_entries as e', 'e.talent_node_id', '=', 'n.id')->join('spells as x', 'x.id', '=', 'e.spell_id')
            ->where(fn ($q) => $q->where('t.class_id', $s->class_id)->orWhere('t.type', 'hero'))
            ->select('x.name', 'x.description')->get();
        $set = $names->merge($talents->pluck('name'))->unique()->values()->all();
        foreach ($talents as $t) {
            if (preg_match_all('/\$@spellname\d+|[A-Z][a-z\']+(?: of the| of| the)?(?: [A-Z][a-z\']+){0,3}/', (string) $t->description, $m)) {
                foreach ($m[0] as $w) {
                    $set[] = $w;
                }
            }
        }
        $out[(int) $s->external_spec_id] = array_values(array_unique($set));
    }

    return $out;
}

function build(string $dir): void
{
    $db = Illuminate\Support\Facades\DB::class;
    $path = REPO.'/data/empowered-casts';
    $reviewed = json_decode(file_get_contents($path.'/reviewed.json'), true);
    $patch = $db::table('patches')->where('is_current', true)->value('build_version');
    $written = 0;
    foreach ($db::table('specializations as s')->join('classes as c', 'c.id', '=', 's.class_id')->whereNotNull('s.external_spec_id')->select('s.external_spec_id as id', 's.slug as spec', 'c.slug as class')->get() as $s) {
        $spec = (int) $s->id;
        $file = "{$path}/{$s->class}/{$s->spec}.json";
        $rev = $reviewed['specs'][$spec] ?? null;
        if (isset($rev['handMeasured'])) {
            continue;   // written by hand from a closer measure; see the file's own "method"
        }
        $row = specRows($dir, $spec);
        $sp = $row[0] ?? null;
        $players = $sp ? count(array_unique(array_column($sp['games'], 0))) : 0;
        $approved = array_keys($rev['approve'] ?? []);
        if (! $row || ! $approved) {
            @unlink($file);

            continue;
        }
        [$sp, $hits, $total, $kind] = $row;
        $names = array_flip($sp['abilities']);
        $results = [];
        $listed = array_merge(...array_map(fn ($v) => $v['only'] ?? [], array_values($rev['approve'])), ...[[]]);
        $extra = array_values(array_filter(array_map(fn ($n) => $names[$n] ?? null, $listed), fn ($v) => $v !== null));
        foreach (array_unique(array_merge(array_slice(array_keys($total), 0, 6), $extra)) as $a) {
            $rows = array_values(array_filter($hits, fn ($h) => $h[1] === $a && ! $h[4]));
            if (count($rows) < 2 * MIN_ON) {
                continue;
            }
            foreach (fit($rows, $sp['sets'], $sp['games']) as $b => $e) {
                $only = $rev['approve'][$b]['only'] ?? null;
                if (in_array($b, $approved, true) && $e['x'] >= MIN_LIFT && $b !== $sp['abilities'][$a] && ($only === null || in_array($sp['abilities'][$a], $only, true))) {
                    $results[$b][] = ['ability' => $sp['abilities'][$a], 'x' => round($e['x'], 2), 'hits' => $e['n']];
                }
            }
        }
        $empowers = [];
        foreach ($results as $b => $list) {
            usort($list, fn ($p, $q) => $q['x'] <=> $p['x']);
            $links = $sp['links'][$b] ?? [];
            arsort($links);
            $press = array_key_first($links);
            $rate = $press ? $links[$press] / max(1, $sp['applied'][$b]) : 0;
            $from = $rev['approve'][$b]['from'] ?? ($rate >= 0.3 && $press !== $b ? [$press] : []);
            $empowers[] = ['buff' => $b, 'from' => (array) $from, 'link' => round($rate, 2), 'results' => array_slice($list, 0, 4), 'held' => $sp['lengths'][$b]['median'] ?? null, 'tooltip' => $rev['approve'][$b]['tooltip'] ?? null];
        }
        usort($empowers, fn ($p, $q) => $q['results'][0]['x'] <=> $p['results'][0]['x']);
        if (! $empowers) {
            @unlink($file);

            continue;
        }
        // The ability most of them act on, and what stacking does to it.
        $count = [];
        foreach ($empowers as $e) {
            foreach ($e['results'] as $r) {
                $count[$r['ability']] = ($count[$r['ability']] ?? 0) + $r['x'];
            }
        }
        arsort($count);
        $payoff = $rev['payoff'] ?? array_key_first($count);
        $three = array_values(array_slice(array_column(array_filter($empowers, fn ($e) => in_array($payoff, array_column($e['results'], 'ability'), true)), 'buff'), 0, 3));
        $a = $names[$payoff];
        $rows = array_values(array_filter($hits, fn ($h) => $h[1] === $a && ! $h[4]));
        $key = function ($h) use ($sp, $three) {
            $k = '';
            foreach ($three as $b) {
                $k .= in_array($b, $sp['sets'][$h[5]], true) ? '1' : '0';
            }

            return 'k'.$k;
        };
        $plain = [];
        foreach ($rows as $h) {
            if (! str_contains($key($h), '1')) {
                $plain[$h[0]][] = $h[3];
            }
        }
        $cells = [];
        foreach ($rows as $h) {
            if (count($plain[$h[0]] ?? []) >= 3) {
                $cells[$key($h)][] = $h[3] / median($plain[$h[0]]);
            }
        }
        $ladder = [];
        foreach ($cells as $k => $v) {
            if (count($v) >= 15) {
                $ladder[] = ['up' => array_map('intval', str_split(substr($k, 1))), 'x' => round(median($v), 2), 'hits' => count($v)];
            }
        }
        usort($ladder, fn ($p, $q) => $p['x'] <=> $q['x']);
        // One buff against none says less than its own row above, and disagrees with it: these are
        // raw medians, the row above holds the other buffs apart.
        if (count($three) < 2 || count($ladder) < 3) {
            [$three, $ladder] = [[], []];
        }
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode([
            'kind' => $kind === 'h' ? 'healing' : 'damage',
            'build' => $patch,
            'measured' => date('Y-m-d'),
            'rounds' => count($sp['games']),
            'players' => $players,
            'payoff' => $payoff,
            'stacked' => $three,
            'empowers' => $empowers,
            'ladder' => $ladder,
            'note' => $rev['note'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $written++;
    }
    echo "wrote $written spec files\n";
}

/** One game, one ability, cast by cast and hit by hit: step 2 of the process. */
function spike(string $who, string $match, string $ability): void
{
    $meta = json_decode(file_get_contents(ARCHIVE."/metadata/{$match}.json"), true);
    $guid = null;
    foreach ($meta['units'] as $u) {
        if ($u['name'] === $who) {
            $guid = $u['id'];
        }
    }
    $raw = gzdecode(file_get_contents(ARCHIVE."/raw/{$match}.log.gz"));
    $buffs = [];
    $t0 = null;
    foreach (explode("\n", $raw) as $line) {
        $p = strpos($line, '  ');
        if ($p === false || ! str_contains($line, $guid)) {
            continue;
        }
        $f = str_getcsv(rtrim(substr($line, $p + 2)), ',', '"', '');
        if (! str_starts_with($f[0], 'SPELL_')) {
            continue;
        }
        $t = secs(substr($line, 0, $p));
        $t0 ??= $t;
        $name = $f[10] ?? '';
        if (str_starts_with($f[0], 'SPELL_AURA_') && $f[5] === $guid && in_array('BUFF', array_slice($f, -2), true)) {
            if ($f[0] === 'SPELL_AURA_REMOVED') {
                unset($buffs[$name]);
            } else {
                $buffs[$name] = str_contains($f[0], 'DOSE') ? (int) end($f) : 1;
            }
        } elseif ($f[1] === $guid && $name === $ability) {
            $n = count($f);
            if ($f[0] === 'SPELL_CAST_SUCCESS') {
                printf("\n%6.1fs CAST  %s\n", $t - $t0, implode(', ', array_map(fn ($b, $s) => $s > 1 ? "$b x$s" : $b, array_keys($buffs), $buffs)));
            } elseif ($f[0] === 'SPELL_DAMAGE' || $f[0] === 'SPELL_PERIODIC_DAMAGE') {
                printf("  hit %8d before mitigation%s\n", $f[$n - 10], $f[$n - 4] === '1' ? '  crit' : '');
            } elseif ($f[0] === 'SPELL_MISSED' && ($at = array_search('ABSORB', $f, true)) !== false) {
                printf("  hit %8d before mitigation, absorbed\n", $f[$at + 3]);
            } elseif ($f[0] === 'SPELL_HEAL' || $f[0] === 'SPELL_PERIODIC_HEAL') {
                printf("  heal %7d gross, %d overheal%s\n", $f[$n - 5], $f[$n - 3], $f[$n - 1] === '1' ? '  crit' : '');
            }
        }
    }
}
