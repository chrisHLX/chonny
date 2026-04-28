<?php

namespace App\Models;

use App\Enums\SkillType;
use Illuminate\Database\Eloquent\Model;

class UserConceptSkillMastery extends Model
{
    protected $table = 'user_concept_skill_mastery';

    protected $fillable = [
        'user_id',
        'concept_id',
        'skill_type',
        'correct_count',
        'total_count',
        'mastery_percentage',
    ];

    protected $casts = [
        'skill_type' => SkillType::class,
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
