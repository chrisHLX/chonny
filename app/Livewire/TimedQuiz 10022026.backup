<?php

namespace App\Livewire;

use Livewire\Component;

use App\Events\ModuleAttempted;
use App\Models\Module;
use App\Models\Pipeline;
use App\Models\PipelineStep;
use App\Models\Subject;
use App\Models\UserModuleHistory;
use App\Http\Services\AiService;
use App\Http\Services\ReviewQuestionService;
use App\Jobs\GenerateReviewContentJob;
use App\Jobs\SuggestionJob;
use App\Jobs\GenerateCardJob;


use Illuminate\Support\Facades\Cache;


class TimedQuiz extends Component
{
    public $modules;
    public $subjects = [];
    public $selectedModule;
    public $questions;
    public $currentIndex = 0;
    public $answer = [];
    public $feedback;
    public $elapsed = 0; 
    public $questionTimes = [];
    public $score = 0;
    public $completed = false;
    public $started = false;
    public $attemptNumber = 0; //default 
    public $difficulty; // easy, medium, hard, review
    public $contents = []; // For review contents
    // ✅ Track per-question correctness
    public $questionResults = [];
    public $consecutiveFails = [];
    public $review_contents = [];    
    public $hasMissingContent = false;
    public $userCredits = null;
    public $proficiency;
    public $status;

    // Trying filtering with context
    public $selectedSubject = null; // Track which subject is selected
    public $categrory;

    public function updating($name, $value)
    {
        \Log::info("Updating {$name}", ['new' => $value]);
    }

    public function updated($name, $value)
    {
        \Log::info("Updated {$name}", ['new' => $value]);
    }

    public function mount()
    {
        $this->category_id = request()->query('category_id'); // get ?category_id=3

        // Filter subjects by category if one is provided
        $this->subjects = Subject::when($this->category_id, function($query, $category_id) {
            $query->where('category_id', $category_id);
        })->get();

        // If only one subject exists, auto-select it
        if ($this->subjects->count() === 1) {
            $this->selectedSubject = $this->subjects->first()->id;
        }

        $this->updateModules();
    }

    /**
     * Filter modules based on selected subject
     */
    public function updatedSelectedSubject($value)
    {
        $this->updateModules();
    }

    protected function updateModules()
    {
        $subjectIds = $this->subjects->pluck('id');

        $query = auth()->user()->modules()
            ->with('proficiencies')
            ->whereIn('subject_id', $subjectIds);

        // If a subject is selected, narrow modules to that subject
        if ($this->selectedSubject) {
            $query->where('subject_id', $this->selectedSubject);
        }

        $this->modules = $query->get();
    }

    public function incrementElapsed()
    {
        $this->elapsed++;
    }

    public function startQuiz()
    {
        if (!$this->selectedModule) return;

        $user = auth()->user();
        $this->userCredits = $user->credits()->firstOrCreate([]); // Ensure record exists
        if ($this->userCredits->ai_credits <= 5) {
            dd('Not enough AI credits to start the quiz. Please top up your credits.');
        }
        $module = $user->modules()->with('questions')->find($this->selectedModule);
        $this->proficiency = $module->proficiencies()->first()->name;
        
        if (!$module) return;

        $result = $this->calculateNextDifficulty($module);
        $this->status = $module->pivot->status;
        
        // Handle edge case where module is mastered, provides review questions
        if ($result['mode'] === 'completed') {
            $this->handleMasteryCompletion($module);
            return;
        }

        // Handle Normla quiz selection
        if($result['mode'] === 'normal') {
            $allDifficultyQuestions = $this->getQuestionIdsForDifficulty($module, $result['level']);
            $questionsToPractice   = $this->getTargetQuestions($allDifficultyQuestions, $user);
            $selectedQuestions     = $this->chooseQuestions($module, $questionsToPractice, $result['level'])->get();
            $this->questions       = $this->prepareQuestionsForQuiz($selectedQuestions);
            \Log::info("Final Selected questions:", $this->questions->pluck('id')->toArray());
            $this->initializeQuizState($result['level']);
            return;
        }

        if ($result['mode'] === 'review') {
            $this->questions = $this->prepareQuestionsForQuiz($result['questions']);
            $this->initializeQuizState($result['level']);
            return;
        }

        if ($result['mode'] === 'consecutive_fails') {
            $this->feedback = "review needed";
            $formattedContents = [];
            foreach ($result['review_contents'] as $questionId => $content) {
                $formattedContents[] = [
                    'question_id' => $questionId,
                    'review_content' => $content,
                ];
            }

            $this->contents = $formattedContents;
            $this->started = true; 
            return;
        }
        
    }

