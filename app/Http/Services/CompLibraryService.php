<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The comp library, for the desktop app's Comps page (tools/log-manager): every enemy comp in the
 * player's own stored games, read for what helps the next time you meet it. Their goes (when the
 * first comes, which offensive cooldowns they press together, their crowd-control chains on you and
 * whom they go for), who dies and how on both sides, how defensives were traded, and what your
 * team left unpressed when they killed.
 *
 * GROUPED BY THE TWO DPS SPECS, ANY HEALER. Exact three-spec teams barely repeat: 221 different
 * line-ups in 258 rounds on 2026-10-04, only 4 met three times. The DPS pair is how players name a
 * comp anyway (TSG is a Warrior and a Death Knight, whoever heals), and it gave 28 comps met three
 * or more times. The healers each one ran are listed inside. Each bracket is its own library:
 * 3v3, Solo Shuffle and 2v2 are never pooled, because a shuffle team is three strangers re-dealt
 * every round and does not play like a premade.
 *
 * Built only from what RoundAnalysisService stored, like the cards and the Improve page. Every
 * figure carries its count, and under MatchAnalysisService::LEAD_BELOW games a page says it is a
 * lead. It describes what these teams did against you; it does not say what they always do.
 */
class CompLibraryService
{
    /**
     * Nicknames by class pair, from the repo's own machine-guide titles (data/machine-guides): a
     * name is only used where a guide uses it. Rogue and Mage depend on the healer.
     */
    private const NICKNAMES = [
        'deathknight+warrior' => 'TSG',
        'druid+hunter' => 'Jungle',
        'hunter+rogue' => 'Thug Cleave',
        'shaman+warrior' => 'Turbo Cleave',
        'warlock+warrior' => 'WLS',
        'priest+warlock' => 'Shadowplay',
        'deathknight+monk' => 'Walking Dead',
        'druid+paladin' => 'Boomkin Cleave',
    ];

    private const BY_HEALER = [
        'mage+rogue' => ['priest' => 'RMP', 'druid' => 'RMD'],
        'mage+warlock' => ['shaman' => 'MLS'],
    ];

    private array $xp = [];

    public function __construct(private SpellIconIndex $icons) {}

    public function signature(User $user, array $xp = []): string
    {
        $rounds = ArenaRound::where('user_id', $user->id)->orderBy('id')->get(['id', 'updated_at'])
            ->map(fn ($r) => $r->id.':'.$r->updated_at)->implode(',');
        $code = implode('|', array_map(fn ($f) => $f.':'.filemtime($f), [__FILE__, resource_path('views/desktop/comp.blade.php'), resource_path('views/desktop/partials/styles.blade.php')]));

        return md5($rounds.'|'.md5(json_encode(array_map(fn ($x) => $x['gladSeasons'] ?? null, $xp))).'|'.$code);
    }

    /**
     * @return array<string, array{name: string, nick: ?string, games: int, won: int, lost: int, last: string, characters: array<string, int>, html: string}>
     */
    public function build(User $user, array $xp = []): array
    {
        $this->xp = $xp;
        $groups = [];
        foreach (ArenaRound::where('user_id', $user->id)->orderBy('played_at')->get() as $r) {
            $a = $r->payload['analysis'] ?? null;
            if (! $a || empty($a['players'])) {
                continue;
            }
            $them = collect($a['players'])->where('side', 'them');
            $dps = $them->where('healer', false)->sortBy('spec')->values();
            if ($dps->isEmpty()) {
                continue;
            }
            // Each bracket is its own library: a Solo Shuffle team is three strangers re-dealt every
            // round and does not play like a 3v3 team that queued together (Chriso, 2026-10-04).
            $prefix = match (true) {
                $r->bracket === '2v2' => '2v2:',
                str_contains($r->bracket, 'Shuffle') => 'shuffle:',
                default => '',
            };
            $key = $prefix.$dps->pluck('spec')->implode('+');
            $groups[$key][] = ['r' => $r, 'a' => $a, 'prefix' => $prefix, 'dps' => $dps->all(), 'healer' => $them->firstWhere('healer', true)];
        }

        $out = [];
        foreach ($groups as $key => $games) {
            $model = $this->model($key, collect($games));
            $out[$key] = [
                'name' => $model['name'], 'nick' => $model['nick'], 'games' => $model['games'],
                'won' => $model['record'][0], 'lost' => $model['record'][1], 'last' => $model['last'],
                'characters' => $model['characters'],
                'html' => view('desktop.comp', ['c' => $model])->render(),
            ];
        }

        return $out;
    }

