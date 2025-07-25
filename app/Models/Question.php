<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'question',
        'answer',
        'type',
        'difficulty',
        'concept_id',
        'unit_id',
        'created_by',
    ];

    protected $casts = [
        'answer' => 'array',
    ];

    public function concepts()
    {
        return $this->belongsToMany(Concept::class);
    }

    public function units()
    {
        return $this->belongsToMany(Unit::class);
    }

    public function modules()
    {
        return $this->belongsToMany(Module::class)->withTimestamps();
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot([
                'attempts',
                'correct_count',
                'last_answered_at',
                'total_time_spent',
                'last_time_spent',
                'last_answer'
            ])
            ->withTimestamps();
    }


}
