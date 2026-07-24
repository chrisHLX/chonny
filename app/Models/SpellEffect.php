<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SpellEffect extends Model
{
    protected $fillable = [
        'spell_id',
        'effect_index',
        'type',
        'base_value',
        'scaled_value',
    ];

    // Without these, MySQL's decimal columns come back as strings (e.g. "4.6380") while
    // SpellDataFileParser hands upsertTrack() a float (4.638) — isDirty()'s fallback comparison
    // (strcmp of string forms) then flags every re-import as a change even when the underlying
    // number is identical, inflating "updated" counts with false positives.
    protected $casts = [
        'base_value' => 'float',
        'scaled_value' => 'float',
    ];

    public function spell()
    {
        return $this->belongsTo(Spell::class);
    }

    /** Traverses spell_id → spells.patch_id → patches.game_id. */
    public function game(): ?Game
    {
        return $this->spell?->game();
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('spell', fn (Builder $q) => $q->inGame($gameId));
    }
}
