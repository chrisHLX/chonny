<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One bracket's rating as a sync saw it, written only when it changed (BattlenetCharacterSyncService). */
class PlayerRatingSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['battlenet_character_id', 'bracket', 'season', 'rating', 'won', 'lost', 'recorded_at'];

    protected $casts = ['recorded_at' => 'datetime'];

    public function character()
    {
        return $this->belongsTo(BattlenetCharacter::class, 'battlenet_character_id');
    }
}
