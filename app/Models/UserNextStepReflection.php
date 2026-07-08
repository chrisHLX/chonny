<?php

namespace App\Models;

use App\Enums\DidTry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserNextStepReflection extends Model
{
    protected $table = 'user_next_step_reflections';

    protected $fillable = [
        'next_step_id',
        'did_try',
        'how_it_went',
        'why_reasoning',
        'submitted_at',
    ];

    protected $casts = [
        'did_try'      => DidTry::class,
        'submitted_at' => 'datetime',
    ];

    public function nextStep(): BelongsTo
    {
        return $this->belongsTo(UserNextStep::class, 'next_step_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(UserReflectionEvidence::class, 'reflection_id');
    }
}
