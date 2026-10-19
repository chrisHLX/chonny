<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A character's rating in one bracket over one season: a point appended each time it is seen to
 * change, by the daily leaderboard pull (PvpLeaderboardService) or a character lookup
 * (BattlenetCharacterSyncService). Keyed by region and Blizzard's character id, so both write one
 * history. One row per character, bracket and season; see the 2026-10-19 migration for why.
 */
class PlayerRatingHistory extends Model
{
    protected $table = 'player_rating_history';

    public const CREATED_AT = null;

    protected $guarded = [];

    protected $casts = ['points' => 'array'];

    /**
     * Append one point to each row, creating rows as needed, in one statement per chunk. Each item:
     * region, blizzard_character_id, bracket, season, rating, won, lost. The caller decides what
     * changed; this only writes.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function record(array $items, ?int $at = null): void
    {
        $at ??= time();
        $now = now();

        foreach (array_chunk($items, 1000) as $chunk) {
            $rows = array_map(fn ($i) => [
                'region' => $i['region'],
                'blizzard_character_id' => $i['blizzard_character_id'],
                'bracket' => $i['bracket'],
                'season' => $i['season'],
                'points' => json_encode([[$at, (int) $i['rating'], (int) $i['won'], (int) $i['lost']]]),
                'updated_at' => $now,
            ], $chunk);

            // Concatenate the new point onto what is there: MySQL merges two arrays into one,
            // SQLite (the tests) appends the new array's only element.
            $merge = DB::connection()->getDriverName() === 'sqlite'
                ? DB::raw("json_insert(points, '$[#]', json_extract(excluded.points, '$[0]'))")
                : DB::raw('json_merge_preserve(points, values(points))');

            static::upsert($rows, ['region', 'blizzard_character_id', 'bracket', 'season'], ['points' => $merge, 'updated_at']);
        }
    }

    /** @return list<array{at: \Carbon\Carbon, rating: int, won: int, lost: int}> */
    public function pointList(): array
    {
        return array_map(fn ($p) => ['at' => \Carbon\Carbon::createFromTimestamp($p[0]), 'rating' => $p[1], 'won' => $p[2], 'lost' => $p[3]], $this->points ?? []);
    }
}
