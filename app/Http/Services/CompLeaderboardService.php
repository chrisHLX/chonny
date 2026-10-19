<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\ArenaRoundPlayer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The 3v3 comps of this season, from every game uploaded to MindCollector (2026-10-19, Chriso: "a
 * leaderboard of comps similar to how check pvp and arena logs work except this is seasonal and
 * updated from our mindcollector").
 *
 * A COMP IS ITS THREE CLASSES: the two DPS classes and the healer's, so RMP and RMD are two rows,
 * the way players name them. Both teams of every 3v3 game count, each from its own side; a game
 * two teammates both uploaded counts once (by match id). Read from arena_round_players, never a
 * round's payload, except for one comp's card.
 *
 * THE CARD is the desktop app's comp page (CompLibraryService, desktop.comp) built from every
 * uploader's games against that comp, with each game read from the side that faced it and every
 * name replaced by its spec. Who may open it is the page's business (Players see the leaderboard;
 * the card needs an account with the app connected: CompsPlayed).
 */
class CompLeaderboardService
{
    /** Cached this long; the key also changes with every new upload. */
    private const TTL_MINUTES = 30;

    public function __construct(private BattlenetClient $client, private CompLibraryService $library) {}

    /**
     * The current PvP season's start, from Blizzard (cached a day); 120 days ago if Blizzard
     * cannot be asked, so the board still shows this season's games and nothing very old.
     *
     * @return array{id: ?int, start: Carbon}
     */
    public function season(): array
    {
        $hit = Cache::get('comp-board:season:v1');
        if (is_array($hit)) {
            return ['id' => $hit['id'], 'start' => Carbon::createFromTimestamp($hit['start'])];
        }

        try {
            $id = $this->client->gameData('us', '/data/wow/pvp-season/index', 'dynamic')['current_season']['id'] ?? null;
            $ms = $id ? ($this->client->gameData('us', "/data/wow/pvp-season/{$id}", 'dynamic')['season_start_timestamp'] ?? null) : null;
            if ($id && $ms) {
                Cache::put('comp-board:season:v1', ['id' => (int) $id, 'start' => intdiv((int) $ms, 1000)], now()->addDay());

                return ['id' => (int) $id, 'start' => Carbon::createFromTimestamp(intdiv((int) $ms, 1000))];
            }
        } catch (\Throwable) {
        }

        return ['id' => null, 'start' => now()->subDays(120)->startOfDay()];
    }

    /**
     * Every team of every 3v3 game this season, one row per side of each match.
     *
     * @return Collection<int, array{round: int, match: string, side: string, key: string, specs: list<int>, won: ?bool, mmr: ?int, at: ?Carbon, names: list<string>, vs: string}>
     */
    public function teams(): Collection
    {
        // Kept until a 3v3 round is added or re-measured: one cheap query decides, so the board,
        // a card and the card's page never regroup every player on each request.
        $now = ArenaRoundPlayer::where('bracket', '3v3')->selectRaw('count(*) as n, max(id) as m')->first();
        $key = 'comp-board:teams:v2:'.$now->n.':'.$now->m.':'.$this->season()['start']->timestamp;

        return Cache::remember($key, now()->addHours(6), fn () => $this->groupTeams());
    }

    private function groupTeams(): Collection
    {
        $rows = ArenaRoundPlayer::query()
            ->where('bracket', '3v3')
            ->where('played_at', '>=', $this->season()['start'])
            ->orderBy('arena_round_id')
            ->get(['arena_round_id', 'match_id', 'side', 'healer', 'spec_external_id', 'class_slug', 'won', 'mmr', 'played_at', 'name', 'realm', 'region']);

        $teams = collect();
        foreach ($rows->groupBy('match_id') as $match => $players) {
            // One upload of the match: a game two teammates both uploaded is one game.
            $round = $players->min('arena_round_id');
            $sides = $players->where('arena_round_id', $round)->groupBy('side');
            if ($sides->count() !== 2) {
                continue;
            }
            $keys = $sides->map(fn ($team) => self::keyOf($team));
            foreach ($sides as $side => $team) {
                $teams->push([
                    'round' => $round,
                    'match' => (string) $match,
                    'side' => (string) $side,
                    'key' => $keys[$side],
                    'vs' => $keys->except($side)->first(),
                    'specs' => $team->pluck('spec_external_id')->filter()->map(fn ($s) => (int) $s)->sort()->values()->all(),
                    'won' => $team->first()->won,
                    'mmr' => $team->first()->mmr,
                    'at' => $team->first()->played_at,
                    'names' => $team->map(fn ($p) => $p->name.'-'.$p->realm.'-'.strtoupper($p->region))->values()->all(),
                ]);
            }
        }

        return $teams;
    }

