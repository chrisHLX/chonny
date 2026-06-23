<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTraitEvidence extends Model
{
    protected $fillable = [
        'user_id',
        'module_id',
        'question_id',
        'trait_id',
        'selected_answer',
        'selected_option_index',
        'points',
        'answered_at',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    public function trait()
    {
        return $this->belongsTo(PlayerTrait::class, 'trait_id');
    }
}
