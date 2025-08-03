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
        'response',
        'metadata',
    ];

    protected $casts = [
        'response' => 'array',
        'metadata' => 'array',
    ];

}
