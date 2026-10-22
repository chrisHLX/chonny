<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One note a player wrote on one of their own games, from the browser.
 *
 * Reaches the card through GameCardService's existing note plumbing: `forCards()` shapes these the
 * way the desktop app's notes.json already looks, with `game` set, so attachNotes() files them
 * against the lobby without any timestamp matching.
 */
class ArenaNote extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'lobby_id', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every note a user has written, in the shape `wow:game-cards --notes=` already expects.
     *
     * @return array<int, array{at: string, text: string, game: string, kind: string}>
     */
    public static function forCards(int $userId): array
    {
        return static::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (self $n) => [
                'at' => $n->created_at->toIso8601String(),
                'text' => $n->body,
                'game' => $n->lobby_id,
                'kind' => 'note',
            ])
            ->all();
    }
}
