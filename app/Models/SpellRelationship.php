<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Structural spell-to-spell relationship, derived at import time from the source data's own
 * explicit spell_id references — never inferred from free-text description parsing.
 * `relationship_type` is one of:
 * - 'modifies' — effect-value modifier (e.g. "Improved Power Word: Shield modifies Power Word:
 *   Shield"), from "Modified By" / "Affecting Spells".
 * - 'modifies_charges' — charge-count modifier (e.g. "Protector of the Frail grants Pain
 *   Suppression an extra charge"), from "Category" / "Affected Spells (Category)".
 * - 'replaces' — action-bar spell replacement (e.g. "Beacon of Virtue replaces Beacon of
 *   Light"), from a Talent Entry line's replace="<name>" (id=<id>) annotation.
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
