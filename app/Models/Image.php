<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'ability_id',
        'type',
        'url',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function ability()
    {
        return $this->belongsTo(Ability::class);
    }
}
