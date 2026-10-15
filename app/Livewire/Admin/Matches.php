<?php

namespace App\Livewire\Admin;

use App\Jobs\BuildCoachPages;
use App\Models\ArenaReview;
use App\Models\ArenaRound;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every game uploaded to the site, for an admin (/admin/matches, 2026-10-15): who uploads, how
 * much, and each game with both teams, the result and the MMR, opening into its rounds and the
 * uploader's own game card.
 *
 * The list is built from `arena_reviews` (one row per game, the counts in plain columns). Only the
 * page's own rows are decoded (a review is ~80 KB of JSON), and the MMR is pulled out of the
 * page's rounds in SQL so their ~100 KB payloads are never loaded.
 *
 * `played_at` is the uploader's own clock as the combat log wrote it (Australian players' games
 * read 11 hours ahead of UTC); upload times are the server's UTC. The page says which is which.
 * A game two people uploaded (a 2v2 with a friend) is two rows: one per uploader.
 */
class Matches extends Component
{
    use WithPagination;

    public const PER_PAGE = 30;

    public const PERIODS = ['1' => 'Last 24 hours', '7' => 'Last 7 days', '30' => 'Last 30 days', 'all' => 'All time'];

    #[Url]
    public ?int $uploader = null;

    #[Url]
    public string $bracket = '';

    #[Url]
    public string $period = 'all';

    #[Url]
    public string $result = '';

    #[Url(as: 'q')]
    public string $search = '';

    /** The review id opened to its rounds. */
    public ?int $open = null;

