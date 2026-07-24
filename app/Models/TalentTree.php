<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TalentTree extends Model
{
    protected $fillable = [
        'patch_id',
        'class_id',
        'spec_id',
        'type',
        'name',
        'external_tree_id',
    ];

    public function patch()
    {
        return $this->belongsTo(Patch::class);
    }

    public function gameClass()
    {
        return $this->belongsTo(GameClass::class, 'class_id');
    }

    public function specialization()
    {
        return $this->belongsTo(Specialization::class, 'spec_id');
    }

    public function nodes()
    {
        return $this->hasMany(TalentNode::class);
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
