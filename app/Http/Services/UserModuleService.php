<?php
namespace App\Http\Services;

use App\Models\User;
use App\Models\Module;

class UserModuleService
{
    /**
     * Build detailed stats for a user's performance in a specific module.
     * Used by QuizRunner::buildCompletionStats() to build the completion-screen
     * strengths/weaknesses lists.
     *
     * @param User $user
     * @param Module $module
     * @return array
     */
    public function buildModuleUserStats(User $user, Module $module)
    {
        // Load module with questions and concepts
        // This is loaded from the pivot table user_module_question which means it only works if the user is actually linked to the module!!! IMPORTANT
        $module = $user->modules()
            ->with(['questions.concepts', 'questions'])
            ->findOrFail($module->id);

        $questions = $module->questions;
        $questionIds = $questions->pluck('id')->toArray();

        // Get pivot stats for ONLY this module's questions
        $answered = $user->answeredQuestions()
            ->whereIn('questions.id', $questionIds)
            ->withPivot([
                'attempts',
                'correct_count',
                'last_answered_at',
                'total_time_spent',
                'last_time_spent',
                'last_answer',
                'last_answer_correct',
                'consecutive_fails'
            ])
            ->get();

        $totalQuestions = $questions->count();

        // QUESTION-LEVEL BREAKDOWN (with struggle detection)
        $questionStats = $answered->map(function ($q) {
            $attempts = (int) ($q->pivot->attempts ?? 0);
            $correctCount = (int) ($q->pivot->correct_count ?? 0);
            $consecutiveFails = (int) ($q->pivot->consecutive_fails ?? 0);

            // how many times they answered incorrectly overall
            $wrongTimes = max(0, $attempts - $correctCount);

            // did they ever get it correct at least once?
            $everCorrect = $correctCount > 0;

            // did they get it correct on the first try?
            $firstTryCorrect = ($attempts > 0 && $correctCount > 0 && $attempts === 1 && $correctCount === 1);

            // struggled = had one or more incorrect attempts OR had consecutive fails
            $struggled = $wrongTimes > 0 || $consecutiveFails > 0;

            return [
                'id' => $q->id,
                'text' => $q->question,
                'type' => $q->type ?? null,
                'difficulty' => $q->difficulty ?? null,
                'concepts' => $q->concepts?->pluck('name')->toArray() ?? [],
                'attempts' => $attempts,
                'correct_count' => $correctCount,
                'wrong_times' => $wrongTimes,
                'consecutive_fails' => $consecutiveFails,
                'total_time_spent' => $q->pivot->total_time_spent ?? 0,
                'last_time_spent' => $q->pivot->last_time_spent ?? 0,
                // final_correct may be true due to retries; keep it but don't use it for struggle detection
                'final_correct' => (bool) ($q->pivot->last_answer_correct ?? false),
                'ever_correct' => $everCorrect,
                'first_try_correct' => $firstTryCorrect,
                'struggled' => (bool) $struggled
            ];
        })->values()->toArray();

        // MODULE SUMMARY using struggle-aware metrics
        $answeredCollection = collect($questionStats);

        $numEverCorrect = $answeredCollection->where('ever_correct', true)->count();
        $numStruggled = $answeredCollection->where('struggled', true)->count();

        // total incorrect attempts across all questions (signal of effort/struggle)
        $totalWrongAttempts = $answeredCollection->sum('wrong_times');

        // average time per answered question (skip unanswered)
        $avgTime = $answeredCollection->avg('total_time_spent') ?? 0;
        $maxFails = $answeredCollection->max('consecutive_fails') ?? 0;

        // Decide how you want to compute score:
        // Option A: % of questions the user eventually got correct
        $scorePercent = $totalQuestions > 0 ? round(($numEverCorrect / $totalQuestions) * 100, 1) : 0;

        // Option B (alternative): score that penalises many wrong attempts:
        // $scorePercent = $totalQuestions > 0 ? round((($numEverCorrect / $totalQuestions) * 100) - min(30, $totalWrongAttempts), 1) : 0;

        $moduleSummary = [
            'id' => $module->id,
            'name' => $module->name,
            'subject' => $module->subject->name ?? null,
            'proficiency' => $module->proficiencies()->first()->name ?? null,
            'score_percent' => $scorePercent,
            'num_questions' => $totalQuestions,
            'num_ever_correct' => $numEverCorrect,
            'num_unanswered_or_never_correct' => $totalQuestions - $numEverCorrect,
            'num_struggled' => $numStruggled,
            'total_wrong_attempts' => $totalWrongAttempts,
            'average_time' => round($avgTime, 2),
            'max_consecutive_fails' => $maxFails
        ];

        // PATTERN ANALYSIS (struggle-focused)
        $struggledTypes = $answeredCollection
            ->where('struggled', true)
            ->groupBy('type')
            ->keys()
            ->values()
            ->toArray();

        $struggledConcepts = $answeredCollection
            ->where('struggled', true)
            ->flatMap(fn($q) => $q['concepts'] ?? [])
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(5)
            ->values()
            ->toArray();

        $longestTimeTopics = $answeredCollection
            ->sortByDesc('total_time_spent')
            ->take(3)
            ->flatMap(fn($q) => $q['concepts'] ?? [])
            ->unique()
            ->values()
            ->toArray();

        $overallMastery = $totalQuestions > 0
            ? round($numEverCorrect / max(1, $totalQuestions + ($maxFails * 0.5)), 2)
            : 0;

        $patterns = [
            'struggled_types' => $struggledTypes,
            'struggled_concepts' => $struggledConcepts,
            'longest_time_topics' => $longestTimeTopics,
            'total_wrong_attempts' => $totalWrongAttempts,
            'overall_mastery_score' => $overallMastery
        ];

        
        return [
            'module' => $moduleSummary,
            'question_stats' => $questionStats,
            'patterns' => $patterns
        ];
    }
}