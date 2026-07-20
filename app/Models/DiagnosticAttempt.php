<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiagnosticAttempt extends Model
{
    protected $fillable = [
        'module_id',
        'subject_id',
        'user_id',
        'session_id',
        'started_at',
        'completed_at',
        'last_question_index',
        'total_questions',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