    /** "mage-rogue-priest": the two DPS classes sorted, then the healer's ("none" without one). Class slugs hold no hyphen. */
    public static function keyOf(Collection $team): string
    {
        $healers = $team->where('healer', true);
        $dps = $team->where('healer', false)->pluck('class_slug')->map(fn ($c) => $c ?: 'unknown')->sort()->values();
        if ($healers->count() !== 1) {
            return $team->pluck('class_slug')->map(fn ($c) => $c ?: 'unknown')->sort()->implode('-').'-none';
        }

        return $dps->implode('-').'-'.($healers->first()->class_slug ?: 'unknown');
    }

    /**
     * The board: every comp with its record, the average MMR and experience of the teams that
     * played it, its usual specs, and its record against each comp it met.
     *
     * @return array{season: array, games: int, comps: list<array>}
     */
    public function board(): array
    {
        $teams = $this->teams();
        $signature = $teams->count().':'.$teams->max('round');

        return Cache::remember('comp-board:v2:'.$signature, now()->addMinutes(self::TTL_MINUTES), function () use ($teams) {
            $xp = app(PlayerExperienceService::class)->cachedMany($teams->pluck('names')->flatten()->unique()->values()->all());
            $specs = app(PlayerGamesService::class);
            $labels = $teams->pluck('key')->unique()->mapWithKeys(fn ($k) => [$k => self::label($k)]);

            $comps = $teams->groupBy('key')->map(function (Collection $games, string $key) use ($xp, $specs, $labels) {
                $won = $games->where('won', true)->count();
                $lost = $games->where('won', false)->count();
                // A team's experience: its best 3v3 rating ever, averaged over the players Blizzard has.
                $exp = $games->map(fn ($g) => collect($g['names'])->map(fn ($n) => $xp[$n]['exp3v3'] ?? null)->filter()->avg())->filter();

                return [
                    'key' => $key,
                    'nick' => $labels[$key]['nick'],
                    'classes' => $labels[$key]['classes'],
                    'games' => $games->count(),
                    'won' => $won,
                    'lost' => $lost,
                    'rate' => $won + $lost > 0 ? (int) round(100 * $won / ($won + $lost)) : null,
                    'mmr' => ($m = $games->pluck('mmr')->filter()->avg()) ? (int) round($m) : null,
                    'exp' => $exp->isEmpty() ? null : (int) round($exp->avg()),
                    'last' => $games->max('at'),
                    // The line-ups it was played as, most common first: "Subtlety Rogue, Frost Mage, Discipline Priest".
                    'lineups' => $games->countBy(fn ($g) => implode(',', $g['specs']))->sortDesc()->take(3)
                        ->map(fn ($n, $ids) => ['specs' => collect(explode(',', $ids))->filter()->map(fn ($id) => $specs->spec((int) $id))->values()->all(), 'games' => $n])
                        ->values()->all(),
                    'matchups' => $games->groupBy('vs')->map(fn (Collection $g, string $vs) => [
                        'key' => $vs,
                        'nick' => $labels[$vs]['nick'] ?? null,
                        'classes' => $labels[$vs]['classes'] ?? [],
                        'games' => $g->count(),
                        'won' => $g->where('won', true)->count(),
                        'lost' => $g->where('won', false)->count(),
                    ])->sortByDesc('games')->values()->all(),
                ];
            })->sortByDesc(fn ($c) => [$c['games'], $c['rate'] ?? 0])->values()->all();

            return ['games' => $teams->pluck('match')->unique()->count(), 'comps' => $comps];
        }) + ['season' => $this->season()];
    }

    /** @return array{nick: ?string, classes: list<string>} */
    public static function label(string $key): array
    {
        $parts = explode('-', $key);
        $healer = array_pop($parts);
        $classes = $healer === 'none' ? $parts : array_merge($parts, [$healer]);

        return [
            'nick' => count($parts) === 2 && $healer !== 'none' ? CompLibraryService::nicknameFor(implode('+', $parts), $healer) : null,
            'classes' => $classes,
        ];
    }

