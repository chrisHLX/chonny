<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TalentNodeEdge extends Model
{
    protected $fillable = [
        'from_node_id',
        'to_node_id',
        'edge_type',
    ];

    public function fromNode()
    {
        return $this->belongsTo(TalentNode::class, 'from_node_id');
    }

    public function toNode()
    {
        return $this->belongsTo(TalentNode::class, 'to_node_id');
    }

    public function game(): ?Game
    {
        return $this->fromNode?->game();
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('fromNode', fn (Builder $q) => $q->inGame($gameId));
    }
}
