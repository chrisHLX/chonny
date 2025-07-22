<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class concept extends Model
{
    //
    use HasFactory; 

    protected $fillable = [
        'name',
        'type',
        'description',
    ];

    // Concept.php
    public function units()
    {
        return $this->belongsToMany(Unit::class)->withTimestamps();
    }

    // Unit.php
    public function concepts()
    {
        return $this->belongsToMany(Concept::class)->withTimestamps();
    }
    // Question.php
    public function questions()
    {
        return $this->belongsToMany(Question::class);
    }

    // Add enum access
    public static function getTypeOptions(): array
    {
        return [
            'strategy',
            'tactic',
            'economic',
            'composition',
            'micro',
            'map control',
            'timing',
            'transition',
            'defensive',
            'harassment',
            'offensive',
            'scouting',
            'unit control',
            'resource management',
            'positioning',
            'build order',
            'macro',
            'adaptation',
            'psychological',
            'meta',
            'other'
        ];
    }


}
