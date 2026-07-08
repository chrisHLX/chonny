<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserProfileInsight extends Model
{
    protected $table = 'user_profile_insights';

    protected $fillable = [
        'user_id',
        'module_id',
        'subject_id',
        'archetype_id',
        'profile_title',
        'confidence_level',
        'summary',
        'evidence',
        'self_report_check',
        'likely_in_game_pattern',
        'growth_area_pattern',
        'generated_at',
    ];

    protected $casts = [
        'evidence'           => 'array',
        'self_report_check'  => 'array',
        'generated_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    public function concepts(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'user_profile_insight_concepts', 'insight_id', 'concept_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function strengthConcepts(): BelongsToMany
    {
        return $this->concepts()->wherePivot('role', 'strength');
    }

    public function growthAreaConcepts(): BelongsToMany
    {
        return $this->concepts()->wherePivot('role', 'growth_area');
    }

    public function nextSteps(): HasMany
    {
        return $this->hasMany(UserNextStep::class, 'insight_id');
    }
}
