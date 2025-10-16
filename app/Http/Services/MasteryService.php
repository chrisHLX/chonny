<?php

namespace App\Http\Services;

use App\Models\User;
use App\Models\Question;
use App\Models\UserConceptMastery;
use Illuminate\Support\Collection;

class MasteryService
{
    public static function updateMasteryForUserQuestions(User $user, $questions)
    {
        // Single Question model
        if ($questions instanceof Question) {
            $questions = collect([$questions]);
        }

        // Collection already (could be models or IDs)
        if ($questions instanceof Collection) {
            $questions = $questions->map(fn($q) => $q instanceof Question ? $q : Question::find($q))->filter();
        }
        // Array cases
        elseif (is_array($questions)) {
            $first = reset($questions);

            // Case: keyed id => bool map (your questionResults)
            if (is_bool($first)) {
                $ids = array_keys($questions);
                $questions = Question::whereIn('id', $ids)->get();
            } else {
                // Case: array of IDs or mixed Question models/IDs
                $ids = array_map(fn($q) => $q instanceof Question ? $q->id : (int) $q, $questions);
                $questions = Question::whereIn('id', $ids)->get();
            }
        } else {
            // Unknown input — nothing to do
            return;
        }

        // Guard: empty collection -> nothing to do
        if ($questions->isEmpty()) {
            return;
        }

        foreach ($questions as $question) {
            self::updateMasteryForUserQuestion($user, $question);
        }
    }

    public static function updateMasteryForUserQuestion(User $user, Question $question)
    {
        foreach ($question->concepts as $concept) {
            $totalQuestions = $concept->questions()->count();

            // Calculates mastery based on questions the user has answered correctly
            $correctAnswers = $concept->questions()
                ->whereHas('users', function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                    ->where('question_user.last_answer_correct', true); // ✅ FIXED
                })
                ->count();

            $mastery = $totalQuestions > 0
                ? round(($correctAnswers / $totalQuestions) * 100)
                : 0;

            UserConceptMastery::updateOrCreate(
                ['user_id' => $user->id, 'concept_id' => $concept->id],
                [
                    'total_questions'    => $totalQuestions,
                    'mastery_percentage' => $mastery,
                ]
            );
        }
    }

}