    private function model(string $key, Collection $games): array
    {
        $first = $games->first();
        $dps = collect($first['dps']);
        $classes = $dps->pluck('classSlug')->sort()->implode('+');
        $healers = $games->map(fn ($g) => $g['healer']['spec'] ?? null)->filter()->countBy()->sortDesc();
        $topHealerClass = $games->map(fn ($g) => $g['healer']['classSlug'] ?? null)->filter()->countBy()->sortDesc()->keys()->first();
        $nick = self::NICKNAMES[$classes] ?? (self::BY_HEALER[$classes][$topHealerClass] ?? null);
        $won = $games->filter(fn ($g) => $g['a']['won'])->count();

        $chars = $games->groupBy(fn ($g) => collect($g['a']['players'])->first(fn ($p) => ! empty($p['logger']))['name'] ?? '?');
        // Per character, so the app's list can show the picked character's own games and record.
        $characters = $chars->map(fn ($gs) => ['games' => $gs->count(), 'won' => $gs->filter(fn ($g) => $g['a']['won'])->count(), 'lost' => $gs->reject(fn ($g) => $g['a']['won'])->count()])->all();

        $theirGoes = $games->flatMap(fn ($g) => collect($g['a']['goes'])->where('side', 'them')->map(fn ($go) => $go + ['_g' => $g]));
        $ourGoes = $games->flatMap(fn ($g) => collect($g['a']['goes'])->where('side', 'us')->map(fn ($go) => $go + ['_g' => $g]));
        $nameOf = fn (array $g, ?string $guid) => collect($g['a']['players'])->firstWhere('guid', $guid);

        // ---- their goes
        $firstGo = $games->map(fn ($g) => collect($g['a']['goes'])->where('side', 'them')->min('from'))->filter(fn ($v) => $v !== null)->values();
        $offensive = $theirGoes->map(fn ($go) => collect($go['links'] ?? [])->whereIn('cat', ['offensive', 'mixed'])->pluck('spell')->unique()->sort()->values()->all());
        $offSpells = $offensive->flatten()->countBy()->sortDesc();
        // Which cooldowns they press together, as pairs: a whole set of six rarely repeats exactly,
        // but "Dark Transformation with Colossus Smash in 12 of 19 goes" is the pattern to expect.
        $offSets = $offensive->flatMap(function ($s) {
            $pairs = [];
            for ($i = 0; $i < count($s); $i++) {
                for ($j = $i + 1; $j < count($s); $j++) {
                    $pairs[] = $s[$i].' + '.$s[$j];
                }
            }

            return $pairs;
        })->countBy()->sortDesc()->filter(fn ($n) => $n >= 2);

        // Their crowd control on us, as each go's ordered chain of (spell > role), and its common runs.
        $chains = $theirGoes->map(fn ($go) => collect($go['links'] ?? [])->where('cat', 'control')
            ->filter(fn ($l) => ($nameOf($go['_g'], $l['on'] ?? null)['side'] ?? null) === 'us')
            ->map(fn ($l) => $l['spell'].' > '.($l['role'] ?? '?'))->values()->all());
        $runs = [];
        foreach ($chains as $chain) {
            $seen = [];
            for ($n = 3; $n >= 2; $n--) {
                for ($i = 0; $i + $n <= count($chain); $i++) {
                    $run = implode(' → ', array_slice($chain, $i, $n));
                    if (! isset($seen[$run])) {
                        $runs[$run] = ($runs[$run] ?? 0) + 1;
                        $seen[$run] = true;
                    }
                }
            }
        }
        arsort($runs);
        $runs = array_filter($runs, fn ($v) => $v >= 2);
        $ccOnHealer = $chains->flatten()->filter(fn ($s) => str_ends_with($s, '> healer'))->map(fn ($s) => substr($s, 0, -9))->countBy()->sortDesc();
        $targets = $theirGoes->map(fn ($go) => $nameOf($go['_g'], $go['target'] ?? null))->filter()->map(fn ($p) => $p['healer'] ? 'your healer' : $p['spec'])->countBy()->sortDesc();
        $theirKills = $theirGoes->filter(fn ($go) => $go['kill'] || $go['killLater'])->count();

        // ---- who dies
        // The first death on each side, in the games that side lost.
        $firstDeath = fn (array $g, string $side) => ($d = collect($g['a']['deaths'])->where('side', $side)->sortBy('t')->first()) ? $d + ['_g' => $g] : null;
        $ourDeaths = $games->reject(fn ($g) => $g['a']['won'])->map(fn ($g) => $firstDeath($g, 'us'))->filter();
        $theirDeaths = $games->filter(fn ($g) => $g['a']['won'])->map(fn ($g) => $firstDeath($g, 'them'))->filter();
        $whoDied = $ourDeaths->map(fn ($d) => ($p = $nameOf($d['_g'], $d['who'])) ? ($p['healer'] ? 'your healer ('.$p['spec'].')' : $p['spec'].($p['logger'] ? ' (you)' : '')) : '?')->countBy()->sortDesc();
        $killBlows = $ourDeaths->map(fn ($d) => $d['killingBlow']['spell'] ?? null)->filter()->countBy()->sortDesc();
        $healerLocked = $ourDeaths->filter(fn ($d) => in_array($d['healer']['state'] ?? '', ['locked'], true) || (($d['healer']['state'] ?? '') === 'ended' && ($d['healer']['endedAgo'] ?? 99) <= 1))->count();
        $deathAfter = $ourDeaths->map(fn ($d) => $d['goStartedAgo'])->filter(fn ($v) => $v !== null)->values();
        $theyLost = $theirDeaths->map(fn ($d) => ($p = $nameOf($d['_g'], $d['who'])) ? ($p['healer'] ? 'their healer ('.$p['spec'].')' : $p['spec']) : '?')->countBy()->sortDesc();

        // ---- defensives traded
        $theirAnswers = $ourGoes->flatMap(fn ($go) => collect($go['forced'] ?? [])->pluck('spell'))->countBy()->sortDesc();
        $ourAnswers = $theirGoes->flatMap(fn ($go) => collect($go['forced'] ?? [])->pluck('spell'))->countBy()->sortDesc();
        $cover = $theirGoes->filter(fn ($go) => isset($go['cover']));
        $bucket = fn ($lo, $hi) => $cover->filter(fn ($go) => $go['cover']['bigDown'] >= $lo && $go['cover']['bigDown'] <= $hi);
        $ourKillsFrom = $ourGoes->filter(fn ($go) => $go['kill'] || $go['killLater'])->count();
        $drainedKill = fn ($lo, $hi) => [($s = $ourGoes->filter(fn ($go) => ($go['drained'] ?? 0) >= $lo && ($go['drained'] ?? 0) <= $hi))->filter(fn ($go) => $go['kill'] || $go['killLater'])->count(), $s->count()];

        // ---- left unpressed in the losses (answer sheets, analysis version 7)
        $sheets = $ourDeaths->filter(fn ($d) => isset($d['answers']));
        $unused = $sheets->flatMap(fn ($d) => collect($d['answers']['rows'])->where('state', 'ready')->whereIn('kind', ['defensive', 'trinket'])
            ->map(fn ($r) => $r['spell'].' ('.(($p = $nameOf($d['_g'], $r['who'])) ? ($p['logger'] ? 'you' : $p['spec']) : '?').')')->unique())->countBy()->sortDesc();

        // ---- difficulty
        $difficulty = $games->map(function ($g) {
            $a = $g['a'];
            $sum = fn ($side) => (int) collect($a['players'])->where('side', $side)->sum(fn ($p) => $this->xp[$p['name']]['gladSeasons'] ?? 0);

            return GameCardService::difficultyOf((int) (($a['mmr']['them'] ?? 0) - ($a['mmr']['us'] ?? 0)), isset($a['mmr']['us'], $a['mmr']['them']), $sum('them'), $sum('us'))['label'];
        })->countBy();

        $pct = fn (int $n, int $of) => $of > 0 ? sprintf('%d%% (%d of %d)', round(100 * $n / $of), $n, $of) : '-';

        // ---- each game, and the comp split by how experienced the team was
        $gladOf = function (array $g) {
            $known = collect($g['a']['players'])->where('side', 'them')->filter(fn ($p) => isset($this->xp[$p['name']]) && ($this->xp[$p['name']]['found'] ?? false));

            return $known->isEmpty() ? null : (int) $known->sum(fn ($p) => $this->xp[$p['name']]['gladSeasons'] ?? 0);
        };
        $colors = config('wow_classes.colors');
        $list = $games->sortByDesc(fn ($g) => (string) $g['r']->played_at)->map(function ($g) use ($gladOf, $nameOf, $colors) {
            $a = $g['a'];
            $first = collect($a['deaths'])->sortBy('t')->first();
            $p = $first ? $nameOf($g, $first['who']) : null;
            $goes = collect($a['goes']);
            $kicks = collect($a['kicks'] ?? []);
            $healer = collect($a['players'])->first(fn ($x) => $x['side'] === 'us' && $x['healer']);
            // Each player, for the row's detail panel: experience as the profile shows it now.
            $player = function ($x) use ($colors) {
                $xp = $this->xp[$x['name']] ?? null;

                return [
                    'name' => explode('-', $x['name'])[0],
                    'spec' => $x['spec'],
                    'color' => $colors[$x['classSlug'] ?? ''] ?? '#8A8A9A',
                    'you' => ! empty($x['logger']),
                    'xp' => match (true) {
                        $xp === null => 'not looked up',
                        ! ($xp['found'] ?? false) => 'no profile',
                        default => implode(' · ', array_filter([
                            ($xp['gladSeasons'] ?? 0) > 0 ? $xp['gladSeasons'].'× Glad' : null,
                            'best '.($xp['exp3v3'] ?? '?'),
                            $xp['bestRank'] ?? null,
                        ])),
                    },
                    'glad' => ($xp['gladSeasons'] ?? 0) > 0,
                ];
            };
            $sum = fn (string $side) => (int) collect($a['players'])->where('side', $side)->sum(fn ($x) => $this->xp[$x['name']]['gladSeasons'] ?? 0);

            return [
                'id' => 'g'.substr(md5((string) $g['r']->id), 0, 8),
                'players' => [
                    'them' => collect($a['players'])->where('side', 'them')->sortByDesc('healer')->map($player)->values()->all(),
                    'us' => collect($a['players'])->where('side', 'us')->sortByDesc('healer')->map($player)->values()->all(),
                ],
                'stats' => array_filter([
                    'Length' => $this->clock((float) ($g['r']->payload['metadata']['durationInSeconds'] ?? 0)),
                    'Difficulty' => GameCardService::difficultyOf((int) (($a['mmr']['them'] ?? 0) - ($a['mmr']['us'] ?? 0)), isset($a['mmr']['us'], $a['mmr']['them']), $sum('them'), $sum('us'))['label']
                        .' ('.$sum('them').' Gladiator seasons to your '.$sum('us').')',
                    'Goes, yours / theirs' => $goes->where('side', 'us')->count().' / '.$goes->where('side', 'them')->count(),
                    'Goes that killed, yours / theirs' => $goes->where('side', 'us')->filter(fn ($x) => $x['kill'] || $x['killLater'])->count().' / '.$goes->where('side', 'them')->filter(fn ($x) => $x['kill'] || $x['killLater'])->count(),
                    'Defensives before the first death, yours / theirs' => ($a['defensives']['us']['spent'] ?? 0).' / '.($a['defensives']['them']['spent'] ?? 0),
                    'Interrupts, yours / theirs' => $kicks->where('side', 'us')->count().' / '.$kicks->where('side', 'them')->count(),
                    'Your healer locked out' => $healer ? round($a['lockout'][$healer['guid']] ?? 0, 1).'s' : null,
                ], fn ($v) => $v !== null),

                'when' => $g['r']->played_at?->format('D j M, H:i'),
                'who' => explode('-', collect($g['a']['players'])->first(fn ($x) => ! empty($x['logger']))['name'] ?? '?')[0],
                'won' => $g['a']['won'],
                'mmr' => isset($g['a']['mmr']['us'], $g['a']['mmr']['them']) ? $g['a']['mmr']['us'].' / '.$g['a']['mmr']['them'] : null,
                'glad' => $gladOf($g),
                'death' => $p ? ($p['side'] === 'us' ? 'yours: ' : 'theirs: ').($p['healer'] ? 'healer ('.$p['spec'].')' : $p['spec']).' at '.$this->clock($first['t']) : null,
            ];
        })->values()->all();

        $byExperience = $this->byExperience($games, $gladOf, $pct);
        $ofGoes = fn (Collection $counts, int $total, int $take = 6) => $counts->take($take)->map(fn ($n, $k) => ['label' => $k, 'value' => $pct($n, $total)])->values()->all();
        $median = fn (Collection $v) => $v->isEmpty() ? null : round($v->sort()->values()->median(), 1);

        return [
            'key' => $key,
            'name' => (['2v2:' => '2v2: ', 'shuffle:' => 'Solo Shuffle: '][$games->first()['prefix']] ?? '')
                .$dps->pluck('spec')->implode(' + '),
            'nick' => $nick,
            'games' => $games->count(),
            'lead' => $games->count() < MatchAnalysisService::LEAD_BELOW,
            'record' => [$won, $games->count() - $won],
            'last' => (string) $games->last()['r']->played_at,
            'from' => substr((string) $games->first()['r']->played_at, 0, 10),
            'to' => substr((string) $games->last()['r']->played_at, 0, 10),
            'characters' => $characters,
            'byCharacter' => $chars->map(fn ($gs, $full) => ['name' => explode('-', $full)[0], 'won' => $gs->filter(fn ($g) => $g['a']['won'])->count(), 'lost' => $gs->reject(fn ($g) => $g['a']['won'])->count()])->values()->all(),
            'healers' => $healers->map(fn ($n, $spec) => ['spec' => $spec, 'games' => $n])->values()->all(),
            'difficulty' => $difficulty->all(),
            'goes' => [
                'count' => $theirGoes->count(),
                'perGame' => round($theirGoes->count() / max(1, $games->count()), 1),
                'firstAt' => $median($firstGo),
                'killed' => $pct($theirKills, $theirGoes->count()),
                'offensive' => $this->withIcons($ofGoes($offSpells, $theirGoes->count())),
                'sets' => $ofGoes($offSets, $theirGoes->count(), 5),
                'chains' => collect($runs)->take(5)->map(fn ($n, $run) => ['label' => $run, 'value' => $n.' goes'])->values()->all(),
                'onHealer' => $this->withIcons($ccOnHealer->take(6)->map(fn ($n, $s) => ['label' => $s, 'value' => $n.'×'])->values()->all()),
                'targets' => $ofGoes($targets, $theirGoes->count(), 4),
            ],
            'deaths' => [
                'losses' => $ourDeaths->count(),
                'who' => $ofGoes($whoDied, $ourDeaths->count(), 4),
                'blows' => $this->withIcons($ofGoes($killBlows, $ourDeaths->count(), 5)),
                'healerLocked' => $pct($healerLocked, $ourDeaths->count()),
                'after' => $median($deathAfter),
                'wins' => $theirDeaths->count(),
                'theirs' => $ofGoes($theyLost, $theirDeaths->count(), 4),
            ],
            'trading' => [
                'ourGoes' => $ourGoes->count(),
                'ourKill' => $pct($ourKillsFrom, $ourGoes->count()),
                'theirAnswers' => $this->withIcons($theirAnswers->take(6)->map(fn ($n, $s) => ['label' => $s, 'value' => $n.'×'])->values()->all()),
                'perOurGo' => round($ourGoes->sum(fn ($go) => count($go['forced'] ?? [])) / max(1, $ourGoes->count()), 1),
                'drain' => ['low' => $drainedKill(0, 1), 'high' => $drainedKill(2, 99)],
                'ourAnswers' => $this->withIcons($ourAnswers->take(6)->map(fn ($n, $s) => ['label' => $s, 'value' => $n.'×'])->values()->all()),
                'perTheirGo' => round($theirGoes->sum(fn ($go) => count($go['forced'] ?? [])) / max(1, $theirGoes->count()), 1),
                'cover' => $cover->isEmpty() ? null : [
                    'none' => $pct($bucket(0, 0)->filter(fn ($go) => $go['kill'] || $go['killLater'])->count(), $bucket(0, 0)->count()),
                    'one' => $pct($bucket(1, 1)->filter(fn ($go) => $go['kill'] || $go['killLater'])->count(), $bucket(1, 1)->count()),
                    'two' => $pct($bucket(2, 99)->filter(fn ($go) => $go['kill'] || $go['killLater'])->count(), $bucket(2, 99)->count()),
                ],
            ],
            'list' => $list,
            'byExperience' => $byExperience,
            'unused' => ['losses' => $sheets->count(), 'rows' => $this->withIcons($unused->take(8)->map(fn ($n, $s) => ['label' => $s, 'value' => $n.' of '.$sheets->count()])->values()->all(), true)],
        ];
    }

