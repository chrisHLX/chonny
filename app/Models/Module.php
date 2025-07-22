<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    //

    protected $fillable = [
        'name',
        'description',
        'race',
        'difficulty',
        'published',
        'created_by',
    ];

    public function questions()
    {
        return $this->belongsToMany(Question::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
        ->withPivot(['status', 'score', 'current_difficulty', 'last_activity_at', 'completed_at'])
        ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}
