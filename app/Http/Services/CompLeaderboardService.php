<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\ArenaRoundPlayer;
use App\Models\PvpLeaderboardEntry;
use App\Support\PlayerUrl;
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

    /**
     * The leaderboard's experience filter (2026-10-21, Chriso: "the comps with exp and mmr and then
     * matches ... just comp filtered by EXP"): the team's experience at least this, by the rating
     * milestones players already name. A team's experience is its players' best 3v3 rating ever,
     * averaged over those Blizzard has a profile for.
     */
    public const EXP_BANDS = [1800 => 'Rival', 2100 => 'Duelist', 2400 => 'Gladiator rating'];

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
        // Once a request (2026-10-30): the page asks for the teams four or five times (the board, the
        // games, the specs and regions seen), and each read of the cache unserialises every team.
        if ($this->teams !== null) {
            return $this->teams;
        }
        // Kept until a 3v3 round is added or re-measured: one cheap query decides, so the board,
        // a card and the card's page never regroup every player on each request.
        $now = ArenaRoundPlayer::where('bracket', '3v3')->selectRaw('count(*) as n, max(id) as m')->first();
        $key = 'comp-board:teams:v4:'.$now->n.':'.$now->m.':'.$this->season()['start']->timestamp;

        return $this->teams = Cache::remember($key, now()->addHours(6), fn () => $this->groupTeams());
    }

    private ?Collection $teams = null;

    /** A short signature of the teams, for the keys of what is cached from them. */
    private function signature(): string
    {
        $teams = $this->teams();

        return $teams->count().':'.$teams->max('round');
    }

    private function groupTeams(): Collection
    {
        $rows = ArenaRoundPlayer::query()
            ->where('bracket', '3v3')
            ->where('played_at', '>=', $this->season()['start'])
            ->orderBy('arena_round_id')
            ->get(['arena_round_id', 'match_id', 'side', 'healer', 'spec_external_id', 'class_slug', 'won', 'mmr', 'played_at', 'name', 'realm', 'realm_key', 'region']);

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
                    // The game's region, from its players (2026-10-30): Chinese games can be told apart.
                    'region' => strtolower((string) $team->first()->region),
                    'names' => $team->map(fn ($p) => $p->name.'-'.$p->realm.'-'.strtoupper($p->region))->values()->all(),
                    // Who played, as the ladder is matched (whoKey()), with their class.
                    'who' => $team->map(fn ($p) => ['k' => self::whoKey($p->region, $p->realm_key, $p->name), 'n' => $p->name, 'c' => $p->class_slug])->values()->all(),
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
     * played it, its usual specs, and its record against each comp it met. With $minExp (a key of
     * EXP_BANDS), only the teams whose experience is known and at least that; any other value is
     * the whole board. With $spec (Blizzard's spec id, 2026-10-29), only the teams that had one.
     *
     * @return array{season: array, games: int, teams: int, known: int, minExp: ?int, comps: list<array>}
     */
    public function board(?int $minExp = null, ?int $spec = null, ?string $region = null): array
    {
        $minExp = isset(self::EXP_BANDS[$minExp]) ? $minExp : null;
        $teams = $this->teams();
        $spec = $spec && $teams->contains(fn ($t) => in_array($spec, $t['specs'], true)) ? $spec : null;
        $region = $region !== null && isset($this->regionsSeen()[$region]) ? $region : null;
        $signature = $this->signature();

        return Cache::remember('comp-board:v5:'.$signature.':'.($minExp ?? 'all').':'.($spec ?? 'any').':'.($region ?? 'any'), now()->addMinutes(self::TTL_MINUTES), function () use ($teams, $minExp, $spec, $region) {
            if ($region) {
                $teams = $teams->where('region', $region)->values();
            }
            $xp = app(PlayerExperienceService::class)->cachedMany($teams->pluck('names')->flatten()->unique()->values()->all());
            $specs = app(PlayerGamesService::class);
            $labels = $teams->pluck('key')->unique()->mapWithKeys(fn ($k) => [$k => self::label($k)]);

            // A team's experience: its best 3v3 rating ever, averaged over the players Blizzard has.
            $teams = $teams->map(fn ($t) => $t + ['exp' => collect($t['names'])->map(fn ($n) => $xp[$n]['exp3v3'] ?? null)->filter()->avg()]);
            $known = $teams->whereNotNull('exp')->count();
            $all = $teams->count();
            if ($minExp) {
                $teams = $teams->filter(fn ($t) => $t['exp'] !== null && $t['exp'] >= $minExp);
            }
            if ($spec) {
                $teams = $teams->filter(fn ($t) => in_array($spec, $t['specs'], true));
            }

            // The players of these teams who are on Blizzard's 3v3 ladder, for each comp's "top players seen".
            $ladder = $this->ladderRatings($teams->pluck('who')->flatten(1));

            $comps = $teams->groupBy('key')->map(function (Collection $games, string $key) use ($specs, $labels, $ladder) {
                $won = $games->where('won', true)->count();
                $lost = $games->where('won', false)->count();
                $exp = $games->pluck('exp')->filter();

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
                    // The highest-rated players on Blizzard's 3v3 ladder seen playing it (Leaderboards' comps tab).
                    'top' => $games->pluck('who')->flatten(1)->unique('k')
                        ->map(fn ($w) => isset($ladder[$w['k']]) ? $ladder[$w['k']] + ['class' => $w['c']] : null)
                        ->filter()->sortByDesc('rating')->take(3)->values()->all(),
                    // The line-ups it was played as, most common first: "Subtlety Rogue, Frost Mage, Discipline Priest".
                    'lineups' => $games->countBy(fn ($g) => implode(',', $g['specs']))->sortDesc()->take(3)
                        ->map(fn ($n, $ids) => [
                            'specs' => collect(explode(',', $ids))->filter()->map(fn ($id) => $specs->spec((int) $id))->values()->all(),
                            // Blizzard's spec ids, so the comps page can load the line-up into its slots.
                            'ids' => collect(explode(',', $ids))->filter()->map(fn ($id) => (int) $id)->values()->all(),
                            'games' => $n,
                        ])
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

            return ['games' => $teams->pluck('match')->unique()->count(), 'teams' => $all, 'known' => $known, 'minExp' => $minExp, 'spec' => $spec, 'region' => $region, 'comps' => $comps];
        }) + ['season' => $this->season()];
    }

    /**
     * Every spec played in a 3v3 game this season, for the board's spec filter: Blizzard's id and its
     * name, by class then spec.
     *
     * @return list<array{id: int, label: string, class: ?string}>
     */
    public function specsSeen(): array
    {
        return Cache::remember('comp-board:specs:v1:'.$this->signature(), now()->addMinutes(self::TTL_MINUTES), function () {
            $specs = app(PlayerGamesService::class);

            return $this->teams()->pluck('specs')->flatten()->unique()
                ->map(fn ($id) => ['id' => (int) $id] + $specs->spec((int) $id))
                ->sortBy(fn ($s) => [$s['class'] ?? 'z', $s['label']])->values()->all();
        });
    }

    /** Names of the regions games come from. */
    public const REGIONS = ['us' => 'Americas', 'eu' => 'Europe', 'kr' => 'Korea', 'tw' => 'Taiwan', 'cn' => 'China'];

    /**
     * The regions this season's games come from, with their game counts, most first: ['us' => 829,
     * 'cn' => 134]. For the board's region filter (2026-10-30, Chriso: "filter and seperate Chinese
     * games from English").
     *
     * @return array<string, int>
     */
    public function regionsSeen(): array
    {
        return $this->regions ??= $this->teams()->where('side', 'us')->countBy('region')
            ->filter(fn ($n, $r) => isset(self::REGIONS[$r]))->sortDesc()->all();
    }

    private ?array $regions = null;

    /** One player as the ladder and the uploaded games both name them: "us|area52|rogueguy". */
    public static function whoKey(string $region, string $realm, string $name): string
    {
        return strtolower($region).'|'.RealmDirectory::key($realm).'|'.mb_strtolower($name);
    }

    /** The latest season of Blizzard's 3v3 ladder held for each region: ['us' => 40, ...]. */
    private function ladderSeasons(): array
    {
        return PvpLeaderboardEntry::where('bracket', '3v3')->selectRaw('region, max(season) as s')->groupBy('region')
            ->pluck('s', 'region')->map(fn ($s) => (int) $s)->all();
    }

    /**
     * Of these players (each ['k' => whoKey, 'n' => name]), the ones on their region's 3v3 ladder
     * this season, by whoKey. Asked by name, which is indexed, and matched on region and realm.
     *
     * @return array<string, array{name: string, realm: string, region: string, rating: int, rank: int, url: string}>
     */
    private function ladderRatings(Collection $who): array
    {
        $seasons = $this->ladderSeasons();
        if ($seasons === [] || $who->isEmpty()) {
            return [];
        }
        $wanted = $who->pluck('k')->flip();
        $directory = app(RealmDirectory::class);
        $out = [];
        foreach ($who->pluck('n')->unique()->chunk(1000) as $names) {
            PvpLeaderboardEntry::where('bracket', '3v3')->whereIn('name', $names->values()->all())
                ->get(['region', 'season', 'name', 'realm_slug', 'rating', 'rank'])
                ->each(function ($e) use (&$out, $wanted, $seasons, $directory) {
                    $k = self::whoKey($e->region, $e->realm_slug, $e->name);
                    if (($seasons[$e->region] ?? null) !== (int) $e->season || ! isset($wanted[$k])) {
                        return;
                    }
                    $out[$k] = [
                        'name' => $e->name,
                        'realm' => $directory->nameFor($e->realm_slug, $e->region) ?? $e->realm_slug,
                        'region' => $e->region,
                        'rating' => (int) $e->rating,
                        'rank' => (int) $e->rank,
                        'url' => PlayerUrl::to($e->region, $e->realm_slug, $e->name, false),
                    ];
                });
        }

        return $out;
    }

    /**
     * Blizzard's 3v3 ladder for a region this season (Leaderboards' players tab), each player with
     * the comps they were seen playing in the games uploaded this season. With $seenOnly, only the
     * players seen in an uploaded game. Null season when no ladder has been pulled.
     *
     * @return array{season: ?int, asOf: ?Carbon, total: int, seen: int, players: list<array>}
     */
    public function ladder(string $region, bool $seenOnly = false, int $limit = 100): array
    {
        $season = $this->ladderSeasons()[$region] ?? null;
        if (! $season) {
            return ['season' => null, 'asOf' => null, 'total' => 0, 'seen' => 0, 'players' => []];
        }
        $teams = $this->teams();
        $asOf = PvpLeaderboardEntry::where('region', $region)->where('season', $season)->where('bracket', '3v3')->max('seen_at');
        $cacheKey = 'comp-board:ladder:v1:'.implode(':', [$region, $season, $asOf, $teams->count(), $teams->max('round'), (int) $seenOnly, $limit]);

        return Cache::remember($cacheKey, now()->addMinutes(self::TTL_MINUTES), function () use ($region, $season, $teams, $seenOnly, $limit, $asOf) {
            // Every comp each player was seen on, and their class from those games.
            $seen = [];
            foreach ($teams as $t) {
                foreach ($t['who'] as $w) {
                    $seen[$w['k']]['comps'][$t['key']] = ($seen[$w['k']]['comps'][$t['key']] ?? 0) + 1;
                    $seen[$w['k']]['class'] ??= $w['c'];
                }
            }

            $rows = PvpLeaderboardEntry::where('region', $region)->where('season', $season)->where('bracket', '3v3')
                ->orderBy('rank')->get(['blizzard_character_id', 'name', 'realm_slug', 'rank', 'rating', 'won', 'lost']);
            $rows->each(fn ($e) => $e->who = self::whoKey($region, $e->realm_slug, $e->name));
            $seenCount = $rows->filter(fn ($e) => isset($seen[$e->who]))->count();
            $shown = ($seenOnly ? $rows->filter(fn ($e) => isset($seen[$e->who])) : $rows)->take($limit)->values();

            // A 3v3 entry names no spec; the same character's Shuffle or Blitz entries do.
            $classes = PvpLeaderboardEntry::where('region', $region)->where('season', $season)->whereNotNull('class_slug')
                ->whereIn('blizzard_character_id', $shown->pluck('blizzard_character_id'))->pluck('class_slug', 'blizzard_character_id');
            $directory = app(RealmDirectory::class);

            return [
                'season' => $season,
                'asOf' => $asOf ? Carbon::parse($asOf) : null,
                'total' => $rows->count(),
                'seen' => $seenCount,
                'players' => $shown->map(fn ($e) => [
                    'rank' => (int) $e->rank,
                    'name' => $e->name,
                    'realm' => $directory->nameFor($e->realm_slug, $region) ?? $e->realm_slug,
                    'url' => PlayerUrl::to($region, $e->realm_slug, $e->name, false),
                    'class' => $classes[$e->blizzard_character_id] ?? ($seen[$e->who]['class'] ?? null),
                    'rating' => (int) $e->rating,
                    'won' => (int) $e->won,
                    'lost' => (int) $e->lost,
                    'comps' => collect($seen[$e->who]['comps'] ?? [])->sortDesc()->take(3)
                        ->map(fn ($n, $key) => self::label($key) + ['key' => $key, 'games' => $n])->values()->all(),
                ])->all(),
            ];
        });
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

        $cacheKey = 'comp-board:card:v3:'.md5($key.'|'.$vs.'|'.$teams->pluck('round')->implode(',').'|'.$this->library->codeSignature());

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
                    // The game's own card, read from the same side as this one: the team facing the comp.
                    'href' => route('comps.game', array_filter(['round' => $r->id, 'as' => $t['side'] === 'us' ? 'them' : null, 'from' => $key, 'vs' => $vs])),
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
     * Every 3v3 game this season, newest first, one row per match (Leaderboards' Games tab), each
     * with both teams as the uploader's card reads them: `us` is the team that uploaded it.
     *
     * @return array{total: int, games: list<array>}
     */
    public function recentGames(int $limit = 50, array $filters = []): array
    {
        // The Games tab's filters (2026-10-30): a spec or a comp on either team, the region, and the
        // game's rating (the two teams' MMR averaged) at least a band.
        $spec = isset($filters['spec']) ? (int) $filters['spec'] : null;
        $comp = isset($filters['comp']) ? (string) $filters['comp'] : null;
        $region = isset($filters['region'], $this->regionsSeen()[$filters['region']]) ? $filters['region'] : null;
        $mmr = isset($filters['mmr'], self::EXP_BANDS[(int) $filters['mmr']]) ? (int) $filters['mmr'] : null;

        $key = 'comp-board:games:v2:'.$this->signature().':'.$limit.':'.md5(json_encode([$spec, $comp, $region, $mmr]));

        return Cache::remember($key, now()->addMinutes(self::TTL_MINUTES), function () use ($limit, $spec, $comp, $region, $mmr) {
            $matches = $this->teams()->groupBy('match')->filter(fn (Collection $sides) => $sides->count() === 2);
            if ($spec || $comp || $region || $mmr) {
                $matches = $matches->filter(function (Collection $sides) use ($spec, $comp, $region, $mmr) {
                    if ($region && $sides->first()['region'] !== $region) {
                        return false;
                    }
                    if ($comp && ! $sides->contains('key', $comp)) {
                        return false;
                    }
                    if ($spec && ! $sides->contains(fn ($t) => in_array($spec, $t['specs'], true))) {
                        return false;
                    }
                    if ($mmr) {
                        $avg = $sides->pluck('mmr')->filter()->avg();
                        if (! $avg || $avg < $mmr) {
                            return false;
                        }
                    }

                    return true;
                });
            }
            $matches = $matches->sortByDesc(fn (Collection $sides) => (string) $sides->first()['at']);
            $side = function (array $t) {
                $label = self::label($t['key']);

                return ['key' => $t['key'], 'nick' => $label['nick'], 'classes' => $label['classes'], 'ids' => $t['specs'], 'won' => $t['won'], 'mmr' => $t['mmr']];
            };

            return [
                'total' => $matches->count(),
                'games' => $matches->take($limit)->map(fn (Collection $sides) => [
                    'round' => $sides->first()['round'],
                    'at' => $sides->first()['at'],
                    'region' => $sides->first()['region'],
                    'us' => $side($sides->firstWhere('side', 'us')),
                    'them' => $side($sides->firstWhere('side', 'them')),
                ])->values()->all(),
            ];
        });
    }

    /**
     * One game's card for the website (CompGame): only a 3v3 game of this season's board, read as
     * the uploader's team or, with $as 'them', as the team they played. Null for any other round.
     */
    public function game(int $round, string $as = 'us'): ?string
    {
        $sides = $this->teams()->where('round', $round)->keyBy('side');
        if ($sides->count() !== 2) {
            return null;
        }
        $flip = $as === 'them';
        $r = ArenaRound::find($round);
        if ($r === null) {
            return null;
        }
        $cards = app(GameCardService::class);
        $name = function (string $key) {
            $label = self::label($key);

            return $label['nick'] ?? implode(' + ', array_map(fn ($c) => self::className($c), $label['classes']));
        };

        return Cache::remember('comp-board:game:v1:'.$round.':'.($flip ? 'them' : 'us').':'.$r->updated_at?->timestamp.':'.$cards->codeFingerprint(),
            now()->addHours(12), function () use ($cards, $r, $flip, $sides, $name) {
                config(['desktop.web_assets' => true]);

                return $cards->pooled($r, $flip, [
                    'us' => $name($sides[$flip ? 'them' : 'us']['key']),
                    'them' => $name($sides[$flip ? 'us' : 'them']['key']),
                ]);
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
