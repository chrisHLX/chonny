<?php

namespace App\Livewire\Players;

use App\Http\Services\BattlenetCharacterSyncService;
use App\Http\Services\CharacterTalentResolver;
use App\Http\Services\PlayerGamesService;
use App\Http\Services\PvpLeaderboardService;
use App\Http\Services\RealmDirectory;
use App\Models\BattlenetCharacter;
use App\Models\PageViewEvent;
use App\Models\PlayerRatingHistory;
use App\Models\PvpLeaderboardEntry;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /wow/player/{region}/{realm}/{name}: any character's PvP profile (2026-10-19). Exp, titles,
 * this season's ratings, the rating history this site has seen, gear and talents.
 *
 * SERVED FROM THE DATABASE; BLIZZARD IS ASKED ONLY BY A BROWSER. The page renders what is stored,
 * and `wire:init` fetches when nothing is stored or it is older than STALE_HOURS. A crawler that
 * runs no script therefore costs no Blizzard call, and one that does is held by the per-address
 * limit. A full lookup is about a dozen calls, sent together (BattlenetCharacterSyncService).
 *
 * Public data only: nothing here says whether the character is linked to an account on this site,
 * and its owner's own page (/characters/{id}) stays owner-only.
 */
class Show extends Component
{
    public const STALE_HOURS = 6;

    /** A Refresh any sooner than this after the last fetch does nothing. */
    public const REFRESH_MINUTES = 10;

    /** Blizzard lookups per address per minute. */
    public const LOOKUPS_PER_MINUTE = 15;

    #[Locked]
    public string $region;

    #[Locked]
    public string $realm;

    #[Locked]
    public string $name;

    #[Locked]
    public bool $notFound = false;

    #[Locked]
    public ?string $error = null;

    /** Blizzard's spec id for the talent tab on screen; null means the active spec. */
    public ?int $specExternalId = null;

    /**
     * The talent calculator is drawn only once a browser asks (wire:init): it costs about five
     * seconds and 750 queries a character (TalentSelector, measured 2026-10-19), and a crawler
     * reading every player page must not cost that.
     */
    public bool $talentsShown = false;

    /** How many of the games we have seen to list; "Show more" adds GAMES_STEP. */
    public int $gamesShown = self::GAMES_STEP;

    public const GAMES_STEP = 20;

    public function mount(string $region, string $realm, string $name): void
    {
        abort_unless(in_array($region, Search::regions(), true), 404);

        // One address per character: /wow/player/us/tichondrius/name, however it was typed.
        if ($realm !== mb_strtolower($realm) || $name !== mb_strtolower($name)) {
            $this->redirectRoute('players.show', ['region' => $region, 'realm' => mb_strtolower($realm), 'name' => mb_strtolower($name)]);
        }

        $this->region = $region;
        $this->realm = mb_strtolower($realm);
        $this->name = mb_strtoupper(mb_substr($name, 0, 1)).mb_strtolower(mb_substr($name, 1));

        $c = $this->character;
        PageViewEvent::log('player_profile', $c?->class_id, $c?->spec_id);
    }

    public function moreGames(): void
    {
        $this->gamesShown = min($this->gamesShown + self::GAMES_STEP, 500);
    }

    /** This character's places on Blizzard's leaderboards this season, highest rating first (the daily pull). */
    #[Computed]
    public function leaderboard()
    {
        return PvpLeaderboardEntry::query()
            ->where('region', $this->region)->where('realm_slug', $this->realm)->where('name', $this->name)
            ->orderByDesc('rating')
            ->get()
            ->map(fn ($e) => $e->setAttribute('label', app(PvpLeaderboardService::class)->label($e->bracket)));
    }

    /** Every uploaded game this player was in, whoever uploaded it (PlayerGamesService). */
    #[Computed]
    public function games(): array
    {
        return app(PlayerGamesService::class)->for($this->region, RealmDirectory::key($this->realm), $this->name, $this->gamesShown);
    }

    #[Computed]
    public function character(): ?BattlenetCharacter
    {
        return BattlenetCharacter::query()
            ->where('region', $this->region)
            ->where('realm_slug', $this->realm)
            ->where('name', $this->name)
            ->with(['gameClass', 'specialization.gameClass'])
            ->first();
    }

    public function needsFetch(): bool
    {
        $c = $this->character;

        return ! $this->notFound && ($c?->synced_at === null || $c->synced_at->lt(now()->subHours(self::STALE_HOURS)));
    }

    /** Run by wire:init: fetch from Blizzard when nothing fresh is stored. */
    public function load(): void
    {
        if ($this->needsFetch()) {
            $this->fetch();
        }
    }

