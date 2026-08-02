<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TalentBuild extends Model
{
    protected $table = 'talent_builds';

    protected $fillable = [
        'spec_id',
        'patch_id',
        'user_id',
        'module_id',
        'name',
        'share_slug',
        'is_public',
        'is_default',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function specialization()
    {
        return $this->belongsTo(Specialization::class, 'spec_id');
    }

    public function patch()
    {
        return $this->belongsTo(Patch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function choices()
    {
        return $this->hasMany(TalentBuildChoice::class);
    }

    public function pvpChoices()
    {
        return $this->hasMany(TalentBuildPvpChoice::class);
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
