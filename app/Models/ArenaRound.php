<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded arena round, already derived, waiting to be assembled into a review.
 *
 * Rounds are the unit of upload because a whole combat log cannot be posted (see the migration).
 * They are kept after assembly rather than discarded: the raw log is never retained, so this
 * payload is the only way to rebuild a review after a parser fix.
 */
class ArenaRound extends Model
{
    protected $fillable = [
        'user_id', 'lobby_id', 'roster_key', 'sequence', 'match_id', 'bracket', 'played_at', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'played_at' => 'datetime',
        'sequence' => 'int',
    ];

    /**
     * Every save that changes the payload re-indexes the round's players (arena_round_players), so
     * a player page reads its games without decoding payloads, whoever wrote the round: an upload,
     * a re-measure, a test.
     */
    protected static function booted(): void
    {
        static::saved(function (ArenaRound $round) {
            if ($round->wasRecentlyCreated || $round->wasChanged('payload')) {
                app(\App\Http\Services\RoundPlayerIndexer::class)->index($round);
            }
        });
    }

    /**
     * A player's rounds in the order played, each with only what $keep takes from its payload, read 50
     * at a time (2026-10-28). The page builds held every round decoded at once: 1.3 GB at peak for a
     * 700-round account on a 2 GB box. Each round comes back without its payload; $keep returns null
     * to leave a round out.
     *
     * @param  callable(array $payload, ArenaRound $round): mixed  $keep
     * @return list<array{0: ArenaRound, 1: mixed}>
     */
    public static function readSlim(int $userId, callable $keep): array
    {
        $out = [];
        foreach (static::where('user_id', $userId)->lazyById(50) as $r) {
            $kept = $keep($r->payload ?? [], $r);
            $r->setRawAttributes(array_diff_key($r->getAttributes(), ['payload' => true]), true);
            if ($kept !== null) {
                $out[] = [$r, $kept];
            }
        }
        // By time, as the builds read them; usort is stable, so a tie keeps the order stored.
        usort($out, fn ($x, $y) => strcmp((string) $x[0]->played_at, (string) $y[0]->played_at));

        return $out;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(ArenaRoundPlayer::class);
    }
}
