<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The persisted, authenticated counterpart to RoadmapService::buildGuestRoadmap()'s ephemeral
 * guest preview — one row per milestone, in order, for a (user, subject) pair. Deliberately has
 * no status column: whether a stage is complete/next/future is always computed live (see
 * Collection::getLearningPathProperty()) from real data (module_user completion, in the future
 * possibly UserConceptMastery), the same "never store a redundant second source of truth"
 * discipline NextStepService::checkAndCompleteModuleStep() already follows for UserNextStep.
 */
class UserLearningPathStage extends Model
{
    protected $fillable = [
        'user_id',
        'subject_id',
        'insight_id',
        'order_index',
        'stage_key',
        'concept_id',
        'module_id',
        'title',
        'detail',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function insight()
    {
        return $this->belongsTo(UserProfileInsight::class, 'insight_id');
    }

    public function concept()
    {
        return $this->belongsTo(Concept::class);
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }
}
