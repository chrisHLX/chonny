<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'race',
        'type',
        'description',
    ];

    public function attributes()
    {
        return $this->hasMany(UnitAttribute::class);
    }

    public function abilities()
    {
        return $this->hasMany(Ability::class);
    }

    public function counters()
    {
        return $this->hasMany(Counter::class);
    }

    public function countersThisUnit()
    {
        return $this->hasMany(Counter::class, 'counter_unit_id');
    }

    public function questions()
    {
        return $this->belongToMany(Question::class);
    }
}
