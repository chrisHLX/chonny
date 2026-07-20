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
        'diagnostic_variant_of',
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

    public function contextOptions()
    {
        return $this->belongsToMany(SubjectContextOption::class, 'question_context_option');
    }

    /**
     * Context-specific diagnostic_mcq rewrites of this (root) question — see
     * DiagnosticQuizRunner::applyContextRouting(). Only meaningful when this question itself has
     * diagnostic_variant_of === null; a variant's own variants() is always empty.
     */
    public function variants()
    {
        return $this->hasMany(self::class, 'diagnostic_variant_of');
    }

    public function variantOf()
    {
        return $this->belongsTo(self::class, 'diagnostic_variant_of');
    }

}
