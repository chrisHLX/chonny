<?php

namespace App\Models;

use App\Enums\GeneratedReason;
use App\Enums\NextStepStatus;
use App\Enums\StepType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserNextStep extends Model
{
    protected $table = 'user_next_steps';

    protected $fillable = [
        'user_id',
        'module_id',
        'subject_id',
        'insight_id',
        'concept_id',
        'previous_step_id',
        'step_type',
        'status',
        'generated_reason',
        'title',
        'instructions',
        'attempted_at',
        'completed_at',
    ];

    protected $casts = [
        'step_type'         => StepType::class,
        'status'            => NextStepStatus::class,
        'generated_reason'  => GeneratedReason::class,
        'attempted_at'      => 'datetime',
        'completed_at'      => 'datetime',
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

    public function insight(): BelongsTo
    {
        return $this->belongsTo(UserProfileInsight::class, 'insight_id');
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function previousStep(): BelongsTo
    {
        return $this->belongsTo(UserNextStep::class, 'previous_step_id');
    }

    public function nextStep(): HasOne
    {
        return $this->hasOne(UserNextStep::class, 'previous_step_id');
    }

    public function reflection(): HasOne
    {
        return $this->hasOne(UserNextStepReflection::class, 'next_step_id');
    }
}
