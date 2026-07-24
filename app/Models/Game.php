<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Root of the game-reference-data isolation chain — every other table in this schema
 * (classes, patches, spells, talent trees, pvp talents, ...) traces back to a Game via FK,
 * directly or through a parent relation, so no reference-data query can silently span games.
 */
class Game extends Model
{
    protected $fillable = [
        'slug',
        'name',
    ];

    public function patches()
    {
        return $this->hasMany(Patch::class);
    }

    public function currentPatch()
    {
        return $this->hasOne(Patch::class)->where('is_current', true);
    }

    public function classes()
    {
        return $this->hasMany(GameClass::class);
    }
}