    public function startReviewQuiz()
    {

        $this->feedback = NULL;
        $this->questions = $this->prepareQuestionsForQuiz($this->consecutiveFails ?? collect());
        $this->initializeQuizState('review');
        return;
    }

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

                if ($existing->pivot->consecutive_fails >= 1) {
                    GenerateReviewContentJob::dispatch($question->id, $this->selectedModule, $user->id);
                }

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

        // ======================
        // QUIZ COMPLETED
        // ======================

        $this->completed = true;

        $user = auth()->user();
        $moduleId = $this->selectedModule;
        $userId = $user->id;

        $userScore = $this->userScore($moduleId);

        $status = (
            $this->difficulty === 'final'
            && $this->currentIndex === $this->questions->count()
        ) ? 'completed' : 'in_progress';

        // ----------------------
        // 1) UPDATE MODULE PIVOT
        // ----------------------
        $user->modules()->syncWithoutDetaching([
            $moduleId => [
                'score' => $userScore,
                'status' => $status,
                'last_activity_at' => now(),
                'completed_at' => now(),
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

        // ----------------------
        // 3) DISPATCH JOBS (LAST)
        // ----------------------

        if ($status === 'completed') {

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

            SuggestionJob::dispatch(
                $moduleId,
                $userId,
                $suggestionStep->id
            );

            GenerateCardJob::dispatch(
                $userId,
                $moduleId,
                $cardStep->id
            )->afterCommit();

            // Store pipeline id for frontend
            session(['completion_pipeline_id' => $pipeline->id]);
        }

    }


    public function render()
    {
        return view('livewire.timed-quiz');
    }

    // Resets the module
    public function retryModule()
    {
        // Reset state and start over
        $this->startQuiz();
    }


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

    public function checkReviewContent()
    {
        $allReady = true;
            
        foreach ($this->consecutiveFails as $q) {
            $content = Cache::remember("review_content:{$q->id}", now()->addHours(2), function () use ($q) {
                // 1. Try relationship (DB)
                $contentModel = $q->contents()->first();

                if ($contentModel) {
                    return $contentModel->content; // ← assuming column is `content`
                }

                return null; // Default to null if not found
            });

            
            $formattedContents[] = [
                'question_id' => $q->id,
                'review_content' => $content,
            ];
            \Log::info("Polled review content for Question ID {$q->id}: " . ($content ?? 'not ready'));

            if (empty($content)) {
                $allReady = false;
            }
        }
        
        // Once all review contents are ready, stop polling
        if ($allReady) {
            $this->hasMissingContent = false;
            \Log::info('All review contents ready — stopping poll.');
            $this->contents = $formattedContents;
        }
    }



    private function handleMasteryCompletion($module)
    {
        $this->answer = []; // cast answer to array needed for livewire binding (otherwise matching pairs wont submit)
        $user = auth()->user();
        \Log::info("User has mastered this module and now we are trying to review the questions they got wrong.");

        // Get all question IDs in this module
        $moduleQuestionIds = $module->questions()->pluck('questions.id')->toArray();

        // Get IDs of all questions the user has already answered
        $answeredQuestionIds = $user->answeredQuestions()
            ->whereIn('questions.id', $moduleQuestionIds)
            ->pluck('questions.id')
            ->toArray();

        // Determine the unanswered questions
        $unansweredQuestionIds = array_diff($moduleQuestionIds, $answeredQuestionIds);

        if (empty($unansweredQuestionIds)) {
            // All questions answered — show least accurate questions for review
            \Log::info("User has answered all questions in this mastered module.");
            $leastAccurate = $this->getLeastAccurateQuestions($user, 5, $module);

            \Log::info('Least accurate questions:', $leastAccurate->pluck('id')->toArray());

            $this->questions = $this->prepareQuestionsForQuiz($leastAccurate);
            $this->initializeQuizState('final');
            return; // 🔹 Important: stop execution here so the "otherwise" block doesn't overwrite questions
        }

        // Otherwise, serve only the unanswered questions
        $questionsToServe = $module->questions()
            ->whereIn('questions.id', $unansweredQuestionIds)
            ->get();

        $this->questions = $this->prepareQuestionsForQuiz($questionsToServe);
        $this->initializeQuizState('review');
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


    private function getLeastAccurateQuestions($user, $limit = 3, $module = null)
    {
        $query = $user->answeredQuestions()
            ->withPivot([
                'attempts',
                'correct_count',
                'last_answer_correct',
                'total_time_spent',
                'last_answered_at'
            ]);

        // Filter by module if provided
        if ($module) {
            $query->whereHas('modules', fn($q) => 
                $q->where('modules.id', $module->id)
            );
        }

        $questions = $query->get()
            ->filter(fn($q) => $q->pivot->attempts > 0)
            ->map(function ($q) {
                $q->accuracy = $q->pivot->correct_count / $q->pivot->attempts;
                return $q;
            });

        // Now sort using a multi-metric system
        $sorted = $questions->sort(function ($a, $b) {
            // 1. Failed questions first
            if ($a->pivot->last_answer_correct !== $b->pivot->last_answer_correct) {
                return $a->pivot->last_answer_correct <=> $b->pivot->last_answer_correct;
            }

            // 2. Lower accuracy first
            if ($a->accuracy !== $b->accuracy) {
                return $a->accuracy <=> $b->accuracy;
            }

            // 3. Higher time spent first
            if ($a->pivot->total_time_spent !== $b->pivot->total_time_spent) {
                return $b->pivot->total_time_spent <=> $a->pivot->total_time_spent;
            }

            // 4. Older questions first
            if ($a->pivot->last_answered_at !== $b->pivot->last_answered_at) {
                return strtotime($a->pivot->last_answered_at) <=> strtotime($b->pivot->last_answered_at);
            }

            // 5. Finally random tie-breaker
            return rand(-1, 1);
        });

        return $sorted->take($limit)->values();
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


// --------------------------------- Module completion button --------------------------------- //

    protected function handleNextModule(UserModuleHistory $history)
    {
        $user = auth()->user();

        // Get the actual module instance (you were missing this line)
        $module = Module::find($this->selectedModule);
        if (!$module) {
            \Log::error("Module not found for ID {$this->selectedModule}");
            return;
        }

        // Resolve AI service
        $aiService = app(AiService::class);

        // 1️⃣ Get all question IDs that belong to this module
        $moduleQuestions = $module->questions()->pluck('questions.id')->toArray();

        // 2️⃣ Get all question IDs this user has answered
        $userQuestions = $user->answeredQuestions()->pluck('questions.id')->toArray();

        // 3️⃣ Determine if user has answered *all* module questions
        $unansweredQuestions = array_diff($moduleQuestions, $userQuestions);
        $hasCompletedAllQuestions = empty($unansweredQuestions);

        // 🔹 Case 1: Generate a revision module after 3 failed attempts
        if ($history->status === 'failed' && $history->attempt_number >= 3) {
            $newModule = $aiService->generateNewModule($module->id, $history->wrong_questions);
            $this->selectedModule = $newModule->id;
            \Log::info("revision done");
            return;
        }

        // 🔹 Case 2: Generate a harder module only if user completed and answered all questions
        if ($history->status === 'completed' && $hasCompletedAllQuestions) {
            $newModule = $aiService->generateHarderModule($module->id, $history->right_questions);
            \Log::info("harder done");
            return;
        }

        // 🔹 Case 3: User completed the quiz but not all questions in module
        if ($history->status === 'completed' && !$hasCompletedAllQuestions) {
            // Grab unanswered questions for this module
            $remainingQuestions = Question::whereIn('id', $unansweredQuestions)->get();
            // You can now use $remainingQuestions to show or assign them
            \Log::info("User still has unanswered questions", ['remaining' => $remainingQuestions->pluck('id')->toArray()]);
        }
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
        $correctCount = $user->answeredQuestions()
            ->whereIn('questions.id', $moduleQuestionIds)
            ->wherePivot('last_answer_correct', true)
            ->count();

        // 3️⃣ Calculate the percentage score
        $total = count($moduleQuestionIds);
        $percentage = ($correctCount / $total) * 100;

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






}
