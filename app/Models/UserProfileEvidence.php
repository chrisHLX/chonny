<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfileEvidence extends Model
{
    protected $table = 'user_profile_evidence';

    protected $fillable = [
        'user_id',
        'module_id',
        'question_id',
        'category_id',
        'subject_id',
        'question_key',
        'answer_text',
        'answer_value',
        'metadata',
        'answered_at',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'answered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
