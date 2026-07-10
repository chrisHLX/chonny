<?php

namespace App\Livewire;

use Livewire\Component;
use App\Events\ModuleAttempted;
use App\Models\Module;
use App\Models\User;
use App\Models\UserModuleHistory;

use App\Http\Services\MasteryService;
use App\Http\Services\UserModuleService;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class QuizRunner extends Component
{
    public bool $guestMode = false;

    public $moduleId;
    public $questions;
    // Shuffled option/step/value order per question ID, e.g. ['options' => [...]] — kept as a
    // plain array (not baked into a cloned Question model's `answer` attribute) because Livewire
    // re-fetches fresh Eloquent models from the DB on every request when hydrating a Collection
    // property, silently discarding any in-memory-only attribute mutation on a clone. Plain
    // arrays don't have that problem.
    public array $shuffledOptions = [];
    public $currentIndex = 0;
    public $answer = [];
    public $elapsed = 0;
    public $questionTimes = [];
    public $completed = false;
    public $quizFullyComplete = false;
    public $started = true;
    public $status;

    public $score = 0;
    public $proficiency;
    public $attemptNumber = 0;
    public $difficulty;
    public $questionResults = [];
    public $allQuestionResults = []; // accumulates across rounds; used for final guest score
    public $wrongQuestions = [];
    public $completedDifficulties = [];

    // Review / AI related
    public $contents = [];
    public $consecutiveFails = [];
    public $review_contents = [];
    public $hasMissingContent = false;
    public $userCredits = null;
    public $feedback;
    public ?string $retakeStartedAt = null;

    // Completion screen data
    public $completionStats = [];

    public function mount($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->guestMode = auth()->guest();
        $this->startQuizInternal();
    }

    public function startQuizInternal()
    {
        if ($this->guestMode) {
            $module = Module::with(['questions', 'proficiencies'])->find($this->moduleId);
            if (!$module) return;

            $this->proficiency = $module->proficiencies()->first()->name ?? '—';
            $result = $this->calculateNextDifficulty($module);

            if ($result['mode'] === 'completed') {
                $this->completeModule();
                return;
            }

            if ($result['mode'] === 'normal') {
                $allDifficultyQuestions = $this->getQuestionIdsForDifficulty($module, $result['level']);
                $answeredIds = array_keys($this->questionResults);
                $unanswered = array_values(array_diff($allDifficultyQuestions, $answeredIds));
                $selectedQuestions = $this->chooseQuestions($module, $unanswered, $result['level']);
                $this->questions = $this->prepareQuestionsForQuiz($selectedQuestions);
                $this->initializeQuizState($result['level']);
            }
            return;
        }

        $user = auth()->user();
        $this->userCredits = $user->credits()->firstOrCreate([]);

        if ($this->userCredits->ai_credits <= 5) {
            $this->feedback = "You've reached your alpha credit limit. Submit feedback or contact Christian if you'd like more credits while testing.";
            return;
        }

        $module = $user->modules()->with('questions')->find($this->moduleId);
        if (!$module) return;

        // Load once here; all private methods read $this->retakeStartedAt directly
        $retakeAt = $module->pivot->retake_started_at;
        $this->retakeStartedAt = $retakeAt
            ? \Carbon\Carbon::parse($retakeAt)->toDateTimeString()
            : null;

        $this->proficiency = $module->proficiencies()->first()->name ?? '—';
        $this->status = $module->pivot->status ?? 'in_progress';

        $result = $this->calculateNextDifficulty($module);

        if ($result['mode'] === 'completed') {
            // Module was already marked completed in the DB before this request — just render
            // the screen without re-running the pipeline or writing new history records.
            if ($module->pivot->status === 'completed') {
                $this->completed         = true;
                $this->quizFullyComplete = true;
                $this->difficulty        = 'final';
                $this->questions         = $this->questions ?? collect();
                $this->wrongQuestions    = collect();
                $this->status            = 'completed';

                $userScore = $this->userScore($this->moduleId);
                $this->buildCompletionStats($user, $this->moduleId, $userScore);
                return;
            }
            $this->completeModule();
            return;
        }

        if ($result['mode'] === 'normal') {
            $allDifficultyQuestions = $this->getQuestionIdsForDifficulty($module, $result['level']);
            $questionsToPractice   = $this->getTargetQuestions($allDifficultyQuestions, $user);
            $selectedQuestions     = $this->chooseQuestions($module, $questionsToPractice, $result['level']);
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

    public function retake(): void
    {
        if ($this->guestMode) {
            $this->completed             = false;
            $this->quizFullyComplete     = false;
            $this->completedDifficulties = [];
            $this->questionResults       = [];
            $this->allQuestionResults    = [];
            $this->wrongQuestions        = [];
            $this->score                 = 0;
            $this->currentIndex          = 0;
            $this->questions             = collect();
            $this->completionStats       = [];
            $this->feedback              = null;

            $this->startQuizInternal();
            return;
        }

        $user = auth()->user();
        if (!$user) return;

        $user->modules()->updateExistingPivot($this->moduleId, [
            'retake_started_at' => now(),
            'retake_count'      => DB::raw('retake_count + 1'),
            'status'            => 'in_progress',
            'completed_at'      => null,
        ]);

        $this->completed             = false;
        $this->quizFullyComplete     = false;
        $this->completedDifficulties = [];
        $this->questionResults       = [];
        $this->wrongQuestions        = [];
        $this->score                 = 0;
        $this->currentIndex          = 0;
        $this->questions             = collect();
        $this->completionStats       = [];
        $this->feedback              = null;

        $this->startQuizInternal();
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

    /**
     * Flag a question as personally important while taking a quiz — no interruption to
     * answering, purely a personal bookmark surfaced later on the Progress page's "Your
     * Library" section. Guests have no account to persist a flag against (a deliberate v1
     * scope cut, not a bug).
     */
    public function toggleFlag($questionId): void
    {
        if ($this->guestMode) {
            return;
        }

        auth()->user()->flaggedQuestions()->toggle($questionId);
    }

    public function getFlaggedQuestionIdsProperty()
    {
        return $this->guestMode ? collect() : auth()->user()->flaggedQuestions()->pluck('questions.id');
    }

    private function hasAnswer($question): bool
    {
        if ($question->type === 'matching_pairs') {
            $pairKeys = $question->answer['pairs']['keys'] ?? [];
            if (empty($pairKeys)) return false;

            foreach ($pairKeys as $key) {
                if (empty($this->answer[$key] ?? null)) return false;
            }

            return true;
        }

        // mcq / true_false / open all store a plain scalar once answered — the
        // unanswered default is the empty array $answer starts as (see property decl).
        if (is_array($this->answer)) return false;

        return trim((string) $this->answer) !== '';
    }

    public function submit($params = [])
    {
        // Guards against a duplicate/stale request (e.g. double-click on the last
        // question) landing after currentIndex has already advanced past the end.
        if (!isset($this->questions[$this->currentIndex])) {
            return;
        }

        $question = $this->questions[$this->currentIndex];

        // Guard against submitting a blank answer — mirrors the client-side `required`
        // validation, but also covers a direct Livewire call that bypasses the DOM
        // (stale request, JS disabled, etc). Ordering is exempt: SortableJS's x-init
        // always populates $answer with the current on-screen order before any drag
        // happens, so it never legitimately arrives here empty (see the ordering-question
        // note in CLAUDE.md — don't touch that flow from here).
        if ($question->type !== 'ordering' && !$this->hasAnswer($question)) {
            return;
        }

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

            case 'matching_pairs':
                $correctPairs = $question->answer['correct'] ?? [];
                $userPairs = $this->answer ?? [];

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
        $this->allQuestionResults[$question->id] = $correct;

        if ($correct) $this->score++;

        $this->questionTimes[] = $this->elapsed;
        \Log::info("Estimated time: {$this->elapsed}s");

        // Track in pivot (answered_questions) updating user stats
        if (auth()->check()) {
            $user = auth()->user();
            $existing = $user->answeredQuestions()->where('question_id', $question->id)->first();

            if ($existing) {

                // On a retake, don't carry the stale fail streak from the previous attempt
                $priorFails = ($this->retakeStartedAt && $existing->pivot->last_answered_at < $this->retakeStartedAt)
                    ? 0
                    : ($existing->pivot->consecutive_fails ?? 0);

                $newConsecutiveFails = $correct ? 0 : $priorFails + 1;
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

            \App\Http\Services\MasteryService::updateMasteryForUserQuestions($user, $question);
        }

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

        if ($this->currentIndex < $this->questions->count()) {
            $this->shuffleCurrentQuestionAnswers();
            return;
        }

        // Mark current difficulty as done for this session
        $this->completedDifficulties[] = $this->difficulty;

        if ($this->guestMode) {
            $module = Module::with('questions')->find($this->moduleId);
            if ($this->calculateNextDifficulty($module)['mode'] === 'completed') {
                $this->completeModule();
                return;
            }
            $this->completed = true;
            $wrongIds = array_keys(array_filter($this->questionResults, fn($c) => !$c));
            $this->wrongQuestions = $this->questions->whereIn('id', $wrongIds)->values();
            return;
        }

        $user = auth()->user();
        $moduleId = $this->moduleId;
        $userId = $user->id;

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

    private function calculateNextDifficulty($module)
    {
        $difficulties = ['easy', 'medium', 'hard'];

        if ($this->guestMode) {
            foreach ($difficulties as $level) {
                if (in_array($level, $this->completedDifficulties)) continue;

                $allIds = $module->questions()
                    ->where('difficulty', $level)
                    ->pluck('questions.id')
                    ->toArray();

                if (empty($allIds)) continue;

                $roundSize = min(5, count($allIds));
                $answeredCount = count(array_intersect(array_keys($this->questionResults), $allIds));

                if ($answeredCount >= $roundSize) continue;

                return ['mode' => 'normal', 'level' => $level];
            }
            return ['mode' => 'completed'];
        }

        $user = auth()->user();

        foreach ($difficulties as $level) {
            // Skip if already played this session
            if (in_array($level, $this->completedDifficulties)) {
                continue;
            }

            $allIds = $module->questions()
                ->where('difficulty', $level)
                ->pluck('questions.id')
                ->toArray();

            if (empty($allIds)) continue;

            $roundSize = min(5, count($allIds));

            $answeredCount = $user->answeredQuestions()
                ->whereIn('questions.id', $allIds)
                ->when($this->retakeStartedAt, fn ($q) => $q->where('question_user.last_answered_at', '>=', $this->retakeStartedAt))
                ->count();

            // If user has completed a full round at this level, move on
            if ($answeredCount >= $roundSize) continue;

            return ['mode' => 'normal', 'level' => $level];
        }

        return ['mode' => 'completed'];
    }

    private function completeModule()
    {
        $this->completed = true;
        $this->quizFullyComplete = true;
        $this->difficulty = 'final';
        $this->questions = $this->questions ?? collect();
        $this->wrongQuestions = collect();
        $this->status = 'completed';

        if ($this->guestMode) {
            $score = $this->guestScore();
            session(['guest_quiz_results.' . $this->moduleId => [
                'module_id'        => $this->moduleId,
                'score'            => $score,
                'question_results' => $this->questionResults,
                'question_times'   => $this->questionTimes,
                'completed_at'     => now()->toIso8601String(),
            ]]);
            $this->buildGuestCompletionStats($score);
            return;
        }

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

        // On a retake we skip the completion bonus — that ran on first completion
        if (!$this->retakeStartedAt) {
            $creditService = app(\App\Http\Services\CreditService::class);
            $creditService->rewardLearnedCredits($userId, 50, 'Guide completed');
            if (Module::find($moduleId)->parent_id) {
                $creditService->rewardLearnedCredits($userId, 25, 'Recommended guide bonus');
            }
        }

        $this->buildCompletionStats($user, $moduleId, $userScore);
    }

    private function getQuestionIdsForDifficulty($module, $difficulty)
    {
        return $module->questions()
            ->where('difficulty', $difficulty)
            ->pluck('questions.id')
            ->toArray();
    }

    /**
     * Prepare a collection of questions for the quiz: shuffle answers/options depending on type.
     * Writes into $this->shuffledOptions (plain array) rather than mutating the question models
     * themselves — see the property doc comment for why. Returns $questions unchanged.
     */
    private function prepareQuestionsForQuiz($questions)
    {
        foreach ($questions as $q) {
            $answer = $q->answer;

            if ($q->type === 'mcq' && is_array($answer['options'] ?? null)) {
                $options = $answer['options'];
                shuffle($options);
                $this->shuffledOptions[$q->id]['options'] = $options;
            }

            if ($q->type === 'ordering' && is_array($answer['steps'] ?? null)) {
                $steps = $answer['steps'];
                shuffle($steps);
                $this->shuffledOptions[$q->id]['steps'] = $steps;
            }

            if ($q->type === 'matching_pairs' && is_array($answer['pairs']['values'] ?? null)) {
                $values = $answer['pairs']['values'];
                shuffle($values);
                $this->shuffledOptions[$q->id]['values'] = $values;
            }
        }

        return $questions;
    }


    private function getTargetQuestions(array $moduleQuestionIds, $user)
    {
        \Log::info("Questions for difficulty:", $moduleQuestionIds);

        $answered = $user->answeredQuestions()
            ->whereIn('questions.id', $moduleQuestionIds)
            ->when($this->retakeStartedAt, fn ($q) => $q->where('question_user.last_answered_at', '>=', $this->retakeStartedAt))
            ->pluck('questions.id')
            ->toArray();

        $unanswered = array_values(array_diff($moduleQuestionIds, $answered));
        \Log::info("Unanswered questions:", $unanswered);
        return $unanswered;
    }

    private function chooseQuestions($module, array $targetQuestionIds, $difficulty)
    {
        $pool = !empty($targetQuestionIds)
            ? $module->questions()->whereIn('questions.id', $targetQuestionIds)->get()->shuffle()
            : $module->questions()->where('difficulty', $difficulty)->get()->shuffle();

        if ($pool->count() <= 5) {
            return $pool;
        }

        // Round-robin across skill_types, preferring unused question types within each slot
        $skillOrder = collect(['recall', 'analysis', 'application']);
        $bySkillType = $pool->groupBy('skill_type')->map(fn($g) => $g->values());
        $activeSkills = $skillOrder->filter(fn($s) => $bySkillType->has($s))->values();

        $selected = collect();
        $usedQuestionTypes = [];
        $index = 0;

        while ($selected->count() < 5) {
            $remaining = $activeSkills->filter(fn($s) => $bySkillType->get($s, collect())->isNotEmpty());
            if ($remaining->isEmpty()) break;

            $skill = $activeSkills[$index % $activeSkills->count()];
            $index++;

            $group = $bySkillType->get($skill, collect());
            if ($group->isEmpty()) continue;

            $pick = $group->first(fn($q) => !in_array($q->type, $usedQuestionTypes))
                ?? $group->first();

            $selected->push($pick);
            $usedQuestionTypes[] = $pick->type;
            $bySkillType[$skill] = $group->reject(fn($q) => $q->id === $pick->id)->values();
        }

        return $selected;
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

        $this->prepareQuestionsForQuiz(collect([$question]));
    }

    // ... move ALL other methods from your original TimedQuiz here ...
    // calculateNextDifficulty, prepareQuestionsForQuiz, getLeastAccurateQuestions,
    // userScore, shuffleCurrentQuestionAnswers, initializeQuizState, etc.

    private function buildCompletionStats(User $user, int $moduleId, float $userScore): void
    {
        $module = Module::find($moduleId);
        if (!$module) return;

        $stats = app(UserModuleService::class)->buildModuleUserStats($user, $module);

        $questionStats = collect($stats['question_stats']);

        // Concepts from answered questions where the user did NOT struggle
        $strongConcepts = $questionStats
            ->filter(fn($q) => $q['attempts'] > 0 && !$q['struggled'])
            ->flatMap(fn($q) => $q['concepts'] ?? [])
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(4)
            ->values()
            ->toArray();

        $this->completionStats = [
            'module_name'     => $stats['module']['name'],
            'module_slug'     => $module->slug,
            'score_percent'   => $userScore,
            'questions_count' => $stats['module']['num_questions'],
            'total_time'      => $this->totalTime,
            'strong_concepts' => $strongConcepts,
            'weak_concepts'   => array_slice($stats['patterns']['struggled_concepts'], 0, 4),
            'struggled_types' => $stats['patterns']['struggled_types'],
        ];
    }

    private function guestScore(): float
    {
        $total = count($this->allQuestionResults);
        if ($total === 0) return 0.0;
        $correct = count(array_filter($this->allQuestionResults));
        return round(($correct / $total) * 100, 2);
    }

    private function buildGuestCompletionStats(float $score): void
    {
        $module = Module::with(['questions.concepts'])->find($this->moduleId);
        if (!$module) return;

        $rightIds = array_keys(array_filter($this->allQuestionResults));
        $wrongIds  = array_keys(array_filter($this->allQuestionResults, fn($c) => !$c));

        $strongConcepts = $module->questions()
            ->whereIn('questions.id', $rightIds)
            ->with('concepts')
            ->get()
            ->flatMap(fn($q) => $q->concepts->pluck('name'))
            ->countBy()->sortDesc()->keys()->take(4)->values()->toArray();

        $weakConcepts = $module->questions()
            ->whereIn('questions.id', $wrongIds)
            ->with('concepts')
            ->get()
            ->flatMap(fn($q) => $q->concepts->pluck('name'))
            ->countBy()->sortDesc()->keys()->take(4)->values()->toArray();

        $this->completionStats = [
            'module_name'     => $module->name,
            'module_slug'     => $module->slug,
            'score_percent'   => $score,
            'questions_count' => count($this->questionResults),
            'total_time'      => $this->totalTime,
            'strong_concepts' => $strongConcepts,
            'weak_concepts'   => $weakConcepts,
            'struggled_types' => [],
        ];
    }

    public function render()
    {
        return view('livewire.quiz-runner');
    }
}