    /**
     * One comp's card, from the side of every team that played against it this season, every
     * name replaced by its spec. With $vs, only the games against that comp. Null when there is
     * no game. Cached until a new game arrives.
     */
    public function card(string $key, ?string $vs = null): ?string
    {
        $teams = $this->teams()->where('key', $key)->when($vs, fn ($t) => $t->where('vs', $vs))->values();
        if ($teams->isEmpty()) {
            return null;
        }

        $cacheKey = 'comp-board:card:v2:'.md5($key.'|'.$vs.'|'.$teams->pluck('round')->implode(',').'|'.$this->library->codeSignature());

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($teams, $key, $vs) {
            // Drawn for the website: icons at /storage/..., not this machine's file:/// paths.
            config(['desktop.web_assets' => true]);
            // Only the analysis is read, out of each ~130 KB payload, and the parts of it the card
            // never uses go at once: a comp met 100 times is 100 payloads.
            $rounds = ArenaRound::query()->whereIn('id', $teams->pluck('round'))
                ->selectRaw("id, played_at, json_extract(payload, '$.analysis') as analysis_json, json_extract(payload, '$.metadata.durationInSeconds') as seconds")
                ->get()->keyBy('id');

            $games = collect();
            $names = [];
            foreach ($teams as $t) {
                $r = $rounds[$t['round']] ?? null;
                $a = $r ? json_decode((string) $r->analysis_json, true) : null;
                if (! is_array($a) || empty($a['players'])) {
                    continue;
                }
                unset($a['timeline'], $a['checks'], $a['breakdown'], $a['debuffs'], $a['dispels'], $a['overlaps']);
                // The card reads the comp as "them": a team that uploaded its own game is turned round.
                if ($t['side'] === 'us') {
                    $a = self::flip($a);
                }
                // Pooled, nobody is "you": the uploader's own flag would put a YOU on the card.
                foreach ($a['players'] as $i => $p) {
                    $a['players'][$i]['logger'] = false;
                }
                $them = collect($a['players'])->where('side', 'them');
                $games->push([
                    'r' => $r,
                    'a' => $a,
                    'seconds' => (float) $r->seconds,
                    'prefix' => '',
                    'dps' => $them->where('healer', false)->sortBy('spec')->values()->all(),
                    'healer' => $them->firstWhere('healer', true),
                ]);
                foreach ($a['players'] as $p) {
                    $names[] = $p['name'];
                }
            }
            if ($games->isEmpty()) {
                return null;
            }

            $label = self::label($key);
            $title = implode(' + ', array_map(fn ($c) => self::className($c), $label['classes']))
                .($vs ? ' vs '.(self::label($vs)['nick'] ?? implode(' + ', array_map(fn ($c) => self::className($c), self::label($vs)['classes']))) : '');
            $xp = app(PlayerExperienceService::class)->cachedMany(array_values(array_unique($names)));

            return $this->library->buildPool($games->sortBy(fn ($g) => (string) $g['r']->played_at)->values(), $title, $label['nick'], $xp);
        });
    }

    /**
     * The same game from the other team's side: every "us" becomes "them", the result and the MMR
     * swap.
     */
    public static function flip(array $a): array
    {
        $swap = fn (?string $s) => match ($s) {
            'us' => 'them', 'them' => 'us', default => $s
        };
        $sided = function (array $rows) use ($swap) {
            foreach ($rows as &$row) {
                if (is_array($row) && array_key_exists('side', $row)) {
                    $row['side'] = $swap($row['side']);
                }
            }

            return $rows;
        };

        foreach (['players', 'goes', 'deaths', 'kicks'] as $list) {
            if (isset($a[$list]) && is_array($a[$list])) {
                $a[$list] = $sided($a[$list]);
            }
        }
        if (isset($a['won'])) {
            $a['won'] = ! $a['won'];
        }
        if (isset($a['mmr']) && is_array($a['mmr'])) {
            $a['mmr'] = ['us' => $a['mmr']['them'] ?? null, 'them' => $a['mmr']['us'] ?? null];
        }
        if (isset($a['defensives']) && is_array($a['defensives'])) {
            $a['defensives'] = ['us' => $a['defensives']['them'] ?? [], 'them' => $a['defensives']['us'] ?? []];
        }

        return $a;
    }

    public static function className(string $slug): string
    {
        return config('wow_classes.names')[$slug] ?? ucwords(str_replace(['deathknight', 'demonhunter'], ['death knight', 'demon hunter'], $slug));
    }
}
