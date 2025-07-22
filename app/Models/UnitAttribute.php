<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'attribute_name',
        'value',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
