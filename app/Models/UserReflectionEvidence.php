<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserReflectionEvidence extends Model
{
    protected $table = 'user_reflection_evidence';

    protected $fillable = [
        'reflection_id',
        'concept_id',
        'signal',
        'interpretation',
        'confidence',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
    ];

    public function reflection(): BelongsTo
    {
        return $this->belongsTo(UserNextStepReflection::class, 'reflection_id');
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
