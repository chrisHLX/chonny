<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Counter extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'counter_unit_id',
        'effectiveness',
        'notes',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function counterUnit()
    {
        return $this->belongsTo(Unit::class, 'counter_unit_id');
    }
}
