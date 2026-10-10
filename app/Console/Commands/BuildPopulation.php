<?php

namespace App\Console\Commands;

use App\Http\Services\ArenaLogService;
use App\Http\Services\RoundAnalysisService;
use App\Models\Specialization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Learn from every player in every archived game, not only the player who logged it (2026-10-06).
 *
 *   php -d memory_limit=2G artisan wow:population              # measure what is new, then aggregate
 *   php -d memory_limit=2G artisan wow:population --fresh      # measure everything again (after VERSION changes)
 *   php artisan wow:population --aggregate                      # rebuild the norms from what is measured
 *
 * WHY. A game has six players and the coach used to learn from one. The archive holds 795 games and
 * 1,276 different players across 38 specs; measured with the same code as a player's own games
 * (RoundAnalysisService), they say what normal looks like for each spec (presses per ability, output
 * per free minute, kicks, control, how often the control goes off target) and which habits go with
 * winning rounds and killing goes across everyone, which is the evidence a tip needs before it is
 * advice. A player's own page compares them against these norms.
 *
 * TWO OUTPUTS, kept apart on purpose:
 *  - storage/app/population/{match}.json: one measured game, players under a hashed id (no names).
 *    Gitignored: it is derived from other people's games and only the aggregate leaves the machine.
 *  - data/population/norms.json: per-spec medians and quartiles and the outcome tables, no ids, no
 *    names. Committed; the comp page, the game cards and Improve read it.
 *
 * Each game is measured from the archive's raw slice and metadata, so it works for the games
 * `wow:forget-games` hid from the player's own analysis: forgetting changes what is "yours", not
 * what the game teaches.
 */
class BuildPopulation extends Command
{
    protected $signature = 'wow:population
        {--fresh : Measure every game again}
        {--aggregate : Only rebuild the norms from the games already measured}
        {--limit=0 : Measure at most this many new games (0: all)}';

    protected $description = 'Measure every archived game for every player, and build per-spec norms and outcome tables';

    public const NORMS = 'data/population/norms.json';

    /** A spec's norms need this many measured player-rounds before they are written. */
    private const MIN_ROUNDS = 8;

    /**
     * Output norms (damage, healing, presses) come from this season only: item level rises through an
     * expansion, so the April and May games of season 1 put a lower median on every spec's damage than
     * the August games of season 2 (patch 12.1). Habit-to-winning relationships use every game; a
     * spec with too few rounds this season falls back to all of them, and says so (`window`).
     */
    public const SEASON_SINCE = '2026-08-01';

    /** A round is one this short or shorter only if someone died almost at once; it says little about habits. */
    private const MIN_ALIVE = 45.0;

    public static function dir(): string
    {
        return storage_path('app/population');
    }

    public function handle(ArenaLogService $arena, RoundAnalysisService $analysis): int
    {
        File::ensureDirectoryExists(self::dir());

        if (! $this->option('aggregate')) {
            $this->measure($arena, $analysis);
        }

        $this->aggregate();

        return self::SUCCESS;
    }

