<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One note a player wrote on one of their own games: from the browser, or from the desktop app.
 *
 * Reaches the card through GameCardService's existing note plumbing: `forCards()` shapes these the
 * way the developer app's notes.json looked. A note that names its game has `game` set and is
 * filed against the lobby outright. One from the app written during or before a game (a note, a
 * Ctrl+Shift+M mark, one for the next game) has only its time, and attachNotes() matches it to the
 * round it was written in, or the game after it.
 */
class ArenaNote extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'lobby_id', 'body', 'kind', 'noted_at', 'round_start', 'client_id'];

    protected $casts = [
        'noted_at' => 'datetime',
        'round_start' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every note a user has written, in the shape `wow:game-cards --notes=` already expects.
     *
     * The times of a note with no game are the PC's wall clock, written as they were sent, because
     * that is the clock `played_at` holds (both read as UTC by attachNotes()).
     *
     * @return array<int, array<string, string>>
     */
    public static function forCards(int $userId): array
    {
        return static::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (self $n) => $n->lobby_id !== null
                ? [
                    'at' => ($n->noted_at ?? $n->created_at)->format('Y-m-d H:i:s'),
                    'text' => (string) $n->body,
                    'game' => $n->lobby_id,
                    'kind' => $n->kind ?? 'note',
                ]
                : array_filter([
                    'at' => ($n->noted_at ?? $n->created_at)->format('Y-m-d H:i:s'),
                    'roundStart' => $n->round_start?->format('Y-m-d H:i:s'),
                    'text' => (string) $n->body,
                    'kind' => $n->kind ?? 'note',
                ], fn ($v) => $v !== null))
            ->all();
    }
}
