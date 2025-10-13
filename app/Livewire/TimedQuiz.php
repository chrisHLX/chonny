<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\UserModuleHistory;
use App\Events\ModuleAttempted;
use App\Models\Module;
use App\Http\Services\AiService;
use App\Http\Services\User;

class TimedQuiz extends Component
{
    public $modules;
    public $selectedModule;
    public $questions;
    public $currentIndex = 0;
    public $answer;
    public $feedback;
    public $elapsed = 0; 
    public $questionTimes = [];
    public $score = 0;
    public $completed = false;
    public $started = false;
    public $attemptNumber = 0; //default 
    public $difficulty; // easy, medium, hard, review
    
    // ✅ Track per-question correctness
    public $questionResults = [];
    
    public function mount()
    {
        $this->modules = auth()->user()->modules()->get();
        
    }

    public function incrementElapsed()
    {
        $this->elapsed++;
    }

    public function startQuiz()
    {
        if (!$this->selectedModule) return;

        $user = auth()->user();
        $module = $user->modules()->with('questions')->find($this->selectedModule);
        if (!$module) return;

        $difficulty = $this->calculateNextDifficulty($module);
        \Log::info("Next difficulty: " . ($difficulty ?? 'mastered'));

        // Handle edge case where module is mastered, provide review questions
        if (!$difficulty) {
            $this->handleMasteryCompletion($module);
            return;
        }

        // Select questions for this quiz
        $allDifficultyQuestions = $this->getQuestionIdsForDifficulty($module, $difficulty);
        $questionsToPractice   = $this->getTargetQuestions($allDifficultyQuestions, $user);
        $selectedQuestions     = $this->chooseQuestions($module, $questionsToPractice, $difficulty)->get();
        $this->answer = []; // cast answer to array needed for livewire binding
        $this->questions       = $this->prepareQuestionsForQuiz($selectedQuestions);

        $this->initializeQuizState($difficulty);
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

        $this->feedback = $correct 
            ? "✅ Correct! Time: {$this->elapsed}s" 
            : "❌ Incorrect. Time: {$this->elapsed}s";

        if ($correct) $this->score++;

        $this->questionTimes[] = $this->elapsed;
        \Log::info("Estimated time: {$this->elapsed}s");

        // Track in pivot (answered_questions) updating user stats
        if (auth()->check()) {
            $user = auth()->user();
            $existing = $user->answeredQuestions()->where('question_id', $question->id)->first();

            if ($existing) {
                $user->answeredQuestions()->updateExistingPivot($question->id, [
                    'attempts' => $existing->pivot->attempts + 1,
                    'correct_count' => $existing->pivot->correct_count + ($correct ? 1 : 0),
                    'last_answered_at' => now(),
                    'last_time_spent' => $this->elapsed,
                    'total_time_spent' => $existing->pivot->total_time_spent + $this->elapsed,
                    'last_answer' => is_array($this->answer) ? json_encode($this->answer) : $this->answer,
                    'last_answer_correct' => $correct
                ]);
            } else {
                $user->answeredQuestions()->attach($question->id, [
                    'attempts' => 1,
                    'correct_count' => $correct ? 1 : 0,
                    'last_answered_at' => now(),
                    'last_time_spent' => $this->elapsed,
                    'total_time_spent' => $this->elapsed,
                    'last_answer' => is_array($this->answer) ? json_encode($this->answer) : $this->answer,
                    'last_answer_correct' => $correct
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

        if ($this->currentIndex >= $this->questions->count()) {
            // Quiz completed
            $this->completed = true;

            $user = auth()->user();
            $moduleId = $this->selectedModule;
            // ✅ Calculate user's *overall* score for this module (including past and current)
            $userScore = $this->userScore($moduleId);

            // ✅ Update pivot for module progress
            if ($user && $moduleId) {
                $user->modules()->syncWithoutDetaching([
                    $moduleId => [
                        'score' => $userScore, // Use overall calculated score
                        'status' => 'completed',
                        'last_activity_at' => now(),
                        'completed_at' => now(),
                    ]
                ]);
            }


            // ✅ Save one UserModuleHistory record
            $lastAttempt = UserModuleHistory::where('user_id', $user->id)
                ->where('module_id', $moduleId)
                ->latest('created_at')
                ->first();
            //Work out the new attempt number
            $attemptNumber = $lastAttempt ? $lastAttempt->attempt_number + 1 : 1;
            $this->attemptNumber = $attemptNumber; // Store for potential use elsewhere

            $wrongQuestions = array_keys(array_filter($this->questionResults, fn($correct) => !$correct));
            
            $rightQuestions = array_keys(array_filter($this->questionResults, fn($correct) => $correct));

            $moduleVersion = Module::find($moduleId)->version ?? 'V1';

            $history = UserModuleHistory::create([
                'user_id' => $user->id,
                'module_id' => $moduleId,
                'attempt_number' => $attemptNumber,
                'wrong_questions' => $wrongQuestions,
                'right_questions' => $rightQuestions,
                'module_version' => $moduleVersion,
                'status' => !empty($wrongQuestions) ? 'failed' : 'completed', // If array is empty completed if not failed

            ]);

            // We may no longer need this event.
            ModuleAttempted::dispatch($history);

           // $this->handleNextModule($history);
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
            $questions = $module->questions()->where('difficulty', $level)->pluck('questions.id');

            if ($questions->isEmpty()) continue;

            $correctCount = $user->answeredQuestions()
                ->whereIn('questions.id', $questions)
                ->wherePivot('last_answer_correct', true)
                ->count();

            $total = $questions->count();
            $percentage = $total ? ($correctCount / $total) * 100 : 0;
             
            if ($percentage < 80) {
                \Log::info("Current user level: $level");
                \Log::info("questions: $total");
                return $level;
            }
        }
        

        return null; // all mastered
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
            $this->initializeQuizState('review');
            return; // 🔹 Important: stop execution here so the "otherwise" block doesn't overwrite questions
        }

        // Otherwise, serve only the unanswered questions
        $questionsToServe = $module->questions()
            ->whereIn('questions.id', $unansweredQuestionIds)
            ->get();

        $this->questions = $this->prepareQuestionsForQuiz($questionsToServe);
        $this->initializeQuizState('review');
    }

    /**
     * Prepare a collection of questions for the quiz: shuffle answers/options depending on type
     */
    private function prepareQuestionsForQuiz($questions)
    {
        return $questions->map(function ($q) {
            $answer = $q->answer;

            if ($q->type === 'mcq') shuffle($answer['options']);
            if ($q->type === 'ordering') shuffle($answer['steps']);
            if ($q->type === 'matching_pairs') shuffle($answer['pairs']['values']);

            $q->answer = $answer;
            return $q;
        });
    }

    private function getQuestionIdsForDifficulty($module, $difficulty)
    {
        return $module->questions()
            ->where('difficulty', $difficulty)
            ->pluck('questions.id')
            ->toArray();
    }

    private function getLeastAccurateQuestions($user, $limit = 5, $module = null)
    {
        $query = $user->answeredQuestions()
            ->withPivot(['attempts', 'correct_count']);

        // Optional: filter by module if provided
        if ($module) {
            $query->whereHas('modules', fn($q) => $q->where('modules.id', $module->id));
        }

        return $query->get()
            ->filter(fn($q) => $q->pivot->attempts > 0)
            ->map(function ($q) {
                $accuracy = $q->pivot->correct_count / $q->pivot->attempts;
                $q->accuracy = round($accuracy * 100, 2);
                return $q;
            })
            ->sortBy('accuracy') // least accurate first
            ->take($limit)
            ->values();
    }

    private function getTargetQuestions(array $moduleQuestionIds, $user)
    {
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
        $this->feedback = null;
        $this->questionResults = [];
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







}
