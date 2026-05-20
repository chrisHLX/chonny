<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserModuleHistory extends Model
{
    //
    protected $fillable = [
        'user_id',
        'module_id',
        'attempt_number',
        'wrong_questions', // JSON array of question IDs answered incorrectly
        'right_questions', // JSON array of question IDs answered correctly
        'module_version', // e.g., 'V2', 'V3A'
        'status', // e.g., 'in_progress', 'completed'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    protected $casts = [
        'wrong_questions' => 'array',
        'right_questions' => 'array',
        'attempted_at' => 'datetime',
    ];
}
