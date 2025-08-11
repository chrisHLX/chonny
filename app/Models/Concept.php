<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;

class Concept extends Model
{
    //
    use HasFactory; 

    protected $fillable = [
        'name',
        'description',
    ];

    protected $appends = ['mastery_for_user'];

    // Unit.php
    public function units()
    {
        return $this->belongsToMany(Unit::class)->withTimestamps();
    }

    // Concept.php
    public function concepts()
    {
        return $this->belongsToMany(Concept::class)->withTimestamps();
    }
    // Question.php
    public function questions()
    {
        return $this->belongsToMany(Question::class);
    }

    // Accessor: mastery_for_user
    public function getMasteryForUserAttribute()
    {
        $user = Auth::user();
        if (! $user) {
            return 0; // guest users have no mastery
        }

        // Load related questions with pivot data for this user
        $questions = $this->questions()->with(['users' => function ($q) use ($user) {
            $q->where('user_id', $user->id);
        }])->get();

        $totalQuestions = $questions->count();
        $correctAnswers = 0;

        foreach ($questions as $question) {
            $pivot = $question->users->first()?->pivot;
            if ($pivot && $pivot->correct_count > 0) {
                $correctAnswers++;
            }
        }

        return $totalQuestions > 0 
            ? round(($correctAnswers / $totalQuestions) * 100) 
            : 0;
    }
}
