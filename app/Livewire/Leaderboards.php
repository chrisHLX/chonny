<?php

namespace App\Livewire;

use App\Http\Services\CompLeaderboardService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Leaderboards (/wow/leaderboards, 2026-10-21): two tabs, one page.
 *
 * COMPS: every 3v3 comp in the games uploaded to MindCollector this season, with its games,
 * record, MMR and experience, filtered by the teams' experience (CompLeaderboardService::
 * EXP_BANDS), and the highest-rated players on Blizzard's 3v3 ladder seen playing it. A row opens
 * the comp's card, its real games (CompsPlayed). Chriso: "the leaderboard doesnt display comp v
 * Comp just comp filtered by EXP"; the record against each comp stays in the board's data (a card
 * can still be opened for one matchup) but is not drawn here.
 *
 * PLAYERS: Blizzard's 3v3 ladder for a region (pvp_leaderboard_entries, pulled daily), each player
 * with the comps they were seen playing in uploaded games, and a link to their page. Chriso:
 * "integrate the comps to player leaderboard". Blizzard's ladder names no team, so "seen on" is
 * only ever what uploaded games show, and most players have none.
 *
 * GAMES (2026-10-27): every 3v3 game uploaded this season, newest first, each opening its own card
 * with no one named (CompGame). Chriso: "Can we also have in leaderboard individual matches so we
 * can click on the match card".
 *
 * The 3v3 comps page keeps a top-five preview of the comps tab (CompBoard).
 */
class Leaderboards extends Component
{
    /** Comps before "Show every comp". */
    public const SHOWN = 25;

    /** Players a page of the ladder, and the most "Show more" reaches. */
    public const LADDER_STEP = 100;

    public const LADDER_MAX = 1000;

    /** Games a page of the Games tab, and the most "Show more" reaches. */
    public const GAMES_STEP = 50;

    public const GAMES_MAX = 500;

    /** 'comps' | 'games' | 'players'. Client-writable like every filter here, so read through tab(). */
    #[Url(except: 'comps')]
    public string $tab = 'comps';

    /**
     * The experience band, a key of EXP_BANDS; anything else reads as every team. In the URL so a
     * filtered board can be linked, and a string because a URL can carry anything.
     */
    #[Url(as: 'exp', except: null)]
    public ?string $exp = null;

    /** Only comps with this spec in them (Blizzard's spec id, 2026-10-29); a string, as $exp is. */
    #[Url(as: 'spec', except: null)]
    public ?string $spec = null;

    #[Url(except: 'us')]
    public string $region = 'us';

    /** Only players seen in an uploaded game. Not in the URL: a URL's junk would not fit a bool. */
    public bool $seenOnly = false;

    public bool $all = false;

    public int $ladderShown = self::LADDER_STEP;

    public int $gamesShown = self::GAMES_STEP;

    public function mount(): void
    {
        PageViewEvent::log('leaderboards');
    }

    /** A tab, band, region or filter chosen on the page: the attributed counts (one arriving in a link is not a choice). */
    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['players', 'games'], true) ? $tab : 'comps';
        PageViewEvent::log('leaderboards', null, null, 'tab:'.$this->tab);
    }

    public function setExp(?int $exp): void
    {
        $this->exp = isset(CompLeaderboardService::EXP_BANDS[$exp]) ? (string) $exp : null;
        $this->all = false;
        PageViewEvent::log('leaderboards', null, null, 'exp:'.($this->exp ?? 'all'));
    }

    public function setSpec(?string $spec): void
    {
        $this->spec = ctype_digit((string) $spec) ? (string) $spec : null;
        $this->all = false;
        PageViewEvent::log('leaderboards', null, null, 'spec:'.($this->spec ?? 'any'));
    }

    public function setRegion(string $region): void
    {
        $this->region = in_array($region, self::regions(), true) ? $region : 'us';
        $this->ladderShown = self::LADDER_STEP;
        PageViewEvent::log('leaderboards', null, null, 'region:'.$this->region);
    }

    public function toggleSeen(): void
    {
        $this->seenOnly = ! $this->seenOnly;
        $this->ladderShown = self::LADDER_STEP;
        PageViewEvent::log('leaderboards', null, null, 'seen:'.($this->seenOnly ? 'on' : 'off'));
    }

    public function showAll(): void
    {
        $this->all = true;
    }

    public function showMorePlayers(): void
    {
        $this->ladderShown = min(self::LADDER_MAX, max(self::LADDER_STEP, $this->ladderShown) + self::LADDER_STEP);
    }

    public function showMoreGames(): void
    {
        $this->gamesShown = min(self::GAMES_MAX, max(self::GAMES_STEP, $this->gamesShown) + self::GAMES_STEP);
    }

    /** @return list<string> */
    public static function regions(): array
    {
        return config('services.battlenet.regions', ['us', 'eu']);
    }

    public function render()
    {
        $service = app(CompLeaderboardService::class);
        $tab = in_array($this->tab, ['players', 'games'], true) ? $this->tab : 'comps';
        $region = in_array($this->region, self::regions(), true) ? $this->region : 'us';
        $board = $service->board(ctype_digit((string) $this->exp) ? (int) $this->exp : null, ctype_digit((string) $this->spec) ? (int) $this->spec : null);
        $ladder = $tab === 'players'
            ? $service->ladder($region, $this->seenOnly, max(self::LADDER_STEP, min(self::LADDER_MAX, $this->ladderShown)))
            : null;
        $games = $tab === 'games' ? $service->recentGames(max(self::GAMES_STEP, min(self::GAMES_MAX, $this->gamesShown))) : null;
        // The comps tab's side column (2026-10-29): the newest games and the best-rated players seen.
        $newest = $tab === 'comps' ? $service->recentGames(5)['games'] : [];
        $topSeen = $tab === 'comps' ? array_slice($service->ladder('us', true, 5)['players'] ?? [], 0, 5) : [];
        // Each comp's line-up as spec icons.
        $specIds = collect($board['comps'])->flatMap(fn ($c) => $c['lineups'][0]['ids'] ?? [])->unique()->values()->all();

        return view('livewire.leaderboards', [
            'tabNow' => $tab,
            'seenOnly' => $this->seenOnly,
            'ladderShown' => $this->ladderShown,
            'all' => $this->all,
            'regionNow' => $region,
            'regions' => self::regions(),
            'board' => $board,
            'ladder' => $ladder,
            'games' => $games,
            'newest' => $newest,
            'topSeen' => $topSeen,
            'specsSeen' => $tab === 'comps' ? $service->specsSeen() : [],
            'specModels' => \App\Models\Specialization::with('gameClass')->whereIn('external_spec_id', $specIds)->get()->keyBy('external_spec_id'),
            'bands' => CompLeaderboardService::EXP_BANDS,
            'comps' => $this->all ? $board['comps'] : array_slice($board['comps'], 0, self::SHOWN),
            'classes' => GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->get()->keyBy('slug'),
            'colors' => config('wow_classes.colors', []),
        ])->layout('layouts.app', [
            'title' => '3v3 leaderboards this season: comps and players | MindCollector',
            'description' => 'This season\'s 3v3 arena comps from real games uploaded to MindCollector, by the experience of the teams playing them, and Blizzard\'s 3v3 ladder with the comps each player was seen on.',
        ]);
    }
}
