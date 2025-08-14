<?php
// Concept.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;

class Concept extends Model
{
    use HasFactory; 

    protected $fillable = ['name', 'description'];

    protected $appends = ['mastery_for_user'];

    public function units()
    {
        return $this->belongsToMany(Unit::class)->withTimestamps();
    }

    public function concepts()
    {
        return $this->belongsToMany(Concept::class)->withTimestamps();
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class);
    }

    public function getMasteryForUserAttribute()
    {
        $user = Auth::user();
        if (! $user) {
            return 0;
        }

        // If users relationship is already loaded, no extra queries happen
        $questions = $this->questions->loadMissing([
            'users' => fn ($q) => $q->where('user_id', $user->id)
        ]);

        $totalQuestions = $questions->count();
        $correctAnswers = $questions->filter(function ($question) {
            $pivot = $question->users->first()?->pivot;
            return $pivot && $pivot->correct_count > 0;
        })->count();

        return $totalQuestions > 0 
            ? round(($correctAnswers / $totalQuestions) * 100) 
            : 0;
    }
}
