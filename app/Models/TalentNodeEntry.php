<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TalentNodeEntry extends Model
{
    protected $fillable = [
        'talent_node_id',
        'spell_id',
        'rank',
        'max_rank',
        'external_talent_id',
    ];

    public function talentNode()
    {
        return $this->belongsTo(TalentNode::class);
    }

    public function spell()
    {
        return $this->belongsTo(Spell::class);
    }

    public function game(): ?Game
    {
        return $this->talentNode?->game();
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('talentNode', fn (Builder $q) => $q->inGame($gameId));
    }
}
