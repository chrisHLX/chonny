<?php
// Concept.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;

class Concept extends Model
{
    use HasFactory; 

    protected $fillable = ['subject_id', 'name', 'description'];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    protected $appends = ['mastery_for_user'];

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
        return $this->userConceptMasteries->first()?->mastery_percentage ?? 0;
    }


    public function userConceptMasteries()
    {
        return $this->hasMany(UserConceptMastery::class);
    }

    public function axes()
    {
        return $this->belongsToMany(Axis::class, 'concept_axis')->withPivot('weight');
    }

}
