<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserConceptMastery extends Model
{
    protected $table = 'user_concept_mastery'; // 👈 Add this line

    protected $fillable = [
        'user_id',
        'concept_id',
        'mastery_percentage',
        'total_questions',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function concept()
    {
        return $this->belongsTo(Concept::class);
    }
}
