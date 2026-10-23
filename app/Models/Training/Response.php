<?php

namespace App\Models\Training;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An answer to one of a lesson's exercises. */
class Response extends Model
{
    public const CHOICE = ['case', 'transfer'];

    public const WRITTEN = ['interpretation', 'evidence', 'reflection'];

    protected $table = 'training_responses';

    protected $fillable = ['lesson_slug', 'exercise', 'choice', 'body', 'correct', 'attempt'];

    protected function casts(): array
    {
        return ['correct' => 'boolean', 'choice' => 'integer', 'attempt' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
