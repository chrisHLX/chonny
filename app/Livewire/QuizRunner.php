<?php

namespace App\Livewire;

use Livewire\Component;
use App\Events\ModuleAttempted;
use App\Models\Module;
use App\Models\Pipeline;
use App\Models\PipelineStep;
use App\Models\UserModuleHistory;

use App\Http\Services\MasteryService;

use App\Jobs\SuggestionJob;
use App\Jobs\GenerateCardJob;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class QuizRunner extends Component
{
    public $moduleId;
    public $questions;
    public $currentIndex = 0;
    public $answer = [];
    public $elapsed = 0;
    public $questionTimes = [];
    public $completed = false;
    public $started = true;
    public $status;

    public $score = 0;
    public $proficiency;
    public $attemptNumber = 0;
    public $difficulty;
    public $questionResults = [];
    public $wrongQuestions = [];

    // Review / AI related
    public $contents = [];
    public $consecutiveFails = [];
    public $review_contents = [];
    public $hasMissingContent = false;
    public $userCredits = null;
    public $feedback;

    public function mount($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->startQuizInternal();
    }

    public function startQuizInternal()
    {
        $user = auth()->user();
        $this->userCredits = $user->credits()->firstOrCreate([]);

        if ($this->userCredits->ai_credits <= 5) {
            $this->feedback = "Not enough AI credits. Please top up.";
            return;
        }

        $module = $user->modules()->with('questions')->find($this->moduleId);
        if (!$module) return;

        $this->proficiency = $module->proficiencies()->first()->name ?? '—';

        $result = $this->calculateNextDifficulty($module);
        $this->status = $module->pivot->status ?? 'in_progress';

        if ($result['mode'] === 'completed') {
            $this->handleMasteryCompletion($module);
            return;
        }

        if ($result['mode'] === 'normal') {
            $allDifficultyQuestions = $this->getQuestionIdsForDifficulty($module, $result['level']);
            $questionsToPractice   = $this->getTargetQuestions($allDifficultyQuestions, $user);
            $selectedQuestions     = $this->chooseQuestions($module, $questionsToPractice, $result['level'])->get();
            $this->questions       = $this->prepareQuestionsForQuiz($selectedQuestions);
            \Log::info("Starting quiz with difficulty {$result['level']} for module {$module->id}. Questions: " . implode(', ', $selectedQuestions->pluck('id')->toArray()));
            $this->initializeQuizState($result['level']);
            return;
        }

        if ($result['mode'] === 'consecutive_fails') {
            $this->feedback = "review needed";
            $formatted = collect($result['review_contents'])->map(fn($content, $qid) => [
                'question_id' => $qid,
                'review_content' => $content,
            ])->values();

            $this->contents = $formatted;
            return;
        }
    }

    public function startReviewQuiz()
    {
        $this->feedback = null;
        $this->questions = $this->prepareQuestionsForQuiz($this->consecutiveFails ?? collect());
        $this->initializeQuizState('review');
    }

    // ──────────────────────────────────────────────
    //  All the rest of your quiz logic goes here
    //  (submit, nextQuestion, calculateNextDifficulty, etc.)
    // ──────────────────────────────────────────────

    public function submit($params = [])
    {
        $question = $this->questions[$this->currentIndex];
        $correct = false;

        // Time parameters
        $this->elapsed = isset($params['elapsed']) ? (int) $params['elapsed'] : 0;

        // ✅ Correctness logic
        switch ($question->type) {
            case 'mcq':
                $correct = $this->answer === $question->answer['correct'];
                break;

            case 'true_false':
                $correct = filter_var($this->answer, FILTER_VALIDATE_BOOLEAN) === $question->answer['correct'];
                break;

            case 'open':
                $keywords = $question->answer['correct_keywords'] ?? [];
                $matched = collect($keywords)->filter(fn($k) => str_contains(strtolower($this->answer), strtolower($k)));
                $correct = $matched->count() >= ceil(count($keywords) / 2);
                break;

            //Suggested fix
            case 'matching_pairs':
                $correctPairs = $question->answer['correct'] ?? [];
                $userPairs = $this->answer ?? [];

                // Normalize: does each chosen value match the truth?
                $correct = collect($correctPairs)->every(
                    fn($expectedValue, $key) => isset($userPairs[$key]) && $userPairs[$key] === $expectedValue
                );
                break;
            

            case 'ordering':
                $correctOrder = $question->answer['steps'];
                $userOrder = $this->answer;

                if (is_string($userOrder)) {
                    $decoded = json_decode($userOrder, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $userOrder = $decoded;
                    }
                }

                if (!is_array($userOrder)) {
                    $userOrder = [];
                }

                $correct = $userOrder === $correctOrder;
                break;
        }

        // ✅ Save result
        $this->questionResults[$question->id] = $correct;

        if ($correct) $this->score++;

        $this->questionTimes[] = $this->elapsed;
        \Log::info("Estimated time: {$this->elapsed}s");

        // Track in pivot (answered_questions) updating user stats
        if (auth()->check()) {
            $user = auth()->user();
            $existing = $user->answeredQuestions()->where('question_id', $question->id)->first();
            
            if ($existing) {

                $newConsecutiveFails = $correct 
                ? 0 
                : ($existing->pivot->consecutive_fails ?? 0) + 1;
                \Log::info("What existing pivot fails is {$existing->pivot->consecutive_fails}");

                $user->answeredQuestions()->updateExistingPivot($question->id, [
                    'attempts' => $existing->pivot->attempts + 1,
                    'correct_count' => $existing->pivot->correct_count + ($correct ? 1 : 0),
                    'last_answered_at' => now(),
                    'last_time_spent' => $this->elapsed,
                    'total_time_spent' => $existing->pivot->total_time_spent + $this->elapsed,
                    'last_answer' => is_array($this->answer) ? json_encode($this->answer) : $this->answer,
                    'last_answer_correct' => $correct,
                    'consecutive_fails' => $newConsecutiveFails
                ]);
            } else {
                $user->answeredQuestions()->attach($question->id, [
                    'attempts' => 1,
                    'correct_count' => $correct ? 1 : 0,
                    'last_answered_at' => now(),
                    'last_time_spent' => $this->elapsed,
                    'total_time_spent' => $this->elapsed,
                    'last_answer' => is_array($this->answer) ? json_encode($this->answer) : $this->answer,
                    'last_answer_correct' => $correct,
                    'consecutive_fails' => $correct ? 0 : 1
                ]);
            }
        }
        \App\Http\Services\MasteryService::updateMasteryForUserQuestions($user, $question);

        $this->nextQuestion();
    }

    public function getTotalTimeProperty()
    {
        return array_sum($this->questionTimes);
    }

    public function nextQuestion()
    {
        $this->answer = [];
        $this->feedback = '';
        $this->elapsed = 0;
        $this->currentIndex++;

        $this->shuffleCurrentQuestionAnswers();

        if ($this->currentIndex < $this->questions->count()) {
            return;
        }

        $user = auth()->user();
        $moduleId = $this->moduleId;
        $userId = $user->id;

        // If this round just pushed the user to full mastery, complete immediately
        $module = $user->modules()->with('questions')->find($moduleId);
        if ($this->calculateNextDifficulty($module)['mode'] === 'completed') {
            $this->completeModule();
            return;
        }

        // Round ended but module not yet mastered
        $this->completed = true;
        $userScore = $this->userScore($moduleId);

        // ----------------------
        // 1) UPDATE MODULE PIVOT
        // ----------------------
        $user->modules()->syncWithoutDetaching([
            $moduleId => [
                'score' => $userScore,
                'status' => 'in_progress',
                'last_activity_at' => now(),
            ]
        ]);

        // ----------------------
        // 2) SAVE HISTORY
        // ----------------------
        $lastAttempt = UserModuleHistory::where('user_id', $userId)
            ->where('module_id', $moduleId)
            ->latest('created_at')
            ->first();

        $attemptNumber = $lastAttempt ? $lastAttempt->attempt_number + 1 : 1;
        $this->attemptNumber = $attemptNumber;

        $wrongQuestions = array_keys(array_filter(
            $this->questionResults,
            fn($correct) => !$correct
        ));

        $this->wrongQuestions = $this->questions
            ->whereIn('id', $wrongQuestions)
            ->values();

        $rightQuestions = array_keys(array_filter(
            $this->questionResults,
            fn($correct) => $correct
        ));

        $moduleVersion = Module::find($moduleId)->version ?? 'V1';

        $history = UserModuleHistory::create([
            'user_id' => $userId,
            'module_id' => $moduleId,
            'attempt_number' => $attemptNumber,
            'wrong_questions' => $wrongQuestions,
            'right_questions' => $rightQuestions,
            'module_version' => $moduleVersion,
            'status' => empty($wrongQuestions) ? 'completed' : 'failed',
        ]);

        ModuleAttempted::dispatch($history);
    }  
    
    // Resets the module
    public function nextLevel()
    {
        // Reset state and start over
        $this->startQuizInternal();
    }

    // Just works out what level the user is on. (lets use this for proficiency above intermediate?)
    private function calculateNextDifficulty($module)
    {   
        $difficulties = ['easy', 'medium', 'hard'];
        $user = auth()->user();


        foreach ($difficulties as $level) {
            // Grab all the questions at this difficulty starting at easy
            $questions = $module->questions()->where('difficulty', $level)->pluck('questions.id');
            if ($questions->isEmpty()) continue;

            // Count how many the user answered correctly
            $correctCount = $user->answeredQuestions()
                ->whereIn('questions.id', $questions)
                ->count();

            // Calculate the percentage
            $total = $questions->count();
            $percentage = $total ? ($correctCount / $total) * 100 : 0;

            // If user hasn't mastered the level keep grabbing questions until they have
            if ($percentage < 80) {
                return [
                    'mode' => 'normal',
                    'level' => $level
                ];
            }
        }

        // Once we have looped through all questions and the user has correctly answered them all the module === All mastered
        return ['mode' => 'completed'];
    }

private function handleMasteryCompletion($module)
    {
        $this->answer = [];
        $this->completeModule();
    }

    private function completeModule()
    {
        $this->completed = true;
        $this->difficulty = 'final';
        $this->questions = $this->questions ?? collect();
        $this->wrongQuestions = collect();
        $this->status = 'completed';

        $user = auth()->user();
        $moduleId = $this->moduleId;
        $userId = $user->id;

        $userScore = $this->userScore($moduleId);

        $user->modules()->syncWithoutDetaching([
            $moduleId => [
                'score' => $userScore,
                'status' => 'completed',
                'last_activity_at' => now(),
                'completed_at' => now(),
            ]
        ]);

        $lastAttempt = UserModuleHistory::where('user_id', $userId)
            ->where('module_id', $moduleId)
            ->latest('created_at')
            ->first();

        $attemptNumber = $lastAttempt ? $lastAttempt->attempt_number + 1 : 1;
        $this->attemptNumber = $attemptNumber;

        $moduleVersion = Module::find($moduleId)->version ?? 'V1';

        $history = UserModuleHistory::create([
            'user_id' => $userId,
            'module_id' => $moduleId,
            'attempt_number' => $attemptNumber,
            'wrong_questions' => [],
            'right_questions' => [],
            'module_version' => $moduleVersion,
            'status' => 'completed',
        ]);

        ModuleAttempted::dispatch($history);

        $pipeline = Pipeline::create([
            'user_id' => $userId,
            'module_id' => $moduleId,
            'type' => 'quiz_completion',
            'status' => 'running',
        ]);

        $suggestionStep = PipelineStep::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Generate Suggestions',
            'status' => 'pending',
        ]);

        $cardStep = PipelineStep::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Generate Card',
            'status' => 'pending',
        ]);

        SuggestionJob::dispatch($moduleId, $userId, $suggestionStep->id);

        GenerateCardJob::dispatch($userId, $moduleId, $cardStep->id)->afterCommit();

        session(['completion_pipeline_id' => $pipeline->id]);
    }
    
    private function getQuestionIdsForDifficulty($module, $difficulty)
    {
        return $module->questions()
            ->where('difficulty', $difficulty)
            ->pluck('questions.id')
            ->toArray();
    }

    /**
     * Prepare a collection of questions for the quiz: shuffle answers/options depending on type
     */
    private function prepareQuestionsForQuiz($questions)
    {
        return $questions->transform(function ($q) {
            $q = clone $q; // detach from original model
            $answer = array_merge([], $q->answer); // detach answer array
            if ($q->type === 'mcq') shuffle($answer['options']);
            if ($q->type === 'ordering') shuffle($answer['steps']);
            if ($q->type === 'matching_pairs') shuffle($answer['pairs']['values']);
            $q->answer = $answer;
            return $q;
        });
    }


    private function getTargetQuestions(array $moduleQuestionIds, $user)
    {
        \Log::info("Questions for difficulty:", $moduleQuestionIds);

        $answered = $user->answeredQuestions()
            ->whereIn('questions.id', $moduleQuestionIds)
            ->pluck('questions.id')
            ->toArray();

        $wrong = $user->answeredQuestions()
            ->whereIn('questions.id', $moduleQuestionIds)
            ->wherePivot('last_answer_correct', false)
            ->pluck('questions.id')
            ->toArray();

        $unanswered = array_diff($moduleQuestionIds, $answered);
        \Log::info("Answered questions:", $answered);
        \Log::info("Wrong questions:", $wrong);
        return array_unique(array_merge($wrong, $unanswered));
    }

    private function chooseQuestions($module, array $targetQuestionIds, $difficulty)
    {
        if (!empty($targetQuestionIds)) {
            return $module->questions()->whereIn('questions.id', $targetQuestionIds)->limit(5);
        }

        return $module->questions()->where('difficulty', $difficulty)->inRandomOrder()->limit(5);
    }



    // 🔄 Initialize or reset all quiz state variables for a new attempt, based on the selected difficulty
    private function initializeQuizState($difficulty)
    {
        $this->difficulty = $difficulty;
        $this->started = true;
        $this->completed = false;
        $this->score = 0;
        $this->elapsed = 0;
        $this->questionTimes = [];
        $this->currentIndex = 0;
        $this->questions = $this->questions ?? collect();
        $this->questionResults = [];
        \Log::info("questions after quiz state initialization" . $this->questions);
    }

    /**
     * Calculate the user's overall score for a given module.
     * Combines all past answers (across all difficulties) into a single percentage.
     */
    private function userScore($moduleId)
    {
        $user = auth()->user();
        $module = Module::find($moduleId); // ✅ convert ID → model

        if (!$module) {
            return 0; // Module not found
        }

        // 1️⃣ Get all question IDs that belong to this module
        $moduleQuestionIds = $module->questions()->pluck('questions.id')->toArray();

        if (empty($moduleQuestionIds)) {
            return 0; // Avoid division by zero
        }

        // 2️⃣ Count how many questions the user has answered correctly
        $rows = $user->answeredQuestions()
            ->whereIn('questions.id', $moduleQuestionIds)
            ->get();

        $totalAttempts = $rows->sum(fn ($q) => $q->pivot->attempts);
        $totalCorrect  = $rows->sum(fn ($q) => $q->pivot->correct_count);

        $percentage = $totalAttempts > 0
            ? ($totalCorrect / $totalAttempts) * 100
            : 0;


        return round($percentage, 2);
    }

    private function shuffleCurrentQuestionAnswers()
    {
        $question = $this->questions[$this->currentIndex] ?? null;
        if (!$question) return;

        $q = clone $question; // detach reference
        $answer = array_merge([], $q->answer); // detach array

        if ($q->type === 'mcq') shuffle($answer['options']);
        if ($q->type === 'ordering') shuffle($answer['steps']);
        if ($q->type === 'matching_pairs') shuffle($answer['pairs']['values']);

        $q->answer = $answer;
        $this->questions[$this->currentIndex] = $q; // replace with shuffled
    }

    // ... move ALL other methods from your original TimedQuiz here ...
    // calculateNextDifficulty, prepareQuestionsForQuiz, getLeastAccurateQuestions,
    // userScore, shuffleCurrentQuestionAnswers, initializeQuizState, etc.

    public function render()
    {
        return view('livewire.quiz-runner');
    }
}