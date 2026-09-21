<?php

namespace App\Listeners;

use App\Models\User;
use App\Quiz\QuizService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Carries quizzes taken as a guest onto the account on sign-in. Listens to Login rather than
 * Registered because every path — email sign-up, email log-in, Google, Battle.net — calls
 * Auth::login(), and a guest who already has an account should keep their scores too.
 */
class ClaimGuestQuizAttempts
{
    public function __construct(private QuizService $quizzes) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            $this->quizzes->claimGuestAttempts($event->user);
        } catch (\Throwable $e) {
            // Losing a guest's quiz scores is bad; failing their sign-in over it is worse.
            Log::warning('Guest quiz attempts not claimed: '.$e->getMessage(), ['user_id' => $event->user->id]);
        }
    }
}
