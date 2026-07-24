<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Structural spell-to-spell relationship (e.g. "Improved Power Word: Shield modifies Power Word:
 * Shield"), derived at import time from the source data's own effect-level "Modified By" /
 * "Affecting Spells" references — never inferred from free-text description parsing.
 */
class SpellRelationship extends Model
{
    protected $table = 'spell_relationships';

    protected $fillable = [
        'source_spell_id',
        'target_spell_id',
        'relationship_type',
        'description',
    ];

    public function sourceSpell()
    {
        return $this->belongsTo(Spell::class, 'source_spell_id');
    }

    public function targetSpell()
    {
        return $this->belongsTo(Spell::class, 'target_spell_id');
    }

    public function game(): ?Game
    {
        return $this->sourceSpell?->game();
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('sourceSpell', fn (Builder $q) => $q->inGame($gameId));
    }
}
