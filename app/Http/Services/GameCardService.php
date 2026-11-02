<?php

namespace App\Http\Services;

use App\Models\ArenaRound;
use App\Models\Specialization;
use App\Models\User;
use App\Support\DefensiveFit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One game, read for the desktop app (tools/log-manager): who you played and how experienced they
 * are, how each death happened, both sides' goes, defensives, interrupts and time crowd-controlled,
 * for a loss the same "worth a look" rules the site's loss split uses, the checks and the damage
 * and healing breakdown (RoundAnalysisService version 5), and your own notes: per round from the
 * game panel, and per game from the Matches page (on that game, or on the next one played).
 *
 * Built only from what RoundAnalysisService stored (`arena_rounds.payload['analysis']`), the
 * experience PlayerExperienceService cached and the app's notes file, so a whole history renders in
 * a couple of seconds and nothing here reads a raw log or calls Blizzard. `wow:sync` does both.
 *
 * Rendered as a standalone HTML page (resources/views/desktop/game-card.blade.php) that the app
 * shows in Windows' built-in browser control, which is IE11: the view uses no CSS variables, grid
 * or flex gaps. Icons are local files (file:// URLs into storage/app/public), because the app has
 * no web server behind it.
 *
 * Experience is cached for 7 days. The command keeps what an earlier run saw for a player whose
 * cache has since lapsed, so an old game's card does not lose its opponents' experience.
 */
class GameCardService
{
    /** A note this long after a round ends still belongs to it: the scoreboard, the queue. */
    private const NOTE_AFTER = 300;

    public function __construct(
        private PlayerExperienceService $experience,
        private MatchAnalysisService $analysis,
        private SpellIconIndex $icons,
        private GameBasicsService $basics,
        private GameMomentsService $moments,
        private ImprovementService $improvement,
        private CooldownLedgerService $ledger,
        private ArenaLogService $arena,
    ) {}

    /** @var array<string, array<string, true>> spec => what its dispels were seen removing, for the moments */
    private array $dispellable = [];

    /**
     * Each game carries a signature (`sig`) of everything its card is drawn from: its rounds as
     * stored, the notes that land on it, its players' experience and the card code itself. A game
     * whose signature is in $known is not drawn again and comes back with `html` null; the caller
     * keeps the card it already has. Before this every sync and every note redrew every card (4s
     * for 146 games on 2026-10-02, and growing with each game played).
     *
     * @param  array<string, array>  $remembered  experience by full name from an earlier run
     * @param  array<int, array>  $notes  the app's notes (tools/log-manager), each with `at` and `text`
     * @param  array<string, string>  $known  lobby id => the signature of the card already drawn
     * @return array{games: array<string, array>, experience: array<string, array>}
     */
    public function build(User $user, array $remembered = [], array $notes = [], array $known = [], ?float $deadline = null): array
    {
        $this->payloads = [];

        // Each round's list entry and signature need only its players, result, dispels and length
        // (2026-10-28): every round decoded and kept for the whole build was 650 MB for a 700-round
        // account. A card being drawn reads its own rounds in full, one game at a time.
        $this->summaries = [];
        $slim = ArenaRound::readSlim($user->id, fn (array $p) => isset($p['analysis']) ? [
            'analysis' => ['players' => $p['analysis']['players'] ?? [], 'won' => $p['analysis']['won'] ?? false, 'dispels' => $p['analysis']['dispels'] ?? []],
            'metadata' => ['durationInSeconds' => $p['metadata']['durationInSeconds'] ?? 0],
        ] : null);
        foreach ($slim as [$r, $summary]) {
            $this->summaries[$r->id] = $summary;
        }
        $lobbies = (new \Illuminate\Database\Eloquent\Collection(array_column($slim, 0)))->groupBy('lobby_id');
        unset($slim);

        $names = $lobbies->flatten(1)->flatMap(fn (ArenaRound $r) => array_column($this->summary($r)['analysis']['players'], 'name'))->unique()->values()->all();
        // What each spec's dispels remove, from every stored round: one definition with Improve.
        $this->dispellable = $this->improvement->dispellable($lobbies->flatten(1)->map(fn (ArenaRound $r) => $this->summary($r)['analysis'])->all());
        $xp = [];
        foreach ($this->experience->cachedMany($names) as $name => $hit) {
            $hit ??= $remembered[$name] ?? null;
            if ($hit !== null) {
                $xp[$name] = $hit;
            }
        }

        [$notesByRound, $notesByGame] = $this->attachNotes($lobbies, $notes);

        $code = $this->codeFingerprint();
        // Games the app uploaded to the player's YouTube channel (2026-10-28): the card and the list say so.
        $videos = \App\Models\YoutubeVideo::where('user_id', $user->id)->pluck('video_id', 'lobby_id')->all();
        $games = [];
        $draw = [];
        foreach ($lobbies as $lobby => $rounds) {
            // By time: `sequence` is 1 on every round wow:sync stores, so it cannot order a lobby.
            $rounds = $rounds->sortBy(fn (ArenaRound $r) => (string) $r->played_at)->values();
            $roundNotes = $rounds->mapWithKeys(fn (ArenaRound $r) => [$r->id => $notesByRound[$r->id] ?? []])->all();
            $names = $rounds->flatMap(fn (ArenaRound $r) => array_column($this->summary($r)['analysis']['players'], 'name'))->unique()->sort()->values();
            $sig = md5(json_encode([
                $code,
                $rounds->map(fn (ArenaRound $r) => [$r->id, (string) $r->updated_at])->all(),
                $roundNotes,
                $notesByGame[$lobby] ?? [],
                $names->mapWithKeys(fn ($n) => [$n => $xp[$n] ?? null])->all(),
                $videos[$lobby] ?? null,
            ]));
            $shuffle = $rounds->count() > 1 || str_contains($rounds->first()->bracket, 'Shuffle');
            $first = $this->summary($rounds->first())['analysis'];
            $me = collect($first['players'])->firstWhere('logger', true);
            $won = $rounds->filter(fn (ArenaRound $r) => $this->summary($r)['analysis']['won'])->count();
            $entry = [
                'playedAt' => (string) $rounds->first()->played_at,
                'bracket' => $rounds->first()->bracket,
                'notes' => $rounds->sum(fn (ArenaRound $r) => count($notesByRound[$r->id] ?? [])) + count($notesByGame[$lobby] ?? []),
                'sig' => $sig,
                // For a list of games that cannot read the archive (the website's /wow/coach).
                'you' => $me ? explode('-', $me['name'])[0] : null,
                'record' => [$won, $rounds->count() - $won],
                'against' => $shuffle ? [] : collect($first['players'])->where('side', 'them')->pluck('spec')->sort()->values()->all(),
                'youtube' => isset($videos[$lobby]),
            ];

            $games[$lobby] = $entry + ['html' => null];
            if (($known[$lobby] ?? null) !== $sig) {
                $draw[$lobby] = [$rounds, $shuffle];
            }
        }

        // Newest first, and only until $deadline (2026-10-28): a build in the queue has 85s, and after
        // the card code changes a 470-game account needs about 100s to redraw them all. A card left
        // over keeps the signature it had, so the next build draws it, and a game just played is never
        // the one left over.
        $unfinished = 0;
        foreach (array_reverse($draw, true) as $lobby => [$rounds, $shuffle]) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $games[$lobby]['sig'] = $known[$lobby] ?? null;
                $unfinished++;

                continue;
            }
            $vm = $shuffle ? $this->shuffleModel($rounds, $xp, $notesByRound) : $this->gameModel($rounds->first(), $xp, $notesByRound);
            $vm['gameNotes'] = $this->gameNoteRows($notesByGame[$lobby] ?? []);
            // The website's cards (app and browser) open the game's timeline page; the local IE11
            // cards cannot draw it.
            $vm['watch'] = config('desktop.web_assets') ? "watch-{$lobby}.html" : null;
            $vm['youtube'] = $videos[$lobby] ?? null;

            $games[$lobby]['html'] = view('desktop.game-card', ['g' => $vm])->render();
            $this->payloads = [];
        }

        return ['games' => $games, 'experience' => $xp, 'unfinished' => $unfinished];
    }

    /** Drawing the website's pooled card (pooled()): no names, no player's own tabs. */
    private bool $pool = false;

    /**
     * One 3v3 game for the website's comp board (2026-10-27, Chriso: "have in leaderboard individual
     * matches so we can click on the match card"): the card the app draws, read from the side given,
     * with every name swapped for its spec at the source, the way CompLibraryService::buildPool()
     * draws a comp. What only the uploader's own card should carry stays out: Basics and Moments
     * (both about the uploader's own buttons), Checks, Worth a look, notes and the timeline.
     *
     * $flip reads it from the other team's side (CompLeaderboardService::flip()); that team's deaths
     * then have no answer sheet, which is only ever measured for the uploader's team. Null when the
     * round was never measured, or when a name is still on the drawn page: it is never shown then.
     *
     * @param  array{us: string, them: string}  $sides  each team's comp name, for the line under the title
     */
    public function pooled(ArenaRound $round, bool $flip, array $sides): ?string
    {
        $this->payloads = [];
        $stored = $this->payload($round);
        $a = $stored['analysis'] ?? null;
        if (! is_array($a) || empty($a['players'])) {
            return null;
        }
        $names = array_column($a['players'], 'name');
        $hits = $this->experience->cachedMany($names);
        if ($flip) {
            $a = CompLeaderboardService::flip($a);
            foreach ($a['deaths'] as $i => $d) {
                unset($a['deaths'][$i]['answers']);
            }
        }

        // Each player is their spec; "Your" or "Enemy" in front only where both teams have it.
        $count = array_count_values(array_column($a['players'], 'spec'));
        $labels = [];
        foreach ($a['players'] as $p) {
            $label = ($count[$p['spec']] ?? 0) > 1 ? ($p['side'] === 'us' ? 'Your ' : 'Enemy ').$p['spec'] : $p['spec'];
            for ($n = 2; in_array($label, $labels, true); $n++) {
                $label = preg_replace('/ \d+$/', '', $label).' '.$n;
            }
            $labels[$p['name']] = $label;
        }
        $rename = function ($v) use (&$rename, $labels) {
            if (is_string($v)) {
                return $labels[$v] ?? $v;
            }
            if (! is_array($v)) {
                return $v;
            }
            $out = [];
            foreach ($v as $k => $x) {
                $out[is_string($k) ? ($labels[$k] ?? $k) : $k] = $rename($x);
            }

            return $out;
        };
        $a = $rename($a);
        foreach ($a['players'] as $i => $p) {
            $a['players'][$i]['logger'] = false;
        }
        $xp = [];
        foreach ($labels as $name => $label) {
            if (($hits[$name] ?? null) !== null) {
                $xp[$label] = $hits[$name];
            }
        }

        $this->payloads[$round->id] = ['analysis' => $a, 'metadata' => $stored['metadata'] ?? []];
        $this->pool = true;
        try {
            $vm = $this->gameModel($round, $xp, []);
        } finally {
            $this->pool = false;
            $this->payloads = [];
        }
        $vm['gameNotes'] = [];
        $vm['watch'] = null;
        $vm['pool'] = $sides;
        $html = view('desktop.game-card', ['g' => $vm])->render();

        // The check anon.py makes for the sample pages: no player's name anywhere on the page.
        foreach ($names as $full) {
            $short = $this->short($full);
            if (mb_strlen($short) >= 3 && preg_match('/\b'.preg_quote($short, '/').'\b/u', $html)) {
                \Illuminate\Support\Facades\Log::warning('Pooled game card held a name; not shown', ['round' => $round->id]);

                return null;
            }
        }

        return $html;
    }

    /** @var array<int, array> round id => its decoded payload, for one build */
    private array $payloads = [];

    /**
     * A round's payload, decoded once. Eloquent's `array` cast decodes the JSON again on every read,
     * and a card reads it many times: with 14 MB stored over 212 rounds that was about a second of
     * every build, even one that drew nothing (2026-10-02).
     */
    private function payload(ArenaRound $r): array
    {
        if (! isset($this->payloads[$r->id])) {
            // A round from build() comes without its payload (ArenaRound::readSlim) and is read again here.
            $stored = array_key_exists('payload', $r->getAttributes()) ? $r->payload : ArenaRound::whereKey($r->id)->value('payload');
            // The parts a card reads; moments are unused here. Combatants (talents) are for a shuffle's
            // talent changes between rounds.
            $this->payloads[$r->id] = array_intersect_key($stored ?? [], ['analysis' => true, 'metadata' => true, 'combatants' => true]);
            if (isset($this->payloads[$r->id]['analysis'])) {
                // Every spell name in English (2026-10-30), the rule for every card.
                $this->payloads[$r->id]['analysis'] = app(SpellNameTranslations::class)->inEnglish($this->payloads[$r->id]['analysis']);
            }
        }

        return $this->payloads[$r->id];
    }

    /** @var array<int, array> round id => the parts of its payload build() lists every game by */
    private array $summaries = [];

    /** What build() read of a round for the list: its players, result, dispels and length. */
    private function summary(ArenaRound $r): array
    {
        return $this->summaries[$r->id] ?? $this->payload($r);
    }

    /**
     * Changes when the card's own code does: this class and every template under desktop/. A
     * change to the spell data behind an icon does not move it; a full redraw is `--fresh`.
     */
    public function codeFingerprint(): string
    {
        // GameBasicsService and the norms it reads draw the Basics tab, so they redraw it too.
        // The defensive tags sort each death's ready defensives (DefensiveFit), so a tag change redraws.
        // GameMomentsService draws the Moments tab.
        $files = array_merge([__FILE__, app_path('Http/Services/GameBasicsService.php'), app_path('Http/Services/GameMomentsService.php'), \App\Console\Commands\BuildPopulation::normsPath(),
            app_path('Support/DefensiveFit.php'), DefensiveFit::path(), app_path('Http/Services/KillTargetService.php')],
            // The Watch page is a desktop view but no card draws it (2026-10-26: every change to it
            // redrew every player's cards, and the largest account's build timed out after deploys).
            array_filter(glob(resource_path('views/desktop/*.blade.php')), fn ($f) => basename($f) !== 'watch.blade.php'),
            glob(resource_path('views/desktop/partials/*.blade.php')));
        $files = array_values(array_filter($files, 'file_exists'));
        sort($files);

        return md5(implode('|', array_map(fn ($f) => $f.':'.filemtime($f), $files)));
    }

    // ------------------------------------------------------------------ one game (2v2, 3v3)

    private function gameModel(ArenaRound $round, array $xp, array $notes): array
    {
        $a = $this->payload($round)['analysis'];
        $players = $this->players($a['players'], $xp, $a['lockout'] ?? []);
        $goes = collect($a['goes']);
        $kicks = collect($a['kicks']);
        $side = fn (string $s) => $goes->where('side', $s);
        $glad = fn (string $s) => $players->where('side', $s)->sum(fn ($p) => $p['xp']['glad'] ?? 0);

        return [
            'kind' => 'game',
            'bracket' => $round->bracket,
            'won' => $a['won'],
            'playedAt' => $round->played_at?->format('D j M, H:i'),
            'duration' => $this->clock((float) ($this->payload($round)['metadata']['durationInSeconds'] ?? 0)),
            'mmr' => $a['mmr'],
            'teams' => ['them' => $players->where('side', 'them')->values()->all(), 'us' => $players->where('side', 'us')->values()->all()],
            'glad' => ['them' => $glad('them'), 'us' => $glad('us')],
            'missingXp' => $players->where('xp.state', '!=', 'ok')->count(),
            'deaths' => array_map(fn ($d) => $this->death($d, $players, $a['goes'], $a['timeline'] ?? []), $a['deaths']),
            'difficulty' => $this->difficulty($a, $players),
            // [label, yours, theirs, whether more is better for the side that has it]
            'stats' => [
                ['Goes (bursts)', $side('us')->count(), $side('them')->count(), true],
                ['Tight goes (each link within 5s)', $side('us')->where('good', true)->count(), $side('them')->where('good', true)->count(), true],
                ['Goes that led to a kill', $side('us')->where('killLater', true)->count(), $side('them')->where('killLater', true)->count(), true],
                ['Defensives before the first death', $a['defensives']['us']['spent'], $a['defensives']['them']['spent'], null],
                ["Of those, outside the other side's goes", $a['defensives']['us']['outsideTheirGoes'], $a['defensives']['them']['outsideTheirGoes'], false],
                ['Interrupts', $kicks->where('side', 'us')->count(), $kicks->where('side', 'them')->count(), true],
                ['Interrupts on the healer', $kicks->where('side', 'us')->where('onHealer', true)->count(), $kicks->where('side', 'them')->where('onHealer', true)->count(), true],
            ],
            'look' => $a['won'] || $this->pool ? null : $this->look($this->analysis->lossItems($a, $xp), $players),
            'breakdown' => $this->breakdownModel(collect([$round]), $players),
            'checks' => $this->pool ? null : $this->checkRows($a['checks'] ?? null, $players),
            'notes' => $this->noteRows($notes[$round->id] ?? [], $round),
            'basics' => $this->pool ? null : $this->basicsModel([$a]),
            'moments' => $this->pool ? null : $this->momentsModel([[null, $a]]),
            'tiles' => $this->tiles($a, $players),
            'killTargets' => $this->killTargetsModel($a, $players),
            'ccBreaks' => $this->ccBreaksModel($a, $players),
            'events' => $this->events($a, $players, (float) ($this->payload($round)['metadata']['durationInSeconds'] ?? 0)),
        ];
    }

    /**
     * The Summary's Kill targets box: each team's targets by the damage they took, and its goes, with
     * names and icons (KillTargetService). Null on a pooled card, which has no "your team", and for a
     * round measured before version 18.
     */
    private function killTargetsModel(array $a, Collection $players): ?array
    {
        $read = $this->pool ? null : app(KillTargetService::class)->read($a);
        if ($read === null) {
            return null;
        }

        $byGuid = $players->keyBy('guid');
        $names = collect($read)->flatMap(fn ($side) => collect($side['goes'])->flatMap(fn ($go) => [...array_column($go['healerCc'], 'spell'), ...$go['cooldowns']]))
            ->unique()->values()->all();
        $icons = $names ? $this->icons->for($names) : [];
        $spell = fn (string $name) => ['spell' => $name, 'icon' => $this->spellIcon($icons, $name)];
        $who = fn (string $guid) => ['name' => $byGuid[$guid]['name'] ?? '?', 'spec' => $byGuid[$guid]['spec'] ?? '', 'color' => $byGuid[$guid]['color'] ?? '#B3B3B3'];

        return array_map(function (array $side) use ($spell, $who) {
            $most = max(array_column($side['targets'], 'damage') ?: [1]) ?: 1;

            return [
                'targets' => array_map(fn ($t) => $who($t['guid']) + [
                    'amount' => $this->amount($t['damage']),
                    'share' => round(100 * $t['damage'] / $most),
                    'died' => $t['died'] === null ? null : $this->clock($t['died']),
                ], $side['targets']),
                'goes' => array_map(fn ($go) => [
                    'clock' => $this->clock((float) $go['from']),
                    'who' => $who($go['on']),
                    'high' => (int) $go['high'],
                    'low' => (int) $go['low'],
                    'died' => $go['died'],
                    'healerCc' => array_map(fn ($c) => $spell($c['spell']) + ['seconds' => $c['seconds']], $go['healerCc']),
                    'cooldowns' => array_map($spell, $go['cooldowns']),
                ], $side['goes']),
                'allGoes' => $side['allGoes'],
            ];
        }, $read);
    }

    /**
     * The Summary's crowd control that something broke (2026-11-02, Chriso: every trap in a game went to
     * damage), by the team that cast it. One dot a landing, held or broken, then what broke each. It
     * lists the lockouts of a kind that broke at least once; it makes no call on whether the break was
     * a mistake, since breaking one on purpose is part of the game. Melee swings write no break to
     * the log, so a Polymorph ended by a swing reads as held. Null on a pooled card, for a round
     * measured before version 19, and when nothing broke.
     */
    private function ccBreaksModel(array $a, Collection $players): ?array
    {
        if ($this->pool || ($a['version'] ?? 0) < 19) {
            return null;
        }
        $byGuid = $players->keyBy('guid');
        $control = collect($a['timeline']['control'] ?? [])->filter(fn ($c) => in_array($c[3], RoundAnalysisService::LOCKOUT, true) && isset($byGuid[$c[1]]));
        if (! $control->contains(fn ($c) => isset($c[6]))) {
            return null;
        }
        $icons = $this->icons->for($control->flatMap(fn ($c) => [$c[2], $c[6][1] ?? null])->filter()->unique()->values()->all());
        $name = fn ($guid) => $byGuid[$guid]['name'] ?? '?';

        $side = fn (string $team) => $control->filter(fn ($c) => $byGuid[$c[1]]['side'] === $team)->groupBy(2)
            ->filter(fn ($g) => $g->contains(fn ($c) => isset($c[6])))
            ->sortByDesc(fn ($g) => $g->filter(fn ($c) => isset($c[6]))->count())
            ->map(fn ($g, $spell) => [
                'spell' => $spell,
                'icon' => $this->spellIcon($icons, $spell),
                'landed' => $g->count(),
                'broken' => $g->filter(fn ($c) => isset($c[6]))->count(),
                'dots' => $g->map(fn ($c) => isset($c[6]))->values()->all(),
                'by' => $g->filter(fn ($c) => isset($c[6]))->sortBy(4)->map(fn ($c) => [
                    'spell' => $c[6][1],
                    'icon' => $this->spellIcon($icons, $c[6][1]),
                    'held' => round($c[5] - $c[4], 1),
                    'tip' => $c[6][1].' from '.$name($c[6][0]).' broke it on '.$name($c[0]).' at '.$this->clock($c[5]).', after '.round($c[5] - $c[4], 1).'s',
                ])->values()->all(),
            ])->values()->all();

        return ['us' => $side('us'), 'them' => $side('them')];
    }

    /**
     * The Summary's numbers at a glance (2026-10-29, the dashboard layout): damage, healing, goes,
     * each healer's time crowd-controlled, defensives before the first death and interrupts, yours
     * against theirs. Every one is a figure the Numbers and Damage tabs already show in full.
     *
     * @return list<array{icon: string, label: string, value: string, sub: ?string}>
     */
    private function tiles(array $a, Collection $players): array
    {
        $side = $players->keyBy('guid')->map(fn ($p) => $p['side']);
        $sum = function (string $kinds, string $s) use ($a, $side) {
            $n = 0;
            foreach ($a['breakdown'] ?? [] as $guid => $b) {
                if (($side[$guid] ?? null) === $s) {
                    foreach (explode(',', $kinds) as $kind) {
                        $n += array_sum(array_column($b[$kind] ?? [], 'amount'));
                    }
                }
            }

            return $n;
        };
        $goes = collect($a['goes']);
        $kicks = collect($a['kicks']);
        $healer = fn (string $s) => $players->first(fn ($p) => $p['side'] === $s && $p['healer']);
        $you = $players->firstWhere('you', true);
        $tiles = [];

        if (! empty($a['breakdown'])) {
            $mine = $you ? array_sum(array_column($a['breakdown'][$you['guid']]['damage'] ?? [], 'amount')) : 0;
            $ours = $sum('damage', 'us');
            $tiles[] = ['icon' => 'flame', 'label' => 'Damage', 'value' => $this->amount($ours),
                'sub' => 'enemy '.$this->amount($sum('damage', 'them')).($you && $ours > 0 ? ', yours '.round(100 * $mine / $ours).'%' : '')];
            $tiles[] = ['icon' => 'heart', 'label' => 'Healing and absorbs', 'value' => $this->amount($sum('healing,absorbs', 'us')),
                'sub' => 'enemy '.$this->amount($sum('healing,absorbs', 'them'))];
        }
        $killed = $goes->where('side', 'us')->filter(fn ($g) => $g['kill'] || $g['killLater'])->count();
        $tiles[] = ['icon' => 'swords', 'label' => 'Goes, yours / enemy', 'value' => $goes->where('side', 'us')->count().' / '.$goes->where('side', 'them')->count(),
            'sub' => $killed.' of yours led to a kill'];
        if ($healer('us') || $healer('them')) {
            $locked = fn ($p) => $p ? round((float) ($a['lockout'][$p['guid']] ?? 0), 1).'s' : 'no healer';
            $tiles[] = ['icon' => 'lock', 'label' => 'Your healer crowd-controlled', 'value' => $locked($healer('us')), 'sub' => 'enemy healer '.$locked($healer('them'))];
        }
        $tiles[] = ['icon' => 'shield', 'label' => 'Defensives before the first death', 'value' => ($a['defensives']['us']['spent'] ?? 0).' / '.($a['defensives']['them']['spent'] ?? 0),
            'sub' => 'yours / enemy'];
        $tiles[] = ['icon' => 'ban', 'label' => 'Interrupts, yours / enemy', 'value' => $kicks->where('side', 'us')->count().' / '.$kicks->where('side', 'them')->count(),
            'sub' => $kicks->where('side', 'us')->where('onHealer', true)->count().' of yours on their healer'];

        return $tiles;
    }

    /**
     * The game on one line of time (the Summary's key events): each go and whom it went on, each
     * Medallion it forced, each death and the end, all from what RoundAnalysisService stored.
     *
     * @return list<array{t: float, clock: string, kind: string, ours: bool, text: string, icon: ?string}>
     */
    private function events(array $a, Collection $players, float $length): array
    {
        $byGuid = $players->keyBy('guid');
        $name = fn (?string $guid) => $byGuid[$guid]['name'] ?? 'someone';
        $icons = $this->icons->for(array_values(array_filter(array_merge(['Gladiator\'s Medallion'], array_map(fn ($d) => $d['killingBlow']['spell'] ?? null, $a['deaths'])))));
        $events = [];
        $medallions = [];
        foreach ($a['goes'] as $go) {
            $ours = $go['side'] === 'us';
            $events[] = ['t' => (float) $go['from'], 'kind' => ($go['kill'] ?? null) ? 'kill' : 'go', 'ours' => $ours, 'icon' => null,
                'text' => ($ours ? 'Your team goes' : 'Enemy go').(isset($byGuid[$go['target'] ?? '']) ? ' on '.$name($go['target']) : '')
                    .(($go['healerCc'] ?? 0) > 0 ? ', '.($ours ? 'their' : 'your').' healer crowd-controlled' : '')];
            foreach ($go['forced'] ?? [] as $f) {
                $t = round((float) $go['from'] + (float) $f['t']);
                if (str_contains($f['spell'], 'Medallion') && ! isset($medallions[$f['who'].'@'.$t])) {
                    $medallions[$f['who'].'@'.$t] = true;
                    $events[] = ['t' => (float) $t, 'kind' => 'medallion', 'ours' => ! $ours, 'icon' => $this->spellIcon($icons, 'Gladiator\'s Medallion'),
                        'text' => $name($f['who']).' uses Medallion'];
                }
            }
        }
        foreach ($a['deaths'] as $d) {
            $kb = $d['killingBlow']['spell'] ?? null;
            $events[] = ['t' => (float) $d['t'], 'kind' => 'death', 'ours' => $d['side'] === 'us', 'icon' => $kb ? $this->spellIcon($icons, $kb) : null,
                'text' => $name($d['who']).' dies'.($kb ? ' to '.$kb : '')];
        }
        if ($length > 0) {
            $events[] = ['t' => $length, 'kind' => 'end', 'ours' => (bool) $a['won'], 'icon' => null, 'text' => $a['won'] ? 'Your team wins' : 'Your team loses'];
        }
        usort($events, fn ($x, $y) => $x['t'] <=> $y['t']);

        return array_map(fn ($e) => $e + ['clock' => $this->clock($e['t'])], $events);
    }

    /**
     * The Moments tab: each round's moments (GameMomentsService), with icons. `$rounds` is a list of
     * [label or null, analysis]; a shuffle labels each moment with its round.
     */
    private function momentsModel(array $rounds): ?array
    {
        $all = [];
        $measured = false;
        foreach ($rounds as [$label, $a]) {
            $measured = $measured || (($a['version'] ?? 0) >= 16);
            foreach ($this->moments->forRound($a, $this->dispellable) as $m) {
                $all[] = $m + ['round' => $label];
            }
        }
        if (! $measured) {
            return null;
        }
        $names = collect($all)->flatMap(fn ($m) => array_merge($m['spells'], array_column($m['steps'], 'spell'), $m['during']['casts'] ?? []))->unique()->values()->all();
        $icons = $this->icons->for($names);

        // array_merge, not +: the steps are replaced by the same steps with their icons.
        return array_map(fn ($m) => array_merge($m, [
            'icons' => array_values(array_filter(array_map(fn ($n) => $this->spellIcon($icons, $n), array_slice($m['spells'], 0, 4)))),
            'steps' => array_map(fn ($s) => $s + ['icon' => $this->spellIcon($icons, $s['spell'])], $m['steps']),
        ]), $all);
    }

    // ------------------------------------------------------------------ a Solo Shuffle lobby

    private function shuffleModel(Collection $rounds, array $xp, array $notes): array
    {
        $first = $rounds->first();
        $won = $rounds->filter(fn (ArenaRound $r) => $this->payload($r)['analysis']['won'])->count();

        // Everyone in the lobby once; sides are re-dealt each round, so the roster has no teams.
        $all = $rounds->flatMap(fn (ArenaRound $r) => $this->payload($r)['analysis']['players'])->unique('guid')->values()->all();
        $roster = $this->players($all, $xp, [])->sortByDesc('you')->values();

        $talents = $this->talentChanges($rounds);
        $rows = [];
        foreach ($rounds as $i => $round) {
            $a = $this->payload($round)['analysis'];
            $ps = $this->players($a['players'], $xp, $a['lockout'] ?? []);
            $you = $ps->firstWhere('you', true);

            // Your own buttons and the team's timing: a shuffle's other players change every round,
            // so their mistakes say little about how you play.
            $look = null;
            if (! $a['won']) {
                $mine = array_filter($this->analysis->lossItems($a, $xp), fn ($it) => $it['owner'] === ($you['spec'] ?? null) || $it['owner'] === 'Your team (burst timing)');
                $look = $this->look(array_values($mine), $ps);
            }

            $rows[] = [
                'n' => $i + 1,
                'won' => $a['won'],
                'duration' => $this->clock((float) ($this->payload($round)['metadata']['durationInSeconds'] ?? 0)),
                'with' => $ps->where('side', 'us')->where('you', false)->values()->all(),
                'against' => $ps->where('side', 'them')->values()->all(),
                'death' => isset($a['deaths'][0]) ? $this->death($a['deaths'][0], $ps, $a['goes'], $a['timeline'] ?? []) : null,
                'killTargets' => $this->killTargetsModel($a, $ps),
                'ccBreaks' => $this->ccBreaksModel($a, $ps),
                'talents' => array_map(fn ($c) => $c + ['color' => $ps->firstWhere('guid', $c['guid'])['color'] ?? '#B3B3B3'], $talents[$i] ?? []),
                'look' => $look,
                // Your own only, like $look: the other players change every round.
                'checks' => $this->checkRows(isset($a['checks']) ? array_values(array_filter($a['checks'], fn ($c) => $c['who'] === ($you['guid'] ?? null))) : null, $ps),
                'notes' => $this->noteRows($notes[$round->id] ?? [], $round),
            ];
        }

        return [
            'kind' => 'shuffle',
            'bracket' => $first->bracket,
            'won' => $won > $rounds->count() - $won,
            'record' => [$won, $rounds->count() - $won],
            'playedAt' => $first->played_at?->format('D j M, H:i'),
            'roster' => $roster->all(),
            'rounds' => $rows,
            // The same six players all lobby, so their output adds up across the rounds.
            'breakdown' => $this->breakdownModel($rounds, $roster, teams: false),
            'byRound' => $this->byRoundModel($rounds, $roster),
            'basics' => $this->basicsModel($rounds->map(fn (ArenaRound $r) => $this->payload($r)['analysis'])->all()),
            'moments' => $this->momentsModel($rounds->values()->map(fn (ArenaRound $r, $i) => ['Round '.($i + 1), $this->payload($r)['analysis']])->all()),
        ];
    }

    /**
     * Each player's damage and healing (absorbs in it) round by round (2026-10-28, Chriso: "in the
     * solo shuffle can we do damage and healing by rounds"), from the same breakdown as the lobby's
     * totals under it, with the rounds each player was on your team marked.
     */
    private function byRoundModel(Collection $rounds, Collection $roster): ?array
    {
        $cells = [];
        $cols = [];
        foreach ($rounds->values() as $i => $round) {
            $a = $this->payload($round)['analysis'];
            $cols[] = ['n' => $i + 1, 'won' => $a['won']];
            $side = array_column($a['players'], 'side', 'guid');
            foreach ($a['breakdown'] ?? [] as $guid => $b) {
                $sum = fn (string $kind) => array_sum(array_column($b[$kind] ?? [], 'amount'));
                $cells[$guid][$i] = [
                    'damage' => $this->amount($sum('damage')),
                    'healing' => $this->amount($sum('healing') + $sum('absorbs')),
                    'with' => ($side[$guid] ?? null) === 'us',
                ];
            }
        }
        if ($cells === []) {
            return null;
        }

        $rows = [];
        foreach ($roster as $p) {
            if (isset($cells[$p['guid']])) {
                $rows[] = [
                    'name' => $p['name'], 'color' => $p['color'], 'you' => $p['you'], 'healer' => $p['healer'],
                    'cells' => array_map(fn ($i) => $cells[$p['guid']][$i] ?? null, array_keys($cols)),
                ];
            }
        }

        return ['rounds' => $cols, 'players' => $rows];
    }

    /**
     * What each player changed in their talents since their last round of the lobby: a shuffle allows
     * a change between rounds. Read from the talents the round stored (`combatants`), named the way
     * the cooldown ledger names a build. Round index => [guid, name, took, dropped].
     *
     * @return array<int, list<array{guid: string, name: string, took: list<string>, dropped: list<string>}>>
     */
    private function talentChanges(Collection $rounds): array
    {
        $out = [];
        $last = [];     // guid => the talents of their last round, as stored
        foreach ($rounds->values() as $i => $round) {
            $p = $this->payload($round);
            foreach ($p['analysis']['players'] as $pl) {
                $now = $p['combatants'][$pl['guid']] ?? null;
                if (! $now) {
                    continue;
                }
                $before = $last[$pl['guid']] ?? null;
                $last[$pl['guid']] = $now;
                $raw = fn ($c) => json_encode([$c['talents'] ?? [], $c['pvpTalentIds'] ?? []]);
                if ($before === null || $raw($before) === $raw($now)) {
                    continue;
                }
                $spec = $this->arena->specForExternalId((string) ($pl['specExternalId'] ?? ''));
                if (! $spec) {
                    continue;
                }
                $was = $this->ledger->build($before, $spec)['names'] ?? [];
                $is = $this->ledger->build($now, $spec)['names'] ?? [];
                $took = array_values(array_diff($is, $was));
                $dropped = array_values(array_diff($was, $is));
                if ($took || $dropped) {
                    $out[$i][] = ['guid' => $pl['guid'], 'name' => ($pl['logger'] ?? false) ? 'You' : $this->short($pl['name']), 'took' => $took, 'dropped' => $dropped];
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ the basics (version 10)

    /**
     * The Basics tab (GameBasicsService): your team's goes, your crowd control and casts, your output
     * against every player of your spec, and positioning and macros from your failed casts. Null for
     * a game measured before version 10, which the tab says how to fix.
     */
    private function basicsModel(array $analyses): ?array
    {
        $b = $this->basics->forRounds($analyses);
        if (! $b) {
            return null;
        }

        $names = collect($b['sections'])->flatMap(fn ($s) => collect($s['rows'])->flatMap(fn ($r) => array_merge($r['spells'] ?? [], isset($r['macro']) ? [$r['macro']] : [])))->unique()->values()->all();
        $icons = $names ? $this->icons->for($names) : [];
        foreach ($b['sections'] as &$section) {
            foreach ($section['rows'] as &$row) {
                $row['icons'] = array_values(array_filter(array_map(fn ($n) => $this->spellIcon($icons, $n), $row['spells'] ?? [])));
                if (isset($row['macro'])) {
                    $row['macroIcon'] = $this->spellIcon($icons, $row['macro']);
                }
            }
            unset($row);
        }
        unset($section);

        return $b;
    }

    // ------------------------------------------------------------------ damage, healing, checks

    /**
     * Each player's damage onto enemy players, healing and absorbs onto their own team, by ability,
     * summed over the rounds given (RoundAnalysisService `breakdown`, version 5). Null when none of
     * the rounds was analysed with it, so an older game says how to get it rather than showing zeros.
     */
    private function breakdownModel(Collection $rounds, Collection $players, bool $teams = true): ?array
    {
        $with = $rounds->filter(fn (ArenaRound $r) => isset($this->payload($r)['analysis']['breakdown']));
        if ($with->isEmpty()) {
            return null;
        }

        $sum = [];
        foreach ($with as $r) {
            foreach ($this->payload($r)['analysis']['breakdown'] as $guid => $b) {
                $sum[$guid]['alive'] = ($sum[$guid]['alive'] ?? 0) + $b['alive'];
                $sum[$guid]['idle'] = ($sum[$guid]['idle'] ?? 0) + $b['idle'];
                foreach (['damage', 'healing', 'absorbs'] as $kind) {
                    foreach ($b[$kind] as $row) {
                        $cur = $sum[$guid][$kind][$row['spell']] ?? ['amount' => 0, 'hits' => 0, 'over' => 0];
                        $sum[$guid][$kind][$row['spell']] = ['amount' => $cur['amount'] + $row['amount'], 'hits' => $cur['hits'] + $row['hits'], 'over' => $cur['over'] + $row['over']];
                    }
                }
            }
        }

        $names = [];
        foreach ($sum as $b) {
            foreach (['damage', 'healing', 'absorbs'] as $kind) {
                $names = array_merge($names, array_map(fn ($s) => preg_replace('/^pet: /', '', $s), array_keys($b[$kind] ?? [])));
            }
        }
        $icons = $this->icons->for(array_values(array_unique($names)));

        // Your team first (you at the top), then theirs. A shuffle lobby has no fixed sides: you, then
        // everyone else.
        $ordered = $players->sortBy(fn ($p) => [$teams && $p['side'] !== 'us' ? 1 : 0, $p['you'] ? 0 : 1, $p['healer'] ? 1 : 0])->values();

        $rows = [];
        foreach ($ordered as $p) {
            $b = $sum[$p['guid']] ?? null;
            if ($b === null) {
                continue;
            }
            $kinds = [];
            foreach (['damage' => 'Damage', 'healing' => 'Healing', 'absorbs' => 'Absorbs'] as $kind => $label) {
                // "Other" (everything past a round's top abilities) always last.
                $list = collect($b[$kind] ?? [])->map(fn ($v, $spell) => ['spell' => $spell] + $v)
                    ->sortBy(fn ($x) => [$x['spell'] === 'Other' ? 1 : 0, -$x['amount']])->values();
                $total = $list->sum('amount');
                if ($total <= 0) {
                    continue;
                }
                $kinds[] = [
                    'label' => $label,
                    'total' => $this->amount($total),
                    'perSecond' => $this->amount($total / max(1, $b['alive'])),
                    'rows' => $list->map(fn ($x) => [
                        'spell' => $x['spell'],
                        'icon' => $this->spellIcon($icons, preg_replace('/^pet: /', '', $x['spell'])),
                        'amount' => $this->amount($x['amount']),
                        'share' => round(100 * $x['amount'] / $total),
                        'hits' => $x['hits'],
                        'overheal' => $kind === 'healing' && $x['amount'] + $x['over'] > 0 ? round(100 * $x['over'] / ($x['amount'] + $x['over'])) : null,
                    ])->all(),
                ];
            }
            $totals = collect($kinds)->keyBy('label');
            $rows[] = [
                'id' => 'b'.substr(md5($p['guid']), 0, 8),
                'name' => $p['name'], 'spec' => $p['spec'], 'color' => $p['color'], 'icon' => $p['icon'],
                'you' => $p['you'], 'them' => $teams && $p['side'] === 'them',
                'damage' => $totals['Damage']['total'] ?? '0',
                'healing' => $totals['Healing']['total'] ?? '0',
                'absorbs' => $totals['Absorbs']['total'] ?? '0',
                'idle' => round(100 * $b['idle'] / max(1, $b['alive'])),
                'kinds' => $kinds,
            ];
        }

        return ['players' => $rows, 'partial' => $with->count() < $rounds->count(),
            // Counted as WoW's meter counts damage (RoundAnalysisService version 17) in every round shown.
            'meter' => $with->every(fn (ArenaRound $r) => ($this->payload($r)['analysis']['version'] ?? 0) >= 17)];
    }

    /**
     * RoundAnalysisService `checks`, worded. Null when the round was analysed before checks existed.
     *
     * @param  array<int, array>|null  $checks
     */
    private function checkRows(?array $checks, Collection $players): ?array
    {
        if ($checks === null) {
            return null;
        }
        $byGuid = $players->keyBy('guid');
        $icons = $this->icons->for(array_column($checks, 'spell'));

        return array_map(function ($c) use ($byGuid, $icons) {
            $who = $byGuid[$c['who']] ?? null;
            $text = match ($c['kind']) {
                'cooldown-ready' => sprintf('sat ready for %s in all: at least %d whole use%s lost (pressed %d time%s, %ds cooldown)',
                    $this->clock($c['ready']), $c['lost'], $c['lost'] === 1 ? '' : 's', $c['presses'], $c['presses'] === 1 ? '' : 's', $c['cooldown']),
                'on-immune' => sprintf('on %s at %s, who was already in %s: it removed nothing',
                    $byGuid[$c['on']]['name'] ?? '?', $this->clock($c['t']), $c['immunity']),
                default => '',
            };

            return [
                'name' => $who['name'] ?? '?', 'color' => $who['color'] ?? '#B3B3B3', 'you' => $who['you'] ?? false,
                'spell' => $c['spell'], 'icon' => $this->spellIcon($icons, $c['spell']), 'text' => $text,
            ];
        }, $checks);
    }

    private function amount(float $n): string
    {
        return match (true) {
            $n >= 1_000_000 => number_format($n / 1_000_000, 2).'M',
            $n >= 1_000 => number_format($n / 1_000).'k',
            default => (string) (int) round($n),
        };
    }

    // ------------------------------------------------------------------ pieces

    /** @return Collection<int, array> */
    private function players(array $players, array $xp, array $lockout): Collection
    {
        $specs = Specialization::query()->whereIn('external_spec_id', array_column($players, 'specExternalId'))->get()->keyBy('external_spec_id');
        $colors = config('wow_classes.colors');

        return collect($players)->map(function ($p) use ($specs, $colors, $xp, $lockout) {
            $x = $xp[$p['name']] ?? null;
            $spec = $specs[$p['specExternalId'] ?? 0] ?? null;

            return [
                'guid' => $p['guid'],
                'name' => $this->short($p['name']),
                'full' => $p['name'],
                // Their public page on the site (/wow/player/...), absolute: the app shows a card as a
                // page of its own and opens any other link in the browser.
                'url' => $this->pool ? null : \App\Support\PlayerUrl::fromLogName($p['name']),
                'spec' => $p['spec'],
                'side' => $p['side'],
                'healer' => $p['healer'],
                'you' => $p['logger'],
                'color' => $colors[$p['classSlug'] ?? ''] ?? '#B3B3B3',
                'icon' => $spec?->icon_name ? $this->fileUrl('spec-icons/'.$spec->icon_name) : null,
                'lockout' => $lockout[$p['guid']] ?? null,
                'xp' => match (true) {
                    $x === null => ['state' => 'pending'],
                    ! ($x['found'] ?? false) => ['state' => 'none'],
                    default => ['state' => 'ok', 'glad' => $x['gladSeasons'] ?? 0, 'exp' => $x['exp3v3'] ?? null, 'title' => $x['bestRank'] ?? null],
                },
            ];
        });
    }

    /**
     * How hard the game was before anyone pressed anything: the MMR gap and the Gladiator seasons on
     * each side. A loss to a team 186 MMR above you with five Gladiator seasons to your one reads
     * differently from a loss at even odds, and the card should say so beside the result.
     *
     * @return array{label: string, tone: string, text: string}|null
     */
    private function difficulty(array $a, Collection $players): ?array
    {
        return self::difficultyOf((int) (($a['mmr']['them'] ?? 0) - ($a['mmr']['us'] ?? 0)), isset($a['mmr']['us'], $a['mmr']['them']),
            (int) $players->where('side', 'them')->sum(fn ($p) => $p['xp']['glad'] ?? 0), (int) $players->where('side', 'us')->sum(fn ($p) => $p['xp']['glad'] ?? 0));
    }

    /**
     * Harder: their MMR 50+ above yours, or 3+ more Gladiator seasons (the loss rules' own
     * thresholds). Easier: the same the other way. Both, or neither, is even.
     *
     * @return array{label: string, tone: string, text: string}
     */
    public static function difficultyOf(int $mmrGap, bool $haveMmr, int $theirGlad, int $ourGlad): array
    {
        $harder = ($haveMmr && $mmrGap >= 50) || $theirGlad - $ourGlad >= 3;
        $easier = ($haveMmr && $mmrGap <= -50) || $ourGlad - $theirGlad >= 3;
        $parts = [];
        if ($haveMmr && abs($mmrGap) >= 10) {
            $parts[] = sprintf('enemy MMR %s%d', $mmrGap > 0 ? '+' : '-', abs($mmrGap));
        }
        $parts[] = sprintf('%d Gladiator season%s vs your %d', $theirGlad, $theirGlad === 1 ? '' : 's', $ourGlad);

        return match (true) {
            $harder && ! $easier => ['label' => 'Harder', 'tone' => 'bad', 'text' => ucfirst(implode(', ', $parts))],
            $easier && ! $harder => ['label' => 'Easier', 'tone' => 'good', 'text' => ucfirst(implode(', ', $parts))],
            default => ['label' => 'Even', 'tone' => 'neutral', 'text' => ucfirst(implode(', ', $parts))],
        };
    }

    private function death(array $d, Collection $players, array $goes = [], array $timeline = []): array
    {
        $p = $players->firstWhere('guid', $d['who']);
        $byName = $players->keyBy('full');
        $h = $d['healer'];
        $hasHealer = $players->where('side', $d['side'])->contains('healer', true);

        $healer = null;
        if ($hasHealer && $h['state'] !== 'was the kill') {
            $healer = match ($h['state']) {
                'locked' => ['label' => 'locked out at the death', 'tone' => 'bad'],
                'ended' => ['label' => 'lockout ended '.$h['endedAgo'].'s before', 'tone' => $h['endedAgo'] <= 1 ? 'bad' : 'warn'],
                default => ['label' => 'free', 'tone' => 'good'],
            };
            $healer['whose'] = $d['side'] === 'us' ? 'Your healer' : 'Enemy healer';
            $healer['medallion'] = $h['medallionUsedAt'] ? 'Medallion used at '.collect($h['medallionUsedAt'])->map(fn ($t) => $this->clock($t))->implode(', ') : 'Medallion not used';
        }

        $spells = array_filter(array_merge([$d['killingBlow']['spell'] ?? null], array_column($d['defensives30s'], 'spell'), array_column($d['abilities'] ?? [], 'spell')));
        $icons = $this->icons->for($spells);

        return [
            'clock' => $this->clock($d['t']),
            'name' => $p['name'] ?? '?',
            'spec' => $p['spec'] ?? '?',
            'color' => $p['color'] ?? '#B3B3B3',
            'icon' => $p['icon'] ?? null,
            'ours' => $d['side'] === 'us',
            'killingBlow' => $d['killingBlow'] ? ['spell' => $d['killingBlow']['spell'], 'icon' => $this->spellIcon($icons, $d['killingBlow']['spell'])] : null,
            'shares' => collect($d['shares'])->map(fn ($pct, $name) => [
                'name' => isset($byName[$name]) ? $byName[$name]['name'] : $this->short($name),
                'color' => $byName[$name]['color'] ?? '#6A6A6A',
                'pct' => $pct,
            ])->values()->all(),
            // What did the damage (version 13); empty for a round measured before it.
            'abilities' => array_map(fn ($a) => [
                'spell' => $a['spell'], 'icon' => $this->spellIcon($icons, $a['spell']), 'pct' => $a['pct'],
                'name' => isset($byName[$a['name']]) ? $byName[$a['name']]['name'] : $this->short($a['name']),
                'color' => $byName[$a['name']]['color'] ?? '#6A6A6A',
            ], array_values(array_filter($d['abilities'] ?? [], fn ($a) => $a['pct'] >= 3))),
            'healer' => $healer,
            // The Summary's one line about the first death still says it in words.
            'goStartedAgo' => $d['goStartedAgo'],
            'goWhose' => $d['side'] === 'us' ? 'Enemy' : 'Your',
            'answers' => isset($d['answers']) ? $this->answers($d, $players, $goes) : null,
            'chart' => $this->deathChart($d, $players, $goes, $timeline),
            'hitRows' => $this->hitRows($d, $players, $timeline),
        ];
    }

    /**
     * One death as a picture (2026-11-02, Chriso: the text said this more slowly, and PvP Guides'
     * Empowered box shows how). Every lane shares one clock, from the go's start to the death: the
     * attackers' cooldowns, crowd control on each of the dying side, that side's defensives, and
     * their health with each hit drawn on it as the drop it caused. Read when the page is drawn from
     * the stored timeline; `deaths[].hits` is version 19, and a round measured before it keeps the
     * health a second at a time. Null with no health to draw.
     */
    private function deathChart(array $d, Collection $players, array $goes, array $tl): ?array
    {
        $n = count($d['hp'] ?? []) - 1;
        if ($n < 2) {
            return null;
        }
        $byGuid = $players->keyBy('guid');
        $byName = $players->keyBy('full');
        $killer = $d['side'] === 'us' ? 'them' : 'us';
        $end = (float) $d['t'];
        $start = $end - $n;
        $x = fn (float $t) => round(100 * (max($start, min($end, $t)) - $start) / $n, 2);
        $sideOf = fn ($guid) => $byGuid[$guid]['side'] ?? null;
        $name = fn ($guid) => $byGuid[$guid]['name'] ?? '?';
        $color = fn ($guid) => $byGuid[$guid]['color'] ?? '#6A6A6A';
        $inside = fn (float $from, float $to) => $to > $start && $from < $end;
        // Lockouts only: a slow or a root still leaves the player their buttons.
        $control = collect($tl['control'] ?? [])->filter(fn ($c) => $sideOf($c[0]) === $d['side'] && $inside($c[4], $c[5]) && in_array($c[3], RoundAnalysisService::LOCKOUT, true));

        // Spans, then the presses with no span of their own: a cooldown that is no buff, or a defensive
        // on a shorter tag. Each is drawn from its press, so what was up reads the same in both.
        $cooldowns = collect($tl['offUp'] ?? [])->filter(fn ($s) => $sideOf($s[0]) === $killer && $inside($s[2], $s[3]))
            ->map(fn ($s) => ['spell' => $s[1], 'who' => $s[0], 'from' => $s[2], 'to' => $s[3]])->values()->all();
        foreach ($goes as $g) {
            foreach (($g['side'] ?? null) === $killer ? ($g['links'] ?? []) : [] as $l) {
                $t = $g['from'] + $l['t'];
                $tagged = in_array($l['cat'] ?? null, ['offensive', 'mixed'], true);
                if ($tagged && $t >= $start && $t <= $end && ! collect($cooldowns)->contains(fn ($c) => $c['spell'] === $l['spell'] && $t >= $c['from'] - 2 && $t <= $c['to'])) {
                    $cooldowns[] = ['spell' => $l['spell'], 'who' => $l['by'], 'from' => $t, 'to' => $t];
                }
            }
        }
        $defensives = collect($tl['defUp'] ?? [])->filter(fn ($s) => $sideOf($s[0]) === $d['side'] && $inside($s[3], $s[4]))
            ->map(fn ($s) => ['spell' => $s[2], 'who' => $s[1], 'from' => $s[3], 'to' => $s[4]])->values()->all();
        foreach ($d['defensives30s'] as $c) {
            $t = $end - $c['ago'];
            $guid = $players->firstWhere('full', $c['who'])['guid'] ?? null;
            if ($t >= $start && ! collect($defensives)->contains(fn ($s) => $s['spell'] === $c['spell'] && $t >= $s['from'] - 2 && $t <= $s['to'])) {
                $defensives[] = ['spell' => $c['spell'], 'who' => $guid, 'from' => $t, 'to' => $t];
            }
        }

        $hits = $d['hits'] ?? [];
        $icons = $this->icons->for(array_values(array_unique(array_merge(
            array_column($cooldowns, 'spell'), array_column($defensives, 'spell'), $control->pluck(2)->all(), array_column($hits, 'spell')))));

        $bar = fn (string $spell, ?string $by, float $from, float $to, string $tip) => [
            'left' => $x($from), 'width' => round($x($to) - $x($from), 2), 'color' => $color($by),
            'spell' => $spell, 'icon' => $this->spellIcon($icons, $spell), 'tip' => $tip,
        ];
        $span = fn (array $s) => $s['to'] > $s['from'] ? round($s['to'] - $s['from'], 1).'s' : 'pressed';
        // Bars that overlap in time take a row each, so neither hides the other.
        $stack = function (array $spans) use ($bar, $name, $n, $span) {
            usort($spans, fn ($a, $b) => $a['from'] <=> $b['from']);
            $rows = [];
            $ends = [];
            foreach ($spans as $s) {
                $at = 0;
                while (isset($ends[$at]) && $ends[$at] > $s['from']) {
                    $at++;
                }
                // Past its end by what an icon takes up, or two presses in a row would share one spot.
                $ends[$at] = max($s['to'], $s['from']) + $n * 0.05;
                $rows[$at][] = $bar($s['spell'], $s['who'], $s['from'], $s['to'], $s['spell'].', '.$name($s['who']).', '.$span($s));
            }

            return $rows;
        };

        $people = $players->where('side', $d['side'])->sortBy(fn ($p) => $p['healer'] ? 0 : ($p['guid'] === $d['who'] ? 1 : 2))
            ->map(fn ($p) => [
                'name' => $p['name'], 'color' => $p['color'], 'healer' => $p['healer'],
                'bars' => $control->where(0, $p['guid'])->sortBy(4)->map(fn ($c) => $bar($c[2], $c[1], $c[4], $c[5],
                    $c[2].' on '.$p['name'].' from '.$name($c[1]).', '.round($c[5] - $c[4], 1).'s'
                    .(isset($c[6]) ? ', broken by '.$name($c[6][0]).': '.$c[6][1] : '')) + ['broke' => isset($c[6])])->values()->all(),
            ])
            ->filter(fn ($p) => $p['bars'] || $p['healer'])->values()->all();

        // Health moves a hit at a time. The hits get a lane of their own, scaled to the biggest: most
        // are 1% of a health bar (median 4,000 of 1.07M on the game this was built from), so drawn
        // against health they could not be seen, and a crit could not be told from anything else.
        $now = (float) $d['hp'][0];
        $line = ['0,'.round(100 - $now, 1)];
        $bars = [];
        $peak = max(1, max(array_map(fn ($h) => $h['amount'] + $h['shield'], $hits) ?: [1]));
        foreach ($hits as $h) {
            $t = $end - $h['ago'];
            $after = $h['hp'] === null ? $now : (float) $h['hp'];
            $by = $byName[$h['by']] ?? null;
            $left = $x($t);
            $line[] = round($left * 10, 1).','.round(100 - $now, 1);
            $line[] = round($left * 10, 1).','.round(100 - $after, 1);
            $now = $after;
            $with = collect($tl['offUp'] ?? [])->filter(fn ($s) => $by && $s[0] === $by['guid'] && $t >= $s[2] && $t <= $s[3])->pluck(1)->unique()->implode(', ');
            $weight = $h['amount'] + $h['shield'];
            $bars[] = [
                'left' => $left, 'height' => round(100 * $weight / $peak, 1),
                // How much of the bar reached health; the rest a shield took.
                'solid' => (int) round(100 * $h['amount'] / max(1, $weight)),
                'color' => $by['color'] ?? '#6A6A6A', 'crit' => $h['crit'], 'spell' => $h['spell'],
                'icon' => $this->spellIcon($icons, $h['spell']), 'weight' => $weight, 'pin' => null,
                'tip' => $h['spell'].', '.($by['name'] ?? $h['by']).': '.number_format($h['amount'])
                    .($h['crit'] ? ', crit' : '')
                    .($h['shield'] ? ', '.number_format($h['shield']).' on a shield' : '')
                    .($h['hp'] !== null ? ', '.round($h['hp']).'% health left' : '')
                    .($with !== '' ? ', with '.$with : ''),
            ];
        }
        if ($hits) {
            $line[] = '1000,'.round(100 - $now, 1);
        } else {
            $line = collect($d['hp'])->map(fn ($v, $i) => round(1000 * $i / $n, 1).','.(100 - max(0, min(100, (int) $v))))->all();
        }
        // The biggest hits wear their icon, as many as stay apart. Closer ones appear in the last 10s.
        $picked = [];
        $order = array_keys(collect($bars)->sortByDesc('weight')->all());
        foreach ([[4, 100 / 12, 'all'], [8, 100 * 0.8 / $n, 'near']] as [$most, $gap, $tier]) {
            foreach ($order as $i) {
                if (count($picked) < $most && $bars[$i]['weight'] >= $peak * 0.25
                    && ! collect($picked)->contains(fn ($p) => abs($bars[$p]['left'] - $bars[$i]['left']) < $gap)) {
                    $picked[] = $i;
                    $bars[$i]['pin'] = $tier;
                }
            }
        }

        $goWhose = $d['side'] === 'us' ? 'Enemy' : 'Your';

        return [
            'seconds' => $n,
            // Worth a second view only when the whole go is much longer than its last ten seconds.
            'zoom' => $n >= 14 ? round($n / 10, 2) : null,
            'from' => ($d['goStartedAgo'] ?? null) !== null && $d['goStartedAgo'] <= $n + 1 ? $goWhose.' go starts' : $n.'s before',
            'cooldowns' => ['label' => ($killer === 'us' ? 'Your' : 'Enemy').' cooldowns', 'rows' => $stack($cooldowns)],
            'defensives' => ['label' => $d['side'] === 'us' ? 'Your defensives' : 'Their defensives', 'rows' => $stack($defensives)],
            'people' => $people,
            'hits' => $bars,
            'peak' => $this->amount($peak),
            'lockedLabel' => ($d['side'] === 'us' ? 'Your' : 'Enemy').' healer locked out',
            'line' => implode(' ', $line),
            'locked' => array_map(fn ($iv) => ['left' => round(100 * ($n - $iv[0]) / $n, 2), 'width' => round(100 * ($iv[0] - $iv[1]) / $n, 2)], $d['healerLocked'] ?? []),
            'ticks' => collect(range(0, max($n, 5), 5))->filter(fn ($s) => $s > 0 && $s < $n - 1)
                ->map(fn ($s) => ['left' => round(100 * ($n - $s) / $n, 2), 'label' => $s.'s', 'edge' => $s >= 10])->values()->all(),
            'schools' => array_sum($d['schools'] ?? []) > 0 ? $d['schools'] : null,
        ];
    }

    /**
     * The last ten seconds' damage by ability: a bar for how much it did, gold for the part that came
     * from crits, in place of the dot a hit first tried here (Chriso, 2026-11-02: 39 grey dots for
     * Melee said nothing the chart above does not). Beside it, the cooldowns the attacker had up for
     * most of those hits, named and never given a multiplier: the log says what was up, not how
     * much it added. Empty for a round measured before version 19.
     *
     * @return array{rows: array<int, array<string, mixed>>, shares: array<int, array<string, mixed>>}|null
     */
    private function hitRows(array $d, Collection $players, array $tl): ?array
    {
        $hits = array_filter($d['hits'] ?? [], fn ($h) => $h['ago'] <= 10);
        if (! $hits) {
            return null;
        }
        $byName = $players->keyBy('full');
        $total = max(1, array_sum(array_map(fn ($h) => $h['amount'] + $h['shield'], $hits)));
        $sum = fn (Collection $g) => $g->sum(fn ($h) => $h['amount'] + $h['shield']);
        $groups = collect($hits)->groupBy(fn ($h) => $h['by'].'|'.$h['spell'])->sortByDesc($sum)->take(6);
        $up = [];
        foreach ($groups as $key => $g) {
            $guid = $byName[$g->first()['by']]['guid'] ?? null;
            $times = $g->map(fn ($h) => $d['t'] - $h['ago']);
            $up[$key] = collect($tl['offUp'] ?? [])->filter(fn ($s) => $s[0] === $guid
                && $times->filter(fn ($t) => $t >= $s[2] && $t <= $s[3])->count() * 2 > $times->count())->pluck(1)->unique()->values()->all();
        }
        $icons = $this->icons->for(array_merge($groups->map(fn ($g) => $g->first()['spell'])->values()->all(), array_merge(...array_values($up))));
        $most = max(1, $sum($groups->first()));

        $rows = $groups->map(fn ($g, $key) => [
            'spell' => $g->first()['spell'],
            'icon' => $this->spellIcon($icons, $g->first()['spell']),
            'name' => $byName[$g->first()['by']]['name'] ?? $this->short($g->first()['by']),
            'color' => $byName[$g->first()['by']]['color'] ?? '#6A6A6A',
            'pct' => (int) round(100 * $sum($g) / $total),
            'crits' => $g->where('crit', true)->count(),
            'count' => $g->count(),
            'bar' => (int) round(100 * $sum($g) / $most),
            'critShare' => (int) round(100 * $sum($g->where('crit', true)) / max(1, $sum($g))),
            'up' => array_map(fn ($s) => ['spell' => $s, 'icon' => $this->spellIcon($icons, $s)], $up[$key]),
        ])->values()->all();

        return [
            'rows' => $rows,
            'shares' => collect($hits)->groupBy('by')->sortByDesc($sum)->take(4)->map(fn ($g, $name) => [
                'name' => $byName[$name]['name'] ?? $this->short($name),
                'color' => $byName[$name]['color'] ?? '#6A6A6A',
                'pct' => (int) round(100 * $sum($g) / $total),
            ])->values()->all(),
        ];
    }

    /**
     * The answer sheet for one of our deaths (RoundAnalysisService version 7): their go, split into
     * its offensive cooldowns and its crowd control (two jobs, two lists), each player's lockout in
     * it with the free moment before the longest one, and every button our team had, sorted into
     * ready-and-never-pressed, pressed, and already on cooldown.
     *
     * "Ready" is ordered most direct first: the dying player's own defensives, then the rest of the
     * team's (a healer's externals among them), then crowd control to peel, interrupts, and the
     * Medallions. It lists what was there; which one fitted the go is the player's read.
     */
    private function answers(array $d, Collection $players, array $goes): array
    {
        $s = $d['answers'];
        $byGuid = $players->keyBy('guid');
        $go = collect($goes)->first(fn ($g) => $g['side'] !== $d['side'] && abs($g['from'] - $s['from']) < 0.3);
        $links = collect($go['links'] ?? []);
        $icons = $this->icons->for(array_merge(array_column($s['rows'], 'spell'), $links->pluck('spell')->all()));
        $who = fn ($guid) => ['name' => $byGuid[$guid]['name'] ?? '?', 'color' => $byGuid[$guid]['color'] ?? '#B3B3B3'];
        $cell = fn (array $r) => ['spell' => $r['spell'], 'icon' => $this->spellIcon($icons, $r['spell'])] + $who($r['who']);

        $rank = fn (array $r) => match (true) {
            $r['kind'] === 'defensive' && $r['who'] === $d['who'] => 0,
            $r['kind'] === 'defensive' => ($byGuid[$r['who']]['healer'] ?? false) ? 1 : 2,
            $r['kind'] === 'control' => 3,
            $r['kind'] === 'interrupt' => 4,
            default => 5,
        };
        $rows = collect($s['rows']);

        return [
            'from' => $this->clock($s['from']),
            'offensive' => $links->whereIn('cat', ['offensive', 'mixed'])->unique('spell')
                ->map(fn ($l) => ['spell' => $l['spell'], 'icon' => $this->spellIcon($icons, $l['spell'])] + $who($l['by']))->values()->all(),
            'control' => $links->where('cat', 'control')
                ->map(fn ($l) => ['spell' => $l['spell'], 'icon' => $this->spellIcon($icons, $l['spell']), 'clock' => $this->clock(($go['from'] ?? 0) + $l['t']),
                    'on' => isset($l['on'], $byGuid[$l['on']]) ? $byGuid[$l['on']]['name'] : null, 'onUs' => isset($l['on'], $byGuid[$l['on']]) && $byGuid[$l['on']]['side'] === $d['side']] + $who($l['by']))
                ->filter(fn ($l) => $l['onUs'])->values()->all(),
            'lockout' => collect($s['lockout'])->map(fn ($l, $guid) => $who($guid) + [
                'seconds' => $l['seconds'],
                'longest' => round($l['longest'][1] - $l['longest'][0], 1),
                'longestFrom' => $this->clock($l['longest'][0]),
                'free' => $l['freeBefore'] ? $this->clock($l['freeBefore'][0]).' to '.$this->clock($l['freeBefore'][1]) : null,
            ])->sortByDesc('seconds')->values()->all(),
            // Ready and never pressed, sorted by whether it could have saved them (DefensiveFit): a
            // defensive that reached them and covers what hit them; crowd control, peels and the rest;
            // and what could not help, with why.
            'ready' => ($ready = $rows->where('state', 'ready')->sortBy(fn ($r) => [$rank($r), $byGuid[$r['who']]['name'] ?? ''])
                ->map(fn ($r) => $cell($r) + ['tried' => collect($r['tried'])->map(fn ($t) => $this->clock($t['t']).' ('.lcfirst(preg_replace("/^Can't do that /", '', $t['why'])).')')->implode(', ')]
                    + (in_array($r['kind'], ['defensive', 'trinket'], true)
                        ? DefensiveFit::judge($r['spell'], $r['who'] === $d['who'], $d['schools'] ?? null)
                        : ['fit' => 'other', 'why' => null]))
                ->values())->all(),
            'fits' => $ready->where('fit', 'fits')->values()->all(),
            'others' => $ready->where('fit', 'other')->values()->all(),
            'cannot' => $ready->where('fit', 'no')->values()->all(),
            'schools' => isset($d['schools']) && ($d['schools']['physical'] + $d['schools']['magic']) > 0 ? $d['schools'] : null,
            'pressed' => $pressed = $rows->where('state', 'pressed')->sortBy('at')->map(fn ($r) => $cell($r) + ['clock' => $this->clock($r['at'])])->values()->all(),
            'down' => $down = $rows->where('state', 'down')->map(fn ($r) => $cell($r) + ['back' => $this->clock($r['back'])])->values()->all(),
            'kit' => $this->kitStrip($ready->all(), $pressed, $down, $byGuid[$d['who']]['name'] ?? null),
        ];
    }

    /**
     * What each player had for the go, one row of icons a player, lit or greyed the way the Empowered
     * box shows a buff up or missing: ringed gold when it was ready and could have saved them, lit
     * when ready or pressed, grey when on cooldown or no use here. The dying player's row first.
     */
    private function kitStrip(array $ready, array $pressed, array $down, ?string $dying): array
    {
        $items = [];
        foreach ($ready as $x) {
            $state = ['fits' => 'fits', 'no' => 'no'][$x['fit']] ?? 'ready';
            $tip = match ($state) {
                'fits' => 'ready, and could have saved them',
                'no' => 'ready, could not help ('.$x['why'].')',
                default => 'ready',
            };
            $items[] = $x + ['state' => $state, 'cap' => null, 'tip' => $x['spell'].', '.$tip.($x['tried'] ? ', tried '.$x['tried'] : '')];
        }
        foreach ($pressed as $x) {
            $items[] = $x + ['state' => 'pressed', 'cap' => $x['clock'], 'tip' => $x['spell'].', pressed at '.$x['clock']];
        }
        foreach ($down as $x) {
            $items[] = $x + ['state' => 'down', 'cap' => $x['back'], 'tip' => $x['spell'].', on cooldown, back at '.$x['back']];
        }
        $rank = ['fits' => 0, 'ready' => 1, 'pressed' => 2, 'down' => 3, 'no' => 4];

        return collect($items)->groupBy('name')
            ->sortBy(fn ($g, $name) => $name === $dying ? 0 : 1)
            ->map(fn ($g, $name) => ['name' => $name, 'color' => $g->first()['color'], 'items' => $g->sortBy(fn ($x) => $rank[$x['state']])->values()->all()])
            ->values()->all();
    }

    /** One row per distinct item, with a count where a rule fired more than once (once per go). */
    private function look(array $items, Collection $players): array
    {
        $icons = $this->icons->for(array_filter(array_column($items, 'spell')));
        // Items are owned by your side's specs; an enemy of the same spec must not lend its colour.
        $bySpec = $players->where('side', 'us')->keyBy('spec');

        return collect($items)
            ->groupBy(fn ($i) => $i['owner'].'|'.$i['text'])
            ->map(function (Collection $g) use ($icons, $bySpec) {
                $i = $g->first();
                $them = str_starts_with($i['owner'], 'Them');

                return [
                    'owner' => $i['owner'],
                    'color' => $bySpec[$i['owner']]['color'] ?? ($them ? '#B3B3B3' : '#C8952C'),
                    'text' => $i['text'],
                    'icon' => $i['spell'] ? $this->spellIcon($icons, $i['spell']) : null,
                    'count' => $g->count(),
                    'them' => $them,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Each note goes to the round it was written for. The app stamps a note with the round's start
     * (`roundStart`, the ARENA_MATCH_START time) when it knows it; otherwise the note's own time
     * decides: inside a round, or up to NOTE_AFTER seconds after it ends.
     *
     * Both clocks are WoW's local wall clock: the log writes it, `played_at` stores it as written,
     * and the app stamps notes with the same machine's clock.
     *
     * Two kinds of note go on a whole game instead (the second array):
     * - `game` set: written in the app against that game (its lobby id, the app's match key).
     * - `kind` "next": written for the next game, so it goes on the first game that starts after it
     *   was written. Until one is played it is on no card; the app lists it as waiting.
     *
     * @param  Collection<string, Collection<int, ArenaRound>>  $lobbies
     * @return array{0: array<int, array<int, array>>, 1: array<string, array<int, array>>} round id => notes, lobby id => notes
     */
    private function attachNotes(Collection $lobbies, array $notes): array
    {
        $byGame = [];
        $starts = $lobbies->map(fn (Collection $rs) => $rs->min(fn (ArenaRound $r) => $r->played_at?->getTimestamp()))->filter()->sort();
        $notes = array_values(array_filter($notes, function ($note) use ($lobbies, $starts, &$byGame) {
            if (trim((string) ($note['text'] ?? '')) === '' || ! isset($note['at'])) {
                return true;
            }
            $at = Carbon::parse($note['at'], 'UTC')->getTimestamp();
            if (isset($note['game'])) {
                if ($lobbies->has($note['game'])) {
                    $byGame[$note['game']][] = $note + ['_at' => $at];
                }

                return false;
            }
            if (($note['kind'] ?? 'note') === 'next') {
                $next = $starts->filter(fn ($start) => $start >= $at)->keys()->first();
                if ($next !== null) {
                    $byGame[$next][] = $note + ['_at' => $at];
                }

                return false;
            }

            return true;
        }));

        $rounds = $lobbies->flatten(1);
        $out = [];
        $spans = $rounds->filter(fn (ArenaRound $r) => $r->played_at !== null)->map(fn (ArenaRound $r) => [
            'id' => $r->id,
            'from' => $r->played_at->getTimestamp(),
            'to' => $r->played_at->getTimestamp() + (int) ($this->summary($r)['metadata']['durationInSeconds'] ?? 0),
        ])->sortBy('from')->values();

        foreach ($notes as $note) {
            if (! isset($note['at']) || trim((string) ($note['text'] ?? '')) === '' && ($note['kind'] ?? 'note') !== 'mark') {
                continue;
            }
            $at = Carbon::parse($note['at'], 'UTC')->getTimestamp();
            $start = isset($note['roundStart']) ? Carbon::parse($note['roundStart'], 'UTC')->getTimestamp() : null;

            $hit = $start !== null
                ? $spans->first(fn ($s) => abs($s['from'] - $start) <= 2)
                : null;
            $hit ??= $spans->first(fn ($s) => $at >= $s['from'] - 2 && $at <= $s['to'] + 2);
            $hit ??= $spans->filter(fn ($s) => $at > $s['to'] && $at <= $s['to'] + self::NOTE_AFTER)->last();

            if ($hit) {
                $out[$hit['id']][] = $note + ['_at' => $at];
            }
        }

        return [$out, $byGame];
    }

    /** Notes on a whole game: written after it in the app, or before it for "the next game". */
    private function gameNoteRows(array $notes): array
    {
        return collect($notes)->sortBy('_at')->map(fn ($n) => [
            'when' => Carbon::createFromTimestamp($n['_at'], 'UTC')->format('D j M, H:i'),
            'text' => trim((string) $n['text']),
            'next' => ($n['kind'] ?? 'note') === 'next',
        ])->values()->all();
    }

    private function noteRows(array $notes, ArenaRound $round): array
    {
        $start = $round->played_at?->getTimestamp() ?? 0;

        return collect($notes)->sortBy('_at')->map(fn ($n) => [
            'clock' => $n['_at'] >= $start ? $this->clock($n['_at'] - $start) : 'before',
            'text' => trim((string) ($n['text'] ?? '')),
            'mark' => ($n['kind'] ?? 'note') === 'mark',
        ])->values()->all();
    }

    private function spellIcon(array $icons, string $name): ?string
    {
        $spell = $icons[$name] ?? null;

        return $spell?->icon_name ? $this->fileUrl('spell-icons/'.$spell->icon_name) : null;
    }

    private function fileUrl(string $relative): string
    {
        return \App\Support\DesktopAsset::url($relative);
    }

    private function clock(float $seconds): string
    {
        return sprintf('%d:%02d', intdiv((int) $seconds, 60), (int) $seconds % 60);
    }

    private function short(string $name): string
    {
        return explode('-', $name)[0];
    }
}