    public function refresh(): void
    {
        $c = $this->character;
        if ($c?->synced_at === null || $c->synced_at->lt(now()->subMinutes(self::REFRESH_MINUTES))) {
            $this->fetch();
        }
    }

    public function showTalents(): void
    {
        $this->talentsShown = true;
    }

    public function selectSpec(int $specExternalId): void
    {
        if (collect($this->character?->talents ?? [])->contains('spec_external_id', $specExternalId)) {
            $this->specExternalId = $specExternalId;
            $this->talentsShown = true;
            unset($this->talentView);
        }
    }

    private function fetch(): void
    {
        $this->error = null;

        $allowed = RateLimiter::attempt('player-lookup:'.request()->ip(), self::LOOKUPS_PER_MINUTE, function () {
            try {
                $found = app(BattlenetCharacterSyncService::class)->lookupPublic($this->region, $this->realm, $this->name);
                $this->notFound = $found === null;
            } catch (\Throwable $e) {
                report($e);
                $this->error = "Blizzard isn't answering right now. Try again in a minute.";
            }
        }, 60);

        if (! $allowed) {
            $this->error = 'Too many lookups from here in the last minute. Try again shortly.';
        }

        unset($this->character, $this->talentView, $this->history, $this->leaderboard);
    }

    #[Computed]
    public function talentView(): ?array
    {
        return $this->character ? app(CharacterTalentResolver::class)->forCharacter($this->character, $this->specExternalId) : null;
    }

    /**
     * This season's ratings as this site has seen them change, per bracket, for brackets seen more
     * than once. Blizzard keeps no history, so it starts the first time anyone looked here.
     *
     * @return list<array{label: string, points: list<array{rating: int, at: \Carbon\Carbon}>}>
     */
    #[Computed]
    public function history(): array
    {
        $c = $this->character;
        $board = $this->leaderboard->first();
        $blizzardId = $c?->blizzard_character_id ?? $board?->blizzard_character_id;
        if (! $blizzardId) {
            return [];
        }

        $labels = app(PvpLeaderboardService::class);
        $season = collect($c?->ratings ?? [])->firstWhere('current', true)['season'] ?? $board?->season;

        return PlayerRatingHistory::query()
            ->where('region', $this->region)->where('blizzard_character_id', $blizzardId)
            ->when($season, fn ($q) => $q->where('season', $season))
            ->get()
            ->filter(fn ($h) => count($h->points ?? []) > 1)
            ->sortByDesc(fn ($h) => \Illuminate\Support\Arr::last($h->points ?? [])[1] ?? 0)
            ->map(fn ($h) => ['label' => $labels->label($h->bracket), 'points' => $h->pointList()])
            ->values()
            ->all();
    }

    public function render()
    {
        $c = $this->character;
        // Not from the realm list: a page nobody's browser ran must cost no Blizzard call.
        $realmName = $c?->realm_name ?? mb_convert_case(str_replace('-', ' ', $this->realm), MB_CASE_TITLE);

        $games = $this->games;
        $known = $c?->synced_at || $this->leaderboard->isNotEmpty() || $games['total'] > 0;

        return view('livewire.players.show', [
            'character' => $c,
            'realmName' => $realmName,
            'needsFetch' => $this->needsFetch(),
            'board' => $this->leaderboard,
            'games' => $games,
            'classColors' => config('wow_classes.colors', []),
        ])->layout('layouts.app', [
            'title' => "{$this->name}-{$realmName} ".strtoupper($this->region).' PvP profile | MindCollector',
            'description' => $this->describe($c, $realmName),
            // Nothing stored means nothing to index: a name is a page of its own once Blizzard's
            // profile, a leaderboard or an uploaded game has it.
            'robots' => $known ? null : 'noindex',
        ]);
    }

    private function describe(?BattlenetCharacter $c, string $realmName): string
    {
        if (! $c?->synced_at) {
            $top = $this->leaderboard->take(3)->map(fn ($e) => "{$e->label} {$e->rating}")->implode(', ');

            return "{$this->name} on {$realmName}: ".($top !== '' ? $top.'. ' : '').'World of Warcraft arena and Solo Shuffle ratings, exp, titles and talents.';
        }

        $parts = [trim("{$c->name} on {$realmName} (".strtoupper($c->region).'): '.trim(($c->specialization?->name ?? '').' '.($c->gameClass?->name ?? '')))];
        if ($best = $c->bestExp()) {
            $parts[] = "{$best['bracket']} exp {$best['rating']}";
        }
        foreach (array_slice($c->currentRatings(), 0, 3) as $r) {
            $parts[] = "{$r['label']} {$r['rating']}";
        }

        return implode(', ', $parts).'. Ratings, titles, gear and talents.';
    }
}
