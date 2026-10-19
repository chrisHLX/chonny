<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A character's place on one of Blizzard's PvP leaderboards, as of the last daily pull (wow:pvp-leaderboards). */
class PvpLeaderboardEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['seen_at' => 'datetime'];
}
