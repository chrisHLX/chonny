<?php

namespace App\Quiz;

use App\Models\QuizAttempt;
use App\Models\User;
use App\Quiz\Wow\WowQuiz;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts, answers and finishes quiz attempts for any game. A game plugs in through GameQuiz;
 * add it to GAMES and it gets attempts, grading and progress for free.
 */
class QuizService
{
    /** Questions per attempt. */
    public const QUESTIONS_PER_LEVEL = 8;

    public const GAMES = [
        'wow' => WowQuiz::class,
    ];

    public function game(string $game): GameQuiz
    {
        abort_unless(isset(self::GAMES[$game]), 404);

        return app(self::GAMES[$game]);
    }

    /**
     * Session key listing the session ids this browser has taken quizzes under as a guest. Kept in
     * the session's DATA, not derived from its id, because signing in changes the id (Laravel
     * migrates the session on login) while the data survives — see claimGuestAttempts().
     */
    public const GUEST_SESSIONS_KEY = 'quiz.guest_session_ids';

    /** Returns null when the game data can't make a quiz for this subject and level. */
    public function start(string $game, string $subject, int $level, ?User $user, string $sessionId): ?QuizAttempt
    {
        $questions = $this->game($game)->questions($subject, $level, self::QUESTIONS_PER_LEVEL);

        if ($questions === []) {
            return null;
        }

        if (! $user) {
            $known = session(self::GUEST_SESSIONS_KEY, []);
            if (! in_array($sessionId, $known, true)) {
                session()->put(self::GUEST_SESSIONS_KEY, [...$known, $sessionId]);
            }
        }

        return QuizAttempt::create([
            'user_id' => $user?->id,
            'session_id' => $user ? null : $sessionId,
            'game' => $game,
            'subject' => $subject,
            'level' => $level,
            'questions' => array_map(fn (QuizQuestion $q) => $q->toArray(), $questions),
            'answers' => [],
            'total' => count($questions),
        ]);
    }

    /** Records an answer once. Returns false if the question was already answered or the key isn't an option. */
    public function answer(QuizAttempt $attempt, int $index, string $key): bool
    {
        $question = $attempt->question($index);

        if ($question === null || $attempt->completed_at !== null || $attempt->answerFor($index) !== null || ! $question->hasOption($key)) {
            return false;
        }

        $answers = $attempt->answers ?? [];
        $answers[$index] = $key;

        $attempt->update([
            'answers' => $answers,
            'answered' => count($answers),
            'score' => $attempt->score + ($question->isCorrect($key) ? 1 : 0),
            'completed_at' => count($answers) >= $attempt->total ? now() : null,
        ]);

        return true;
    }

    /**
     * Move the quizzes this browser took as a guest onto the account that just signed in. Called on
     * every login (ClaimGuestQuizAttempts), so it covers email, Google and Battle.net alike.
     *
     * Without this a guest's scores stayed keyed to a session id that signing in replaces: they
     * vanished from the player's own progress and never counted on the leaderboard — the first
     * player to sign up straight from a quiz (2026-09-20, two perfect levels) lost both.
     *
     * Only ids from this session's own server-side data are claimed, so nobody can claim another
     * visitor's attempts by guessing a session id.
     *
     * @return int attempts claimed
     */
    public function claimGuestAttempts(User $user): int
    {
        $sessionIds = session()->pull(self::GUEST_SESSIONS_KEY, []);

        if (! is_array($sessionIds) || $sessionIds === []) {
            return 0;
        }

        return QuizAttempt::whereNull('user_id')
            ->whereIn('session_id', $sessionIds)
            ->update(['user_id' => $user->id, 'session_id' => null]);
    }

    /**
     * The best finished attempt per level, for every subject this player has finished a level of.
     *
     * @return array<string, array<int, QuizAttempt>> subject => level => best attempt
     */
    public function bestBySubject(string $game, ?User $user, string $sessionId): array
    {
        return $this->finishedAttempts($game, $user, $sessionId)
            ->groupBy('subject')
            ->map(fn (Collection $attempts) => $this->bestPerLevel($attempts))
            ->all();
    }

    /**
     * Signed-in players ranked by questions answered, most first. Guests are not ranked: their
     * attempts belong to a session, not a person.
     *
     * @return Collection<int, object{user: User, answered: int, correct: int}>
     */
    public function leaderboard(string $game, int $limit = 10): Collection
    {
        $rows = QuizAttempt::where('game', $game)
            ->whereNotNull('user_id')
            ->where('answered', '>', 0)
            ->groupBy('user_id')
            ->select('user_id', DB::raw('SUM(answered) as answered'), DB::raw('SUM(score) as correct'))
            ->orderByDesc('answered')
            ->orderByDesc('correct')
            ->limit($limit)
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return $rows
            ->filter(fn ($row) => $users->has($row->user_id))
            ->map(fn ($row) => (object) [
                'user' => $users[$row->user_id],
                'answered' => (int) $row->answered,
                'correct' => (int) $row->correct,
            ])
            ->values();
    }

    /**
     * The best finished attempt per level for this player and subject.
     *
     * @return array<int, QuizAttempt>
     */
    public function bestByLevel(string $game, string $subject, ?User $user, string $sessionId): array
    {
        return $this->bestPerLevel($this->finishedAttempts($game, $user, $sessionId)->where('subject', $subject));
    }

    /** @return Collection<int, QuizAttempt> */
    private function finishedAttempts(string $game, ?User $user, string $sessionId): Collection
    {
        return QuizAttempt::where('game', $game)
            ->whereNotNull('completed_at')
            ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->whereNull('user_id')->where('session_id', $sessionId))
            ->get(['id', 'user_id', 'session_id', 'game', 'subject', 'level', 'score', 'total', 'completed_at']);
    }

    /** @return array<int, QuizAttempt> level => best attempt */
    private function bestPerLevel(Collection $attempts): array
    {
        return $attempts->groupBy('level')
            ->map(fn (Collection $group) => $group->sortByDesc(fn (QuizAttempt $a) => $a->score / max(1, $a->total))->first())
            ->sortKeys()
            ->all();
    }
}