    private function measure(ArenaLogService $arena, RoundAnalysisService $analysis): void
    {
        $metaDir = dirname($arena->metadataPath('x'));
        $ids = collect(File::files($metaDir))->map(fn ($f) => $f->getFilenameWithoutExtension())->values();
        $limit = (int) $this->option('limit');
        $done = 0;
        $failed = 0;
        $started = microtime(true);

        foreach ($ids as $i => $id) {
            $out = self::dir()."/{$id}.json";

            if (! $this->option('fresh') && File::exists($out)
                && (json_decode(File::get($out), true)['version'] ?? 0) === RoundAnalysisService::VERSION) {
                continue;
            }
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            $raw = @gzdecode((string) @file_get_contents($arena->rawLogPath($id)));
            $meta = json_decode((string) @file_get_contents($arena->metadataPath($id)), true);

            if (! $raw || ! is_array($meta)) {
                $failed++;

                continue;
            }

            try {
                $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($raw)) ?: [], fn ($l) => $l !== ''));
                $a = $analysis->analyse($lines, $meta);
            } catch (\Throwable $e) {
                $this->warn("  {$id}: ".$e->getMessage());
                $failed++;

                continue;
            }

            if (! $a) {
                $failed++;

                continue;
            }

            File::put($out, json_encode($this->compact($id, $a, $meta)));
            $done++;

            if ($done % 25 === 0) {
                $this->line(sprintf('  %d measured (%d of %d checked), %.0fs', $done, $i + 1, $ids->count(), microtime(true) - $started));
            }
        }

        $this->info("{$done} game(s) measured, {$failed} could not be read.");
    }

    /** One game as the aggregate needs it: no names, players under a stable hash. */
    private function compact(string $id, array $a, array $meta): array
    {
        $hash = fn (?string $guid) => $guid ? substr(sha1('mc-pop:'.$guid), 0, 12) : null;
        $winner = $a['won'] ? 'us' : 'them';

        $players = [];
        foreach ($a['players'] as $p) {
            $g = $p['guid'];
            $b = $a['breakdown'][$g] ?? [];
            $h = $a['habits'][$g] ?? [];
            $sum = fn (string $kind) => array_sum(array_column($b[$kind] ?? [], 'amount'));
            $players[$hash($g)] = [
                'spec' => (int) ($p['specExternalId'] ?? 0),
                'side' => $p['side'],
                'healer' => (bool) $p['healer'],
                'logger' => (bool) $p['logger'],
                'won' => $p['side'] === $winner,
                'alive' => $b['alive'] ?? 0,
                'idle' => $b['idle'] ?? 0,
                'free' => $h['free'] ?? 0,
                'locked' => $a['lockout'][$g] ?? 0,
                'damage' => $sum('damage'),
                'healing' => $sum('healing') + $sum('absorbs'),
                'casts' => $h['casts'] ?? [],
                'control' => $h['control'] ?? 0,
                'offTarget' => $h['offTarget'] ?? 0,
                'kicks' => $h['kicks'] ?? 0,
                'kicked' => array_sum($h['kicked'] ?? []),
                'petHits' => $h['petHits'] ?? 0,
                'petOnTarget' => $h['petOnTarget'] ?? 0,
                'ilvl' => $p['ilvl'] ?? null,
                'pvpTalents' => $p['pvpTalents'] ?? [],
                'failed' => $p['logger'] ? array_map(fn ($r) => $r['n'], $h['failed'] ?? []) : null,
                'died' => collect($a['deaths'])->contains('who', $g),
                'diedFirst' => ($a['deaths'][0]['who'] ?? null) === $g,
            ];
        }

        $goes = array_map(function ($go) use ($a, $hash) {
            // The defending side's answers pressed inside this go, for tools/match-review/decisions.php
            // (situation, choice, outcome; 2026-10-10). Rows end at the first death, as stored.
            $defending = $go['side'] === 'us' ? 'them' : 'us';
            $answers = [];
            foreach ($a['defensives'][$defending]['rows'] ?? [] as $r) {
                if ($r['t'] >= $go['from'] && $r['t'] <= $go['to']) {
                    $answers[] = [
                        't' => round((float) $r['t'] - (float) $go['from'], 1),
                        'spell' => $r['spell'],
                        'who' => $hash($r['who']),
                        'onTarget' => ($r['on'] ?? $r['who']) === $go['target'],
                        'hp' => $r['hp'] ?? null,
                        'ttl' => $r['ttl'] ?? null,
                        'needed' => (bool) ($r['needed'] ?? false),
                        'danger' => in_array('danger', $r['reasons'] ?? [], true),
                    ];
                }
            }

            // When each player's first offensive cooldown of the go went out: their spread is how
            // far apart "together" was.
            $firsts = [];
            foreach ($go['links'] ?? [] as $l) {
                if (in_array($l['cat'], ['offensive', 'mixed'], true) && $l['by'] && ! isset($firsts[$l['by']])) {
                    $firsts[$l['by']] = (float) $l['t'];
                }
            }

            return [
                'side' => $go['side'],
                // When it started and how long it ran: later goes meet more drained defensives and
                // dampening, and longer goes have more time to kill, so both are confounds to hold.
                'from' => round((float) $go['from'], 1),
                'len' => round((float) $go['to'] - (float) $go['from'], 1),
                'peakDamage' => (int) ($go['peak']['damage'] ?? 0),
                'good' => (bool) $go['good'],
                'healerCc' => (int) $go['healerCc'],
                'pressers' => count($firsts),
                'spread' => count($firsts) >= 2 ? round(max($firsts) - min($firsts), 1) : null,
                'joint' => (bool) ($go['peak']['joint'] ?? false),
                'healerLocked' => (float) ($go['peak']['healerLocked'] ?? 0),
                'healerKicked' => (bool) ($go['peak']['healerKicked'] ?? false),
                'drained' => (int) ($go['drained'] ?? 0),
                'defs' => (int) ($go['defs'] ?? 0),
                'kill' => $go['kill'] !== null,
                'killLater' => (bool) $go['killLater'],
                'target' => $hash($go['target'] ?? null),
                // The defending side's answers back as the go started (the cooldown ledger), and
                // what they pressed inside it.
                'cover' => isset($go['cover']) ? ['answers' => array_map(fn ($x) => ['who' => $hash($x['who'])] + $x, $go['cover']['answers'] ?? [])] + $go['cover'] : null,
                'targetHp' => $go['targetHp'] ?? null,
                'answers' => $answers,
            ];
        }, $a['goes']);

        return [
            'version' => $a['version'],
            'id' => $id,
            'bracket' => $meta['startInfo']['bracket'] ?? null,
            'playedAt' => isset($meta['startTime']) ? date('Y-m-d', (int) ($meta['startTime'] / 1000)) : null,
            'duration' => (float) ($meta['durationInSeconds'] ?? 0),
            'mmr' => $a['mmr'],
            // Defensive rows stop here, so a go starting later says nothing about what was pressed.
            'firstDeath' => isset($a['deaths'][0]['t']) ? round((float) $a['deaths'][0]['t'], 1) : null,
            'players' => $players,
            'goes' => $goes,
        ];
    }

    // ------------------------------------------------------------------ aggregate

    private function aggregate(): void
    {
        $games = collect(File::files(self::dir()))
            ->map(fn ($f) => json_decode(File::get($f->getPathname()), true))
            ->filter(fn ($g) => is_array($g) && ($g['version'] ?? 0) === RoundAnalysisService::VERSION)
            ->values();

        if ($games->isEmpty()) {
            $this->warn('Nothing measured at this version yet.');

            return;
        }

        $labels = Specialization::with('gameClass')->whereNotNull('external_spec_id')->get()
            ->mapWithKeys(fn ($s) => [$s->external_spec_id => trim("{$s->name} {$s->gameClass?->name}")]);

        $rows = $games->flatMap(fn ($g) => collect($g['players'])->map(fn ($p, $h) => $p + ['h' => $h, 'game' => $g['id'], 'bracket' => $g['bracket'], 'playedAt' => $g['playedAt']])->values())
            ->filter(fn ($p) => $p['spec'] > 0 && $p['alive'] >= self::MIN_ALIVE && $p['free'] > 0)
            ->values();

        $specs = [];
        foreach ($rows->groupBy('spec') as $spec => $all) {
            $season = $all->filter(fn ($p) => ($p['playedAt'] ?? '') >= self::SEASON_SINCE)->values();
            $list = $season->count() >= self::MIN_ROUNDS ? $season : $all;
            if ($list->count() < self::MIN_ROUNDS) {
                continue;
            }
            $perFree = fn (string $k) => $list->map(fn ($p) => $p[$k] / ($p['free'] / 60));
            $specs[(string) $spec] = [
                'label' => $labels[$spec] ?? (string) $spec,
                'window' => $list === $season ? 'season' : 'all',
                'healer' => (bool) $list->first()['healer'],
                'rounds' => $list->count(),
                'players' => $list->pluck('h')->unique()->count(),
                'winRate' => round($list->where('won', true)->count() / $list->count(), 3),
                'damagePerFreeMinute' => $this->quartiles($perFree('damage')),
                'healingPerFreeMinute' => $this->quartiles($perFree('healing')),
                'idleShare' => $this->quartiles($list->map(fn ($p) => $p['idle'] / max(1, $p['alive']))),
                'lockedShare' => $this->quartiles($list->map(fn ($p) => $p['locked'] / max(1, $p['alive']))),
                'kicksPerMinute' => $this->quartiles($perFree('kicks')),
                'controlPerMinute' => $this->quartiles($perFree('control')),
                'offTargetShare' => $this->quartiles($list->filter(fn ($p) => $p['control'] >= 3)->map(fn ($p) => $p['offTarget'] / $p['control'])),
                'petOnTargetShare' => $this->quartiles($list->filter(fn ($p) => $p['petHits'] >= 30)->map(fn ($p) => $p['petOnTarget'] / $p['petHits'])),
                'abilities' => $this->abilities($list),
            ];
        }

        $norms = [
            'generatedAt' => now()->toDateString(),
            'version' => RoundAnalysisService::VERSION,
            'seasonSince' => self::SEASON_SINCE,
            'games' => $games->count(),
            'playerRounds' => $rows->count(),
            'players' => $rows->pluck('h')->unique()->count(),
            'specs' => $specs,
            'outcomes' => $this->outcomes($rows, $specs),
            'goes' => $this->goOutcomes($games),
        ];
        $norms['evidence'] = $this->evidence($norms, $rows, $games);

        File::ensureDirectoryExists(base_path(dirname(self::NORMS)));
        File::put(base_path(self::NORMS), json_encode($norms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info(sprintf('%d games, %d player-rounds, %d players, %d specs -> %s',
            $norms['games'], $norms['playerRounds'], $norms['players'], count($specs), self::NORMS));
    }

    /** Presses a free minute per ability: median and quartiles over the rounds that pressed it, and how many did. */
    private function abilities($list): array
    {
        $n = $list->count();
        $by = [];
        foreach ($list as $p) {
            $min = $p['free'] / 60;
            foreach ($p['casts'] as $name => $count) {
                $by[$name][] = $count / $min;
            }
        }

        return collect($by)
            ->map(fn ($v) => ['used' => round(count($v) / $n, 2)] + $this->quartiles(collect($v)))
            // Something most players of the spec press, or the list fills with procs and one-offs.
            ->filter(fn ($a) => $a['used'] >= 0.25)
            ->sortByDesc(fn ($a) => $a['used'] * $a['p50'])
            ->take(30)
            ->all();
    }

    /**
     * Which habits go with winning the round, across everyone: each player's metric set against the
     * median of their own spec in their own season, then the win rate of the rounds above that median
     * against those below it. A correlation, not a cause (a player winning a round is also why their kicks found
     * casts to stop), and it says so on every row with its count.
     */
    private function outcomes($rows, array $specs): array
    {
        $metrics = [
            'damagePerFreeMinute' => [fn ($p) => $p['damage'] / ($p['free'] / 60), 'dps'],
            'healingPerFreeMinute' => [fn ($p) => $p['healing'] / ($p['free'] / 60), 'healer'],
            'idleShare' => [fn ($p) => $p['idle'] / max(1, $p['alive']), 'all'],
            'lockedShare' => [fn ($p) => $p['locked'] / max(1, $p['alive']), 'all'],
            'kicksPerMinute' => [fn ($p) => $p['kicks'] / ($p['free'] / 60), 'all'],
            'controlPerMinute' => [fn ($p) => $p['control'] / ($p['free'] / 60), 'all'],
        ];

        // Each round is set against the median of its own spec IN ITS OWN SEASON, taken from the same
        // rounds being split: a season-2 player is not "above" a median that season 1's lower item
        // level pulled down, and both halves of a split come from one set of rounds.
        $group = fn ($p) => $p['spec'].'|'.(($p['playedAt'] ?? '') >= self::SEASON_SINCE ? 'season' : 'before');
        $healerOf = fn ($p) => (bool) ($specs[(string) $p['spec']]['healer'] ?? $p['healer']);

        $out = [];
        foreach ($metrics as $name => [$fn, $role]) {
            $medians = $rows->groupBy($group)->map(function ($list) use ($fn) {
                $v = $list->map($fn)->sort()->values();

                return $v->count() >= self::MIN_ROUNDS ? $v[(int) floor(($v->count() - 1) / 2)] : null;
            });
            foreach (['dps', 'healer'] as $who) {
                if ($role !== 'all' && $role !== $who) {
                    continue;
                }
                $b = ['above' => ['n' => 0, 'won' => 0], 'below' => ['n' => 0, 'won' => 0]];
                foreach ($rows as $p) {
                    if ($healerOf($p) !== ($who === 'healer')) {
                        continue;
                    }
                    $median = $medians[$group($p)] ?? null;
                    if ($median === null) {
                        continue;
                    }
                    $value = $fn($p);
                    if ($value == $median) {
                        continue;
                    }
                    $bucket = $value > $median ? 'above' : 'below';
                    $b[$bucket]['n']++;
                    $b[$bucket]['won'] += $p['won'] ? 1 : 0;
                }
                $out[$name][$who] = array_map(fn ($x) => ['n' => $x['n'], 'winRate' => $x['n'] ? round($x['won'] / $x['n'], 3) : null], $b);
            }
        }

        return $out;
    }

    /**
     * Do the basics hold across everyone? Every go in the archive, both sides, by the condition it
     * was played under, and how often it killed (in the go or within 30s). This is the test of the
     * comp page's "press together while their healer is locked" against games nobody here played.
     */
    private function goOutcomes($games): array
    {
        $goes = $games->flatMap(fn ($g) => $g['goes'])->values();
        $rate = function ($set) {
            $n = $set->count();

            return ['n' => $n, 'killRate' => $n ? round($set->filter(fn ($g) => $g['kill'] || $g['killLater'])->count() / $n, 3) : null];
        };
        $locked = fn ($g) => $g['healerLocked'] >= 2 || $g['healerKicked'];
        $together = fn ($g) => $g['spread'] !== null && $g['spread'] <= 3;
        $apart = fn ($g) => $g['spread'] !== null && $g['spread'] > 3;

        return [
            'all' => $rate($goes),
            'healerLocked' => $rate($goes->filter($locked)),
            'healerFree' => $rate($goes->reject($locked)),
            'cooldownsTogether' => $rate($goes->filter($together)),
            'cooldownsApart' => $rate($goes->filter($apart)),
            'together+locked' => $rate($goes->filter(fn ($g) => $together($g) && $locked($g))),
            'together+free' => $rate($goes->filter(fn ($g) => $together($g) && ! $locked($g))),
            'apart+locked' => $rate($goes->filter(fn ($g) => $apart($g) && $locked($g))),
            'apart+free' => $rate($goes->filter(fn ($g) => $apart($g) && ! $locked($g))),
            'oneDefensiveDrainedOrMore' => $rate($goes->filter(fn ($g) => $g['drained'] >= 1)),
            'noneDrained' => $rate($goes->filter(fn ($g) => $g['drained'] === 0)),
        ];
    }

    /**
     * The evidence behind each line of a game's Basics tab (GameBasicsService), recomputed with every
     * run so the advice is re-tested by every new game, and every player in it. Each entry is a pair
     * of rates ("with" the habit, "without") and their counts; the page words a line from these
     * numbers, so when a relationship weakens as the archive grows, the wording follows.
     *
     *  - go entries: kill rate (in the go or within 30s) of goes with and without the condition;
     *  - player entries: win rate of rounds with and without the habit.
     */
    private function evidence(array $norms, $rows, $games): array
    {
        $goes = $games->flatMap(fn ($g) => $g['goes'])->values();
        $kill = function ($set) {
            $n = $set->count();

            return ['n' => $n, 'rate' => $n ? round($set->filter(fn ($g) => $g['kill'] || $g['killLater'])->count() / $n, 3) : null];
        };
        $win = fn ($set) => ['n' => $set->count(), 'rate' => $set->count() ? round($set->where('won', true)->count() / $set->count(), 3) : null];
        $locked = fn ($g) => $g['healerLocked'] >= 2 || $g['healerKicked'];

        // Logger-only failures: a round "with" the habit is at or above the median rate of the rounds
        // that had any, so "with" means "often", not "once".
        $loggers = $rows->filter(fn ($p) => $p['logger'] && is_array($p['failed']))->values();
        $failSplit = function (string $why) use ($loggers, $win) {
            $rate = fn ($p) => ($p['failed'][$why] ?? 0) / ($p['free'] / 60);
            $any = $loggers->map($rate)->filter(fn ($r) => $r > 0)->sort()->values();
            if ($any->isEmpty()) {
                return null;
            }
            $cut = $any[(int) floor(($any->count() - 1) / 2)];

            return ['with' => $win($loggers->filter(fn ($p) => $rate($p) >= $cut)), 'without' => $win($loggers->filter(fn ($p) => $rate($p) < $cut)), 'cut' => round($cut, 2)];
        };
        $outcome = fn (string $metric, string $who, bool $higherIsWith) => isset($norms['outcomes'][$metric][$who]) ? [
            'with' => ['n' => $norms['outcomes'][$metric][$who][$higherIsWith ? 'above' : 'below']['n'], 'rate' => $norms['outcomes'][$metric][$who][$higherIsWith ? 'above' : 'below']['winRate']],
            'without' => ['n' => $norms['outcomes'][$metric][$who][$higherIsWith ? 'below' : 'above']['n'], 'rate' => $norms['outcomes'][$metric][$who][$higherIsWith ? 'below' : 'above']['winRate']],
        ] : null;
        $controllers = $rows->filter(fn ($p) => $p['control'] >= 3);

        return [
            'healerLockedInPeak' => ['with' => $kill($goes->filter($locked)), 'without' => $kill($goes->reject($locked))],
            'healerControlDose' => ['with' => $kill($goes->filter(fn ($g) => $g['healerCc'] >= 4)), 'without' => $kill($goes->filter(fn ($g) => $g['healerCc'] === 0))],
            'jointWithHealerLocked' => ['with' => $kill($goes->filter(fn ($g) => $g['joint'] && $locked($g))), 'without' => $kill($goes->filter(fn ($g) => ! $g['joint'] && ! $locked($g)))],
            'theirDefensivesDown' => ['with' => $kill($goes->filter(fn ($g) => $g['drained'] >= 1)), 'without' => $kill($goes->filter(fn ($g) => $g['drained'] === 0))],
            'healerControlledLess' => $outcome('lockedShare', 'healer', false),
            'dpsIdleLess' => $outcome('idleShare', 'dps', false),
            'dpsDamageMore' => $outcome('damagePerFreeMinute', 'dps', true),
            'offTargetControl' => ['with' => $win($controllers->filter(fn ($p) => $p['control'] <= $p['offTarget'] * 2)), 'without' => $win($controllers->filter(fn ($p) => $p['control'] > $p['offTarget'] * 2))],
            'lineOfSightFailures' => $failSplit('Target not in line of sight'),
            'castsInterrupted' => $failSplit('Interrupted'),
            'trinketNotReady' => $failSplit('Item is not ready yet'),
        ];
    }

    /** @return array{p25: ?float, p50: ?float, p75: ?float, n: int} */
    private function quartiles($values): array
    {
        $v = $values->filter(fn ($x) => is_numeric($x) && is_finite((float) $x))->sort()->values();
        $n = $v->count();
        $at = fn (float $q) => $n ? round((float) $v[(int) floor(($n - 1) * $q)], 3) : null;

        return ['p25' => $at(0.25), 'p50' => $at(0.5), 'p75' => $at(0.75), 'n' => $n];
    }
}
