<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PvpTalent extends Model
{
    // Explicit override: Eloquent's default pluralization ("Pvp" + "Talent" -> pluralize last
    // word) was observed resolving to the singular "pvp_talent" instead of "pvp_talents" against
    // the migrated table on this environment — hardcoding avoids relying on that inference.
    protected $table = 'pvp_talents';

    protected $fillable = [
        'spec_id',
        'patch_id',
        'spell_id',
        'unlock_level',
        'external_pvp_talent_id',
    ];

    public function specialization()
    {
        return $this->belongsTo(Specialization::class, 'spec_id');
    }

    public function patch()
    {
        return $this->belongsTo(Patch::class);
    }

    public function spell()
    {
        return $this->belongsTo(Spell::class);
    }

    public function game(): ?Game
    {
        return $this->patch?->game;
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('patch', fn (Builder $q) => $q->where('game_id', $gameId));
    }
}
