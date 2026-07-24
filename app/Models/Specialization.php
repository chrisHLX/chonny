<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Specialization extends Model
{
    protected $fillable = [
        'class_id',
        'name',
        'slug',
        'external_spec_id',
    ];

    public function gameClass()
    {
        return $this->belongsTo(GameClass::class, 'class_id');
    }

    public function talentTrees()
    {
        return $this->hasMany(TalentTree::class, 'spec_id');
    }

    public function pvpTalents()
    {
        return $this->hasMany(PvpTalent::class, 'spec_id');
    }

    public function talentBuilds()
    {
        return $this->hasMany(TalentBuild::class, 'spec_id');
    }

    /** Traverses class_id → classes.game_id — not directly stored on this model. */
    public function game(): ?Game
    {
        return $this->gameClass?->game;
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('gameClass', fn (Builder $q) => $q->where('game_id', $gameId));
    }
}
