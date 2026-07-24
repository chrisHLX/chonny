<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TalentBuildChoice extends Model
{
    protected $table = 'talent_build_choices';

    protected $fillable = [
        'talent_build_id',
        'talent_node_id',
        'chosen_entry_id',
        'rank',
    ];

    public function talentBuild()
    {
        return $this->belongsTo(TalentBuild::class);
    }

    public function talentNode()
    {
        return $this->belongsTo(TalentNode::class);
    }

    public function chosenEntry()
    {
        return $this->belongsTo(TalentNodeEntry::class, 'chosen_entry_id');
    }

    public function game(): ?Game
    {
        return $this->talentBuild?->game();
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('talentBuild', fn (Builder $q) => $q->inGame($gameId));
    }
}
