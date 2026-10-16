<?php

namespace App\Support;

use App\Console\Commands\BuildPopulation;
use App\Models\Specialization;
use Illuminate\Support\Collection;

/**
 * What the public spec guides (/wow/pvp/{class}/{spec}, 2026-10-16) say about each spec, counted from
 * every player of it in every measured game: the PvP talents they take, how often they die and die
 * first, the defensives pressed when a go lands on them, the comps they play in, and the rating the
 * games were played at.
 *
 * Built by wow:population's aggregate from the same compact games as the norms (PopulationGame), so
 * it refreshes wherever the norms do: on the PC into data/population/spec-pages.json (committed),
 * and on the server from the archive bundle plus every uploaded round into the live copy
 * (wow:population-refresh). The page reads whichever was built last (path()). No names and no
 * player ids leave here; counts only.
 *
 * The sample is mostly the games of the people who upload them and the players they met, around
 * their ratings. Every number carries its count, and each spec carries the rating band its games
 * were played at, so a reader can see what it is a sample of.
 */
class SpecPages
{
    public const FILE = 'data/population/spec-pages.json';

    /** A spec needs this many rounds before it gets numbers on its page. */
    public const MIN_ROUNDS = 8;

    /** A PvP talent, defensive or comp shown needs at least this share or count, or the list fills with one-offs. */
    private const MIN_SHARE = 0.05;

    public static function livePath(): string
    {
        return dirname(BuildPopulation::livePath()).'/spec-pages.json';
    }

    /** The newer of the live copy (the server's own builds) and the committed one. */
    public static function path(): string
    {
        $committed = base_path(self::FILE);
        $live = self::livePath();
        if (! is_file($live)) {
            return $committed;
        }
        $builtAt = function (string $path): string {
            $head = (string) @file_get_contents($path, false, null, 0, 300);

            return preg_match('/"builtAt":\s*"([^"]+)"/', $head, $m) ? $m[1] : '';
        };

        return $builtAt($live) > $builtAt($committed) ? $live : $committed;
    }

    /** What load() returns in a test instead of either file (fake()). */
    private static ?array $fake = null;

    /** @var array<string, array> Decoded files by path, once per process. */
    private static array $cache = [];

    /** The whole file, decoded once per request. */
    public static function load(): array
    {
        if (self::$fake !== null) {
            return self::$fake;
        }
        $path = self::path();

        return self::$cache[$path] ??= (is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : []);
    }

    /** Tests: serve this instead of the files; null goes back to the files. */
    public static function fake(?array $data): void
    {
        self::$fake = $data;
        self::$cache = [];
    }

    /** One spec's numbers, by Blizzard's spec id, or null when it has too few games. */
    public static function forSpec(int $externalSpecId): ?array
    {
        return self::load()['specs'][(string) $externalSpecId] ?? null;
    }

    /**
     * Every spec's numbers from the compact games. `$seasonSince`: like the norms, a spec with enough
     * rounds this season is counted from this season only (talents and kits change between seasons).
     */
    public static function build(Collection $games, string $seasonSince): array
    {
        $labels = Specialization::with('gameClass')->whereNotNull('external_spec_id')->get()
            ->mapWithKeys(fn ($s) => [$s->external_spec_id => trim("{$s->name} {$s->gameClass?->name}")]);

        // Every player-round, with what the spec pages need from its game.
        $rows = $games->flatMap(fn ($g) => collect($g['players'])->map(fn ($p, $h) => $p + [
            'h' => $h,
            'game' => $g['id'],
            'bracket' => $g['bracket'],
            'playedAt' => $g['playedAt'],
            'teamMmr' => $g['mmr'][$p['side']] ?? null,
        ])->values())->filter(fn ($p) => ($p['spec'] ?? 0) > 0)->values();

        $goesOn = self::goesOnEachPlayer($games);
        $comps = self::comps($games);

        $specs = [];
        foreach ($rows->groupBy('spec') as $spec => $all) {
            $season = $all->filter(fn ($p) => ($p['playedAt'] ?? '') >= $seasonSince)->values();
            $list = $season->count() >= self::MIN_ROUNDS ? $season : $all;
            if ($list->count() < self::MIN_ROUNDS) {
                continue;
            }
            $n = $list->count();
            $keys = $list->map(fn ($p) => $p['game'].'|'.$p['h'])->flip();

            $mmr = $list->pluck('teamMmr')->filter()->sort()->values();
            $specs[(string) $spec] = [
                'label' => $labels[$spec] ?? (string) $spec,
                'window' => $list === $season ? 'season' : 'all',
                'healer' => (bool) $list->first()['healer'],
                'rounds' => $n,
                'players' => $list->pluck('h')->unique()->count(),
                'from' => $list->pluck('playedAt')->filter()->min(),
                'to' => $list->pluck('playedAt')->filter()->max(),
                'mmr' => $mmr->isEmpty() ? null : [
                    'p25' => (int) $mmr[(int) floor(0.25 * ($mmr->count() - 1))],
                    'p50' => (int) $mmr[(int) floor(0.5 * ($mmr->count() - 1))],
                    'p75' => (int) $mmr[(int) floor(0.75 * ($mmr->count() - 1))],
                ],
                'brackets' => $list->countBy('bracket')->sortDesc()->map(fn ($c) => round($c / $n, 2))->all(),
                // Rounds recorded by a player of this spec themselves: high means the page leans on a
                // few uploaders' own games rather than on everyone they met, and the page says so.
                'recorderShare' => round($list->where('logger', true)->count() / $n, 2),
                'pvpTalents' => self::pvpTalents($list),
                // A successful interrupt is rare enough that the middle player of most specs lands
                // none in a round, so the page says how often a round has one rather than a median of 0.
                'interruptRounds' => round($list->filter(fn ($p) => ($p['kicks'] ?? 0) > 0)->count() / $n, 2),
                'priorityKicks' => self::priorityKicks($list),
                'died' => round($list->where('died', true)->count() / $n, 2),
                'diedFirst' => round($list->where('diedFirst', true)->count() / $n, 2),
                'defensives' => self::defensives($goesOn->only($keys->keys()->all())),
                'comps' => collect($comps[(string) $spec] ?? [])->sortByDesc('games')->take(6)->values()->all(),
            ];
        }

        return [
            'builtAt' => now()->toIso8601String(),
            'generatedAt' => now()->toDateString(),
            'seasonSince' => $seasonSince,
            'games' => $games->count(),
            'specs' => $specs,
        ];
    }

