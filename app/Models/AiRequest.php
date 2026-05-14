<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    //
    protected $fillable = [
        'user_id',
        'purpose',
        'prompt',
        'template_prompt',
        'response',
        'duration_ms',
        'metadata',
    ];

    protected $casts = [
        'response' => 'array',
        'metadata' => 'array',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
