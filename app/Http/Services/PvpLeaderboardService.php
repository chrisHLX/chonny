<?php

namespace App\Http\Services;

use App\Models\PlayerRatingHistory;
use App\Models\PvpLeaderboardEntry;
use App\Models\Specialization;
use Illuminate\Support\Facades\Log;

/**
 * Blizzard's PvP leaderboards into pvp_leaderboard_entries, once a day (wow:pvp-leaderboards, run by
 * cron: DEPLOY.md). Every board of the current season: 3v3, 2v2, RBG, and Shuffle and Blitz per spec
 * (85 a region on 19 Oct 2026, up to about 5,000 entries each, about 1s a board). The two "overall"
 * boards repeat the per-spec ones and are skipped.
 *
 * One call a board gives every character on it a page with real ratings (Players\Show) before anyone
 * has looked them up, and a rating history: a point is appended for each entry whose rating or
 * record changed since yesterday's pull, to the same history a character lookup writes
 * (PlayerRatingHistory).
 */
class PvpLeaderboardService
{
    private const CHUNK = 1000;

    public function __construct(private BattlenetClient $client) {}

    /** @return array{season: ?int, boards: int, entries: int, snapshots: int, failed: list<string>} */
    public function pull(string $region, ?callable $progress = null): array
    {
        $out = ['season' => null, 'boards' => 0, 'entries' => 0, 'snapshots' => 0, 'failed' => []];

        $season = $this->client->gameData($region, '/data/wow/pvp-season/index', 'dynamic')['current_season']['id'] ?? null;
        if (! $season) {
            return $out;
        }
        $out['season'] = (int) $season;

        $boards = array_column($this->client->gameData($region, "/data/wow/pvp-season/{$season}/pvp-leaderboard/index", 'dynamic')['leaderboards'] ?? [], 'name');
        $boards = array_values(array_filter($boards, fn ($b) => is_string($b) && ! str_ends_with($b, '-overall')));
        $specs = $this->specs();
        $started = now();

        foreach ($boards as $board) {
            try {
                $entries = $this->client->gameData($region, "/data/wow/pvp-season/{$season}/pvp-leaderboard/{$board}", 'dynamic')['entries'] ?? [];
            } catch (\Throwable $e) {
                Log::warning('PvP leaderboard pull failed', ['region' => $region, 'board' => $board, 'error' => $e->getMessage()]);
                $out['failed'][] = $board;

                continue;
            }

            [$n, $snaps] = $this->store($region, (int) $season, $board, $entries, $specs[$this->specKey($board)] ?? null);
            $out['boards']++;
            $out['entries'] += $n;
            $out['snapshots'] += $snaps;
            unset($entries);

            // Off the board since the last pull: it is not on it now.
            PvpLeaderboardEntry::where('region', $region)->where('season', $season)->where('bracket', $board)
                ->where('seen_at', '<', $started)->delete();

            if ($progress) {
                $progress($board, $n, $snaps);
            }
        }

        // A new season starts the boards again; last season's entries go, their history stays for
        // one season more, then goes a slice at a time to keep locks short.
        PvpLeaderboardEntry::where('region', $region)->where('season', '<', $season)->delete();
        while (PlayerRatingHistory::where('region', $region)->where('season', '<', $season - 1)->limit(20000)->delete() > 0);

        return $out;
    }

    /** @return array{0: int, 1: int} entries stored, snapshots written */
    private function store(string $region, int $season, string $board, array $entries, ?array $spec): array
    {
        $before = PvpLeaderboardEntry::where('region', $region)->where('season', $season)->where('bracket', $board)
            ->get(['blizzard_character_id', 'rating', 'won', 'lost'])
            ->keyBy('blizzard_character_id');

        $now = now();
        $rows = [];
        $snapshots = [];

        foreach ($entries as $e) {
            $c = $e['character'] ?? [];
            if (empty($c['id']) || empty($c['name']) || empty($c['realm']['slug'])) {
                continue;
            }

            $id = (int) $c['id'];
            $rating = (int) ($e['rating'] ?? 0);
            $won = (int) ($e['season_match_statistics']['won'] ?? 0);
            $lost = (int) ($e['season_match_statistics']['lost'] ?? 0);

            $rows[] = [
                'region' => $region,
                'season' => $season,
                'bracket' => $board,
                'blizzard_character_id' => $id,
                'name' => mb_substr((string) $c['name'], 0, 32),
                'realm_slug' => mb_substr((string) $c['realm']['slug'], 0, 64),
                'spec_external_id' => $spec['id'] ?? null,
                'class_slug' => $spec['class'] ?? null,
                'faction' => isset($e['faction']['type']) ? mb_substr((string) $e['faction']['type'], 0, 10) : null,
                'rank' => (int) ($e['rank'] ?? 0),
                'rating' => $rating,
                'won' => $won,
                'lost' => $lost,
                'tier_id' => isset($e['tier']['id']) ? (int) $e['tier']['id'] : null,
                'seen_at' => $now,
            ];

            $prev = $before[$id] ?? null;
            if ($rating > 0 && (! $prev || $prev->rating !== $rating || $prev->won !== $won || $prev->lost !== $lost)) {
                $snapshots[] = [
                    'region' => $region,
                    'blizzard_character_id' => $id,
                    'bracket' => $board,
                    'season' => $season,
                    'rating' => $rating,
                    'won' => $won,
                    'lost' => $lost,
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            PvpLeaderboardEntry::upsert($chunk, ['region', 'season', 'bracket', 'blizzard_character_id'],
                ['name', 'realm_slug', 'spec_external_id', 'class_slug', 'faction', 'rank', 'rating', 'won', 'lost', 'tier_id', 'seen_at']);
        }
        PlayerRatingHistory::record($snapshots, $now->getTimestamp());

        return [count($rows), count($snapshots)];
    }

    /** A bracket key as people say it: "3v3", "Rated BG", "Solo Shuffle · Beast Mastery Hunter". */
    public function label(string $bracket): string
    {
        return match (true) {
            $bracket === 'rbg' => 'Rated BG',
            str_starts_with($bracket, 'shuffle') => 'Solo Shuffle'.(($s = $this->specs()[$this->specKey($bracket)]['label'] ?? null) ? ' · '.$s : ''),
            str_starts_with($bracket, 'blitz') => 'Blitz'.(($s = $this->specs()[$this->specKey($bracket)]['label'] ?? null) ? ' · '.$s : ''),
            default => $bracket,
        };
    }

    /** "shuffle-hunter-beastmastery" -> "hunter/beastmastery"; null for a board of no one spec. */
    private function specKey(string $board): ?string
    {
        return preg_match('/^(?:shuffle|blitz)-([a-z]+)-([a-z]+)$/', $board, $m) ? $m[1].'/'.$m[2] : null;
    }

    /** @var array<string, array{id: int, class: string, label: string}>|null */
    private ?array $specs = null;

    /** @return array<string, array{id: int, class: string, label: string}> keyed as specKey() writes it */
    private function specs(): array
    {
        return $this->specs ??= Specialization::with('gameClass')
            ->whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))
            ->get()
            ->mapWithKeys(fn ($s) => [
                str_replace('-', '', $s->gameClass->slug).'/'.str_replace('-', '', $s->slug) => ['id' => (int) $s->external_spec_id, 'class' => $s->gameClass->slug, 'label' => trim($s->name.' '.$s->gameClass->name)],
            ])
            ->all();
    }
}