    /** Fewer games than this on either side and the experience comparison waits. */
    private const EXPERIENCE_SIDE_MIN = 2;

    /**
     * The comp split by how experienced the team was: their Gladiator seasons, summed over the
     * three (or two) of them whose profile was found. Experience, not MMR, because early in a
     * season MMR is deflated and a Solo Shuffle round has none (rule 12), while a Gladiator title
     * says what a player has done. Split at the line that divides the comp's own games most
     * evenly, so each comp compares its own stronger and weaker teams; a game none of whose players
     * were looked up stays out.
     *
     * @return array{state: string, threshold?: int, lower?: array, higher?: array, need?: int, known: int}
     */
    private function byExperience(Collection $games, callable $gladOf, callable $pct): array
    {
        $known = $games->map(fn ($g) => $g + ['_glad' => $gladOf($g)])->filter(fn ($g) => $g['_glad'] !== null)->values();
        if ($known->count() < 2 * self::EXPERIENCE_SIDE_MIN) {
            return ['state' => 'few', 'known' => $known->count(), 'need' => 2 * self::EXPERIENCE_SIDE_MIN - $known->count()];
        }
        // The dividing line: of every value a team has (above the lowest), the one that splits the
        // games most evenly. Many teams share a count (often 0), so a plain median can leave one
        // side empty.
        $threshold = null;
        $best = PHP_INT_MAX;
        foreach ($known->pluck('_glad')->unique()->sort()->values()->slice(1) as $line) {
            $up = $known->filter(fn ($g) => $g['_glad'] >= $line)->count();
            $down = $known->count() - $up;
            if ($up >= self::EXPERIENCE_SIDE_MIN && $down >= self::EXPERIENCE_SIDE_MIN && abs($up - $down) < $best) {
                $best = abs($up - $down);
                $threshold = (int) $line;
            }
        }
        if ($threshold === null) {
            return ['state' => 'flat', 'known' => $known->count()];
        }
        $higher = $known->filter(fn ($g) => $g['_glad'] >= $threshold);
        $lower = $known->filter(fn ($g) => $g['_glad'] < $threshold);

        $measure = function (Collection $set) use ($pct) {
            $theirGoes = $set->flatMap(fn ($g) => collect($g['a']['goes'])->where('side', 'them'));
            $ourGoes = $set->flatMap(fn ($g) => collect($g['a']['goes'])->where('side', 'us'));
            $firstGo = $set->map(fn ($g) => collect($g['a']['goes'])->where('side', 'them')->min('from'))->filter(fn ($v) => $v !== null)->values();
            $deathAt = $set->reject(fn ($g) => $g['a']['won'])
                ->map(fn ($g) => collect($g['a']['deaths'])->where('side', 'us')->sortBy('t')->first()['t'] ?? null)->filter(fn ($v) => $v !== null)->values();
            $offensive = $theirGoes->flatMap(fn ($go) => collect($go['links'] ?? [])->whereIn('cat', ['offensive', 'mixed'])->pluck('spell')->unique())->countBy()->sortDesc();
            $answers = $ourGoes->flatMap(fn ($go) => collect($go['forced'] ?? [])->pluck('spell'))->countBy()->sortDesc();
            $won = $set->filter(fn ($g) => $g['a']['won'])->count();
            $median = fn (Collection $v) => $v->isEmpty() ? '-' : round($v->sort()->values()->median()).'s';

            return [
                'Games' => $set->count(),
                'Your record' => $won.'-'.($set->count() - $won),
                'Their Gladiator seasons, median' => (int) $set->pluck('_glad')->sort()->values()->median(),
                'Their goes a game' => number_format($theirGoes->count() / max(1, $set->count()), 1),
                'Their first go' => $median($firstGo),
                'Their goes that killed one of you' => $pct($theirGoes->filter(fn ($go) => $go['kill'] || $go['killLater'])->count(), $theirGoes->count()),
                'Your first death, in losses' => $median($deathAt),
                'Your goes that killed' => $pct($ourGoes->filter(fn ($go) => $go['kill'] || $go['killLater'])->count(), $ourGoes->count()),
                'Their answers to your go, each' => number_format($ourGoes->sum(fn ($go) => count($go['forced'] ?? [])) / max(1, $ourGoes->count()), 1),
                'Their offensive cooldowns, most used' => $offensive->take(3)->keys()->implode(', ') ?: '-',
                'What they answered your goes with' => $answers->take(3)->keys()->implode(', ') ?: '-',
            ];
        };

        return ['state' => 'split', 'threshold' => $threshold, 'known' => $known->count(), 'lower' => $measure($lower), 'higher' => $measure($higher)];
    }

    private function clock(float $seconds): string
    {
        return sprintf('%d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    }

    /**
     * Adds each row's spell icon. A label may carry a suffix after the spell (" (you)"), which
     * $stripSuffix drops for the lookup.
     */
    private function withIcons(array $rows, bool $stripSuffix = false): array
    {
        $spell = fn ($label) => $stripSuffix ? preg_replace('/ \([^)]*\)$/', '', $label) : $label;
        $icons = $this->icons->for(array_map(fn ($r) => $spell($r['label']), $rows));

        return array_map(fn ($r) => $r + ['icon' => ($s = $icons[$spell($r['label'])] ?? null)?->icon_name
            ? 'file:///'.str_replace('\\', '/', storage_path('app/public/spell-icons/'.$s->icon_name)) : null], $rows);
    }
}
