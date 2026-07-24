<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (spell, class[, spec]) combination a spell is actually available under, sourced
 * from where the importer found it: a class's baseline.txt ('baseline', spec_id null), a
 * spec-named or class-talents.txt talent file ('talent', spec_id set when the file names a
 * specific spec), or the pvp talents JSON ('pvp_talent', spec_id always known). A spell can have
 * many rows here — this is what makes "which class(es) is Fireball actually available to"
 * answerable, which spells/spell_effects alone can't answer for baseline/rotation spells.
 */
class SpellClassAvailability extends Model
{
    protected $table = 'spell_class_availability';

    protected $fillable = [
        'spell_id',
        'class_id',
        'spec_id',
        'source',
    ];

    public function spell()
    {
        return $this->belongsTo(Spell::class);
    }

    public function gameClass()
    {
        return $this->belongsTo(GameClass::class, 'class_id');
    }

    public function specialization()
    {
        return $this->belongsTo(Specialization::class, 'spec_id');
    }

    public function game(): ?Game
    {
        return $this->gameClass?->game;
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('gameClass', fn (Builder $q) => $q->where('game_id', $gameId));
    }
}
