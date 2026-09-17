<?php

namespace App\Models;

use App\Quiz\QuizQuestion;
use Illuminate\Database\Eloquent\Model;

/** One run through a quiz level. See the create_quiz_attempts_table migration. */
class QuizAttempt extends Model
{
    /** The share of questions a player needs right for a level to count as passed. */
    public const PASS_RATIO = 0.75;

    protected $fillable = [
        'user_id', 'session_id', 'game', 'subject', 'level',
        'questions', 'answers', 'score', 'total', 'completed_at',
    ];

    protected $casts = [
        'questions' => 'array',
        'answers' => 'array',
        'level' => 'integer',
        'score' => 'integer',
        'total' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function question(int $index): ?QuizQuestion
    {
        $data = $this->questions[$index] ?? null;

        return $data ? QuizQuestion::fromArray($data) : null;
    }

    public function answerFor(int $index): ?string
    {
        return $this->answers[$index] ?? null;
    }

    public function passed(): bool
    {
        return $this->completed_at !== null && $this->total > 0 && $this->score / $this->total >= self::PASS_RATIO;
    }

    /** Signed-in players own their attempts; a guest owns one through their session. */
    public function belongsToViewer(?User $user, string $sessionId): bool
    {
        return $this->user_id !== null
            ? $user !== null && $user->id === $this->user_id
            : $this->session_id === $sessionId;
    }
}
