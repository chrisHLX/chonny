<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
