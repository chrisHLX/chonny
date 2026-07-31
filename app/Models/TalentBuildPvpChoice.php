<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A single PvP talent pick within a TalentBuild — the PvP-slot equivalent of
 * TalentBuildChoice (which covers PvE tree node picks only). PvP talents have no tree/node
 * structure of their own, just 4 flat slots, so this is a separate table rather than a
 * polymorphic extension of talent_build_choices.
 */
class TalentBuildPvpChoice extends Model
{
    protected $table = 'talent_build_pvp_choices';

    protected $fillable = [
        'talent_build_id',
        'slot',
        'pvp_talent_id',
    ];

    public function talentBuild()
    {
        return $this->belongsTo(TalentBuild::class);
    }

    public function pvpTalent()
    {
        return $this->belongsTo(PvpTalent::class);
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
