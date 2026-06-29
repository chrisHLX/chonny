<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'description'];

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function axes()
    {
        return $this->hasMany(Axis::class);
    }

    public function archetypes()
    {
        return $this->belongsToMany(Archetype::class);
    }
}
