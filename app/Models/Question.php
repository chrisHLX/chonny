<?php

namespace App\Models;

use App\Enums\SkillType;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'question',
        'answer',
        'type',
        'difficulty',
        'skill_type',
        'concept_id',
        'created_by',
    ];

    protected $casts = [
        'answer'     => 'array',
        'skill_type' => SkillType::class,
    ];

    public function concepts()
    {
        return $this->belongsToMany(Concept::class);
    }

    public function contents()
    {
        return $this->belongsToMany(Content::class, 'content_question')->withTimestamps();
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
                'last_answer',
                'last_answer_correct',
                'consecutive_fails'
            ])
            ->withTimestamps();
    }

    public function flaggedByUsers()
    {
        return $this->belongsToMany(User::class, 'question_user_flags')->withTimestamps();
    }

}
