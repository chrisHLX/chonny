<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TalentNode extends Model
{
    protected $fillable = [
        'talent_tree_id',
        'external_node_id',
        'type',
        'pos_x',
        'pos_y',
        'display_row',
        'display_col',
        'max_ranks',
    ];

    public function talentTree()
    {
        return $this->belongsTo(TalentTree::class);
    }

    public function entries()
    {
        return $this->hasMany(TalentNodeEntry::class);
    }

    /** Edges where this node is the prerequisite (points at what it unlocks). */
    public function outgoingEdges()
    {
        return $this->hasMany(TalentNodeEdge::class, 'from_node_id');
    }

    /** Edges where this node is the one being unlocked. */
    public function incomingEdges()
    {
        return $this->hasMany(TalentNodeEdge::class, 'to_node_id');
    }

    public function game(): ?Game
    {
        return $this->talentTree?->game();
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('talentTree', fn (Builder $q) => $q->inGame($gameId));
    }
}
