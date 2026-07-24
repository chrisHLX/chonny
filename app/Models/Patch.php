<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Patch extends Model
{
    protected $fillable = [
        'game_id',
        'build_version',
        'released_at',
        'is_current',
    ];

    protected $casts = [
        'released_at' => 'datetime',
        'is_current' => 'boolean',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function spells()
    {
        return $this->hasMany(Spell::class);
    }

    public function talentTrees()
    {
        return $this->hasMany(TalentTree::class);
    }

    public function pvpTalents()
    {
        return $this->hasMany(PvpTalent::class);
    }

    public function talentBuilds()
    {
        return $this->hasMany(TalentBuild::class);
    }

    /**
     * Atomically makes this patch the current one for its game, unsetting any previous current
     * patch first. Backs up the DB-level functional unique index (patches_one_current_per_game,
     * see the create_patches_table migration) rather than relying on it alone — that index only
     * rejects an invalid state, it doesn't perform the flip.
     */
    public function markCurrent(): void
    {
        DB::transaction(function () {
            static::where('game_id', $this->game_id)
                ->where('id', '!=', $this->id)
                ->update(['is_current' => false]);

            $this->update(['is_current' => true]);
        });
    }
}
