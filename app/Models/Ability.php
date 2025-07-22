<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ability extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'name',
        'description',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
