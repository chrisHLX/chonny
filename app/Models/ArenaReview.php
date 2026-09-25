<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One assembled game review, owned by the player who uploaded it.
 *
 * The page reads these and nothing else. Always scope by `user_id` — a review names five other
 * players with their talents and their gear, and it is one player's record of their own games,
 * never a public browser. The route carries `auth` and the component scopes by the viewer; both
 * halves are meant to be there.
 */
class ArenaReview extends Model
{
    protected $fillable = [
        'user_id', 'battlenet_character_id', 'lobby_id', 'character_name', 'bracket',
        'played_at', 'rounds', 'rounds_won', 'rounds_lost', 'mirrors', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'played_at' => 'datetime',
        'rounds' => 'int',
        'rounds_won' => 'int',
        'rounds_lost' => 'int',
        'mirrors' => 'int',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(BattlenetCharacter::class, 'battlenet_character_id');
    }
}