    /**
     * The spec as a kicker (measure 15): every high-priority cast its players met with their kick
     * ready, and how many they stopped, a teammate stopped, or let through. Null when no round of it
     * carries the measure (a spec with no interrupt, or rounds measured before version 15).
     */
    private static function priorityKicks(Collection $list): ?array
    {
        $with = $list->filter(fn ($p) => isset($p['priority']['high']));
        $chances = $with->sum(fn ($p) => $p['priority']['high']['chances']);
        if ($chances === 0) {
            return null;
        }

        return [
            'rounds' => $with->count(),
            'chances' => $chances,
            'mine' => $with->sum(fn ($p) => $p['priority']['high']['mine']),
            'landed' => $with->sum(fn ($p) => $p['priority']['high']['landed']),
        ];
    }

    /** Each PvP talent's share of the spec's rounds, most taken first. */
    private static function pvpTalents(Collection $list): array
    {
        $withTalents = $list->filter(fn ($p) => ! empty($p['pvpTalents']));
        $n = max(1, $withTalents->count());

        return $withTalents->flatMap(fn ($p) => array_unique($p['pvpTalents']))->filter(fn ($name) => self::readable($name))->countBy()
            ->map(fn ($c) => round($c / $n, 2))
            ->filter(fn ($share) => $share >= self::MIN_SHARE)
            ->sortDesc()->take(10)->all();
    }

    /**
     * Every go that landed on a player, keyed "game|player": what that player pressed for themselves
     * in it, and what their team pressed on them.
     */
    private static function goesOnEachPlayer(Collection $games): Collection
    {
        $out = [];
        foreach ($games as $g) {
            foreach ($g['goes'] ?? [] as $go) {
                $target = $go['target'] ?? null;
                if ($target === null || ! isset($g['players'][$target])) {
                    continue;
                }
                $self = [];
                $team = [];
                foreach ($go['answers'] ?? [] as $a) {
                    if (($a['who'] ?? null) === $target) {
                        $self[$a['spell']] = true;
                    } elseif ($a['onTarget'] ?? false) {
                        $team[$a['spell']] = true;
                    }
                }
                $out[$g['id'].'|'.$target][] = ['self' => array_keys($self), 'team' => array_keys($team)];
            }
        }

        return collect($out);
    }

    /** The share of goes on the spec in which each defensive was pressed, by them and by their team. */
    private static function defensives(Collection $goes): array
    {
        $all = $goes->flatten(1);
        $n = $all->count();
        if ($n === 0) {
            return ['goes' => 0, 'self' => [], 'team' => []];
        }
        $share = fn (string $who) => $all->flatMap(fn ($go) => $go[$who])->filter(fn ($name) => self::readable($name))->countBy()
            ->map(fn ($c) => round($c / $n, 2))
            ->filter(fn ($s) => $s >= self::MIN_SHARE)
            ->sortDesc()->take(8)->all();

        return ['goes' => $n, 'self' => $share('self'), 'team' => $share('team')];
    }

    /**
     * A name an English page can show. A game from a Chinese, Korean or Russian client logs its
     * spells in that language (角斗士的勋章 is Gladiator's Medallion): counted, never printed.
     */
    public static function readable(string $name): bool
    {
        return ! preg_match('/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7AF}\x{0400}-\x{04FF}]/u', $name);
    }

    /** For each spec, the 3v3 teams it played in: games, and games won. */
    private static function comps(Collection $games): array
    {
        $out = [];
        foreach ($games->where('bracket', '3v3') as $g) {
            foreach (['us', 'them'] as $side) {
                $team = collect($g['players'])->where('side', $side);
                $ids = $team->pluck('spec')->filter()->sort()->values()->all();
                if (count($ids) !== 3) {
                    continue;
                }
                $won = (bool) $team->first()['won'];
                $key = implode('-', $ids);
                foreach (array_unique($ids) as $spec) {
                    $out[(string) $spec][$key] ??= ['specs' => $ids, 'games' => 0, 'won' => 0];
                    $out[(string) $spec][$key]['games']++;
                    $out[(string) $spec][$key]['won'] += $won ? 1 : 0;
                }
            }
        }

        return array_map(fn ($c) => array_values(array_filter($c, fn ($x) => $x['games'] >= 2)), $out);
    }
}
