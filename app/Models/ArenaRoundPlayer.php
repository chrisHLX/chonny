<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One player in one uploaded round, as the round measured them (RoundPlayerIndexer). */
class ArenaRoundPlayer extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'played_at' => 'datetime',
        'healer' => 'bool',
        'won' => 'bool',
        'pvp_talents' => 'array',
    ];

    public function round()
    {
        return $this->belongsTo(ArenaRound::class, 'arena_round_id');
    }

    /** "Name-Realm-Region", as the combat log writes it. */
    public function logName(): string
    {
        return $this->name.'-'.$this->realm.'-'.strtoupper($this->region);
    }
}