    public function updating(string $name): void
    {
        if ($name !== 'open') {
            $this->resetPage();
            $this->open = null;
        }
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    public function showUploader(?int $id): void
    {
        $this->uploader = $this->uploader === $id ? null : $id;
        $this->open = null;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('uploader', 'bracket', 'period', 'result', 'search', 'open');
        $this->resetPage();
    }

    private function summary(): array
    {
        $totals = ArenaReview::query()
            ->selectRaw('count(*) games, coalesce(sum(rounds), 0) rounds, coalesce(sum(rounds_won), 0) won, coalesce(sum(rounds_lost), 0) lost, count(distinct user_id) uploaders')
            ->first();

        return [
            'games' => (int) $totals->games,
            'rounds' => (int) $totals->rounds,
            'won' => (int) $totals->won,
            'lost' => (int) $totals->lost,
            'uploaders' => (int) $totals->uploaders,
            'day' => ArenaReview::where('played_at', '>=', now()->subDay())->count(),
            'week' => ArenaReview::where('played_at', '>=', now()->subDays(7))->count(),
            'connected' => User::whereNotNull('coach_token_at')->count(),
            'uploadedToday' => ArenaRound::where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /** Games per day over the last 30 days, by the uploader's date. */
    private function daily(): Collection
    {
        $from = now()->subDays(29)->startOfDay();
        $counts = ArenaReview::where('played_at', '>=', $from)->pluck('played_at')
            ->countBy(fn ($at) => $at->format('Y-m-d'));

        return collect(range(29, 0))->map(function (int $ago) use ($counts) {
            $day = now()->subDays($ago);

            return ['date' => $day->format('Y-m-d'), 'label' => $day->format('j M'), 'games' => $counts[$day->format('Y-m-d')] ?? 0];
        });
    }

    /** Everyone who uploads, and everyone who connected the app without a game yet. */
    private function uploaders(): Collection
    {
        $stats = ArenaReview::query()
            ->selectRaw('user_id, count(*) games, coalesce(sum(rounds_won), 0) won, coalesce(sum(rounds_lost), 0) lost, min(played_at) first_played, max(played_at) last_played')
            ->groupBy('user_id')->get()->keyBy('user_id');
        $brackets = ArenaReview::query()->selectRaw('user_id, bracket, count(*) n')
            ->groupBy('user_id', 'bracket')->get()->groupBy('user_id');
        $characters = ArenaReview::query()->selectRaw('user_id, character_name, count(*) n')
            ->whereNotNull('character_name')->groupBy('user_id', 'character_name')->get()->groupBy('user_id');
        $uploads = ArenaRound::query()->selectRaw('user_id, max(created_at) last_upload, count(*) rounds')
            ->groupBy('user_id')->get()->keyBy('user_id');

        return User::query()
            ->whereIn('id', $stats->keys()->merge($uploads->keys())->unique()->all())
            ->orWhereNotNull('coach_token_at')
            ->get(['id', 'name', 'email', 'created_at', 'coach_token_at', 'pool_opt_out_at'])
            ->map(function (User $u) use ($stats, $brackets, $characters, $uploads) {
                $s = $stats[$u->id] ?? null;

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'joined' => $u->created_at,
                    'connected' => $u->coach_token_at,
                    'pooled' => $u->pool_opt_out_at === null,
                    'games' => (int) ($s->games ?? 0),
                    'won' => (int) ($s->won ?? 0),
                    'lost' => (int) ($s->lost ?? 0),
                    'lastPlayed' => $s?->last_played,
                    'lastUpload' => $uploads[$u->id]->last_upload ?? null,
                    'brackets' => collect($brackets[$u->id] ?? [])->sortByDesc('n')->map(fn ($b) => ['bracket' => $b->bracket, 'n' => (int) $b->n])->values()->all(),
                    'characters' => collect($characters[$u->id] ?? [])->sortByDesc('n')->map(fn ($c) => self::shortName($c->character_name))->unique()->values()->all(),
                ];
            })
            ->sortByDesc(fn ($u) => (string) ($u['lastUpload'] ?? $u['connected'] ?? ''))
            ->values();
    }

    private function query()
    {
        $q = ArenaReview::query()
            ->select(['id', 'user_id', 'lobby_id', 'character_name', 'bracket', 'played_at', 'rounds', 'rounds_won', 'rounds_lost', 'created_at'])
            ->with('user:id,name');

        if ($this->uploader) {
            $q->where('user_id', $this->uploader);
        }
        if ($this->bracket !== '') {
            $q->where('bracket', $this->bracket);
        }
        if (isset(self::PERIODS[$this->period]) && $this->period !== 'all') {
            $q->where('played_at', '>=', now()->subDays((int) $this->period));
        }
        match ($this->result) {
            'won' => $q->whereColumn('rounds_won', '>', 'rounds_lost'),
            'lost' => $q->whereColumn('rounds_won', '<', 'rounds_lost'),
            'even' => $q->whereColumn('rounds_won', '=', 'rounds_lost'),
            default => null,
        };
        $search = trim($this->search);
        if ($search !== '') {
            // Any player in the game whose name starts with this: the payload stores every player
            // as "name":"Name-Realm-Region", so the match is anchored on that, not on spell text.
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $q->where(fn ($w) => $w->where('character_name', 'like', $like.'%')
                ->orWhere('payload', 'like', '%"name":"'.$like.'%'));
        }

        return $q->orderByDesc('played_at')->orderByDesc('id');
    }

    /** The page's games with their teams, MMR and length, read from their own payloads only. */
    private function decorate(Collection $page): Collection
    {
        if ($page->isEmpty()) {
            return collect();
        }

        $payloads = ArenaReview::whereIn('id', $page->pluck('id'))->get(['id', 'payload'])->pluck('payload', 'id');
        $rounds = $this->roundFacts($page);

        return $page->map(function (ArenaReview $g) use ($payloads, $rounds) {
            $p = $payloads[$g->id] ?? [];
            $players = collect($p['players'] ?? []);
            $you = $players->firstWhere('isYou', true);
            $shuffle = str_contains($g->bracket, 'Shuffle');
            $side = $you ? (collect($you['teamByRound'] ?? [])->first()) : null;

            $allies = $shuffle || $side === null ? collect() : $players->filter(fn ($pl) => ! ($pl['isYou'] ?? false) && collect($pl['teamByRound'] ?? [])->first() === $side);
            $enemies = $shuffle || $side === null
                ? $players->reject(fn ($pl) => $pl['isYou'] ?? false)
                : $players->reject(fn ($pl) => ($pl['isYou'] ?? false) || collect($pl['teamByRound'] ?? [])->first() === $side);

            $facts = $rounds->get($g->user_id.'|'.$g->lobby_id, collect());
            $us = $facts->pluck('us')->filter();
            $them = $facts->pluck('them')->filter();

            return [
                'id' => $g->id,
                'userId' => $g->user_id,
                'uploader' => $g->user?->name ?? '#'.$g->user_id,
                'lobby' => $g->lobby_id,
                'bracket' => $g->bracket,
                'shuffle' => $shuffle,
                'playedAt' => $g->played_at,
                'uploadedAt' => $g->created_at,
                'won' => $g->rounds_won,
                'lost' => $g->rounds_lost,
                'you' => $you ? self::player($you) : ['name' => self::shortName($g->character_name), 'full' => $g->character_name, 'spec' => null, 'class' => null],
                'allies' => $allies->map(fn ($pl) => self::player($pl))->values()->all(),
                'enemies' => $enemies->map(fn ($pl) => self::player($pl))->values()->all(),
                'mmrUs' => $us->isEmpty() ? null : (int) round($us->avg()),
                'mmrThem' => $them->isEmpty() ? null : (int) round($them->avg()),
                'seconds' => (int) $facts->sum('seconds') ?: (int) collect($p['rounds'] ?? [])->sum('durationSeconds'),
                'card' => File::exists(BuildCoachPages::dir($g->user_id).'/'.$g->lobby_id.'.html'),
                'detail' => $this->open === $g->id ? $this->detail($p, $you, $facts) : null,
            ];
        });
    }

    /** MMR and length per round, straight out of the stored JSON, keyed by uploader and game. */
    private function roundFacts(Collection $page): Collection
    {
        return ArenaRound::query()
            ->whereIn('lobby_id', $page->pluck('lobby_id')->unique())
            ->whereIn('user_id', $page->pluck('user_id')->unique())
            ->selectRaw("user_id, lobby_id, match_id, sequence, json_extract(payload, '$.analysis.mmr') as mmr, json_extract(payload, '$.metadata.durationInSeconds') as seconds")
            ->get()
            ->map(function ($r) {
                $mmr = is_string($r->mmr) ? json_decode($r->mmr, true) : (array) $r->mmr;

                return [
                    'key' => $r->user_id.'|'.$r->lobby_id,
                    'matchId' => $r->match_id,
                    'us' => isset($mmr['us']) ? (int) $mmr['us'] : null,
                    'them' => isset($mmr['them']) ? (int) $mmr['them'] : null,
                    'seconds' => (int) $r->seconds,
                ];
            })
            ->groupBy('key');
    }

    /** One game opened: its rounds (who was with you, who died) and every player's totals. */
    private function detail(array $p, ?array $you, Collection $facts): array
    {
        $players = collect($p['players'] ?? []);
        $byMatch = $facts->keyBy('matchId');

        $rounds = collect($p['rounds'] ?? [])->map(function ($r) use ($players, $you, $byMatch) {
            $seq = (string) ($r['sequence'] ?? 1);
            $side = $you['teamByRound'][$seq] ?? null;
            $with = $players->filter(fn ($pl) => ! ($pl['isYou'] ?? false) && $side !== null && ($pl['teamByRound'][$seq] ?? null) === $side);
            $f = $byMatch[$r['matchId'] ?? ''] ?? null;

            return [
                'sequence' => $r['sequence'] ?? null,
                'result' => $r['result'] ?? null,
                'seconds' => $r['durationSeconds'] ?? null,
                'died' => empty($r['killedName']) ? null : self::shortName($r['killedName']),
                'with' => $with->map(fn ($pl) => self::player($pl))->values()->all(),
                'mmrUs' => $f['us'] ?? null,
                'mmrThem' => $f['them'] ?? null,
            ];
        })->all();

        $rows = $players->map(fn ($pl) => self::player($pl) + [
            'isYou' => (bool) ($pl['isYou'] ?? false),
            'ilvl' => isset($pl['gear']['median']) ? (int) round($pl['gear']['median']) : null,
            'damage' => (int) ($pl['totals']['damageDone'] ?? 0),
            'healing' => (int) ($pl['totals']['healingEffective'] ?? 0),
            'deaths' => (int) ($pl['totals']['deaths'] ?? 0),
            'rounds' => (int) ($pl['roundsPlayed'] ?? 0),
        ])->sortByDesc('isYou')->values()->all();

        return ['rounds' => $rounds, 'players' => $rows];
    }

    private static function player(array $pl): array
    {
        return [
            'name' => self::shortName($pl['name'] ?? null),
            'full' => $pl['name'] ?? null,
            'spec' => $pl['spec']['label'] ?? null,
            'class' => $pl['spec']['classSlug'] ?? null,
        ];
    }

    /** "Skylake-Frostmourne-US" reads as "Skylake"; the full name goes in the title. */
    private static function shortName(?string $name): string
    {
        return $name === null ? '?' : explode('-', $name)[0];
    }

    public function render()
    {
        $page = $this->query()->paginate(self::PER_PAGE);

        return view('livewire.admin.matches', [
            'summary' => $this->summary(),
            'daily' => $this->daily(),
            'uploaders' => $this->uploaders(),
            'games' => $this->decorate($page->getCollection()),
            'page' => $page,
            'brackets' => ArenaReview::query()->distinct()->orderBy('bracket')->pluck('bracket'),
            'periods' => self::PERIODS,
            'classColors' => config('wow_classes.colors', []),
        ])->layout('layouts.app', ['title' => 'Matches']);
    }
}
