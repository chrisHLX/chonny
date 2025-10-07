<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\UserModuleHistory;
use App\Events\ModuleAttempted;
use App\Models\Module;
use App\Http\Services\AiService;

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

        $module = auth()->user()->modules()->with('questions')->find($this->selectedModule);

        if (!$module) {
            session()->flash('error', 'Module not found or not assigned to you.');
            return;
        }

        // Select 5 random questions from the module & Shuffle the options so they are not in the same order each time
        $this->answer = []; // cast answer to array
        $this->questions = $module->questions
        ->shuffle()
        ->take(5)
        ->values()
        ->transform(function ($question) {
            // need to save the answer to a variable before assigning the shuffled options 
            // back due to how laravel casts creates a copy of the array 
            // (which means you cant just do $question->answer['options'] = $options)
            $answer = $question->answer;

            if ($question->type === 'mcq') {
                shuffle($answer['options']);
                $question->answer = $answer;
            }

            if ($question->type === 'ordering') {
                shuffle($answer['steps']);
                $question->answer = $answer;
            }

            
            if ($question->type === 'matching_pairs') {
                shuffle($answer['pairs']['values']); // only shuffle right-hand side
                $question->answer = $answer;
            }
            
            return $question;
        });


        $this->started = true;
        $this->completed = false;
        $this->score = 0;
        $this->elapsed = 0;
        $this->questionTimes = [];
        $this->currentIndex = 0;
        
        $this->feedback = null;
        $this->questionResults = [];
    }

    public function submit($params = [])
    {
        $question = $this->questions[$this->currentIndex];
        $correct = false;

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

        // Track in pivot (answered_questions)
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
                ]);
            } else {
                $user->answeredQuestions()->attach($question->id, [
                    'attempts' => 1,
                    'correct_count' => $correct ? 1 : 0,
                    'last_answered_at' => now(),
                    'last_time_spent' => $this->elapsed,
                    'total_time_spent' => $this->elapsed,
                    'last_answer' => is_array($this->answer) ? json_encode($this->answer) : $this->answer,
                ]);
            }
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

        if ($this->currentIndex >= $this->questions->count()) {
            // Quiz completed
            $this->completed = true;

            $user = auth()->user();
            $moduleId = $this->selectedModule;

            // ✅ Update pivot for module progress
            if ($user && $moduleId) {
                $user->modules()->syncWithoutDetaching([
                    $moduleId => [
                        'score' => $this->score / $this->questions->count() * 100,
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
        $this->completed = false;
        $this->score = 0;
        $this->currentIndex = 0;
        $this->answer = [];
        $this->questionTimes = [];
        $this->totalTime = 0;

        $this->mount(); // or refetch questions the same way as mount
    }

    public function generateRevisionModule()
    {
        
        \Log::info("Grabbing the lattest module");
        // Now user should see new module in dropdown list and it should be selected
        // is it possible to already have the new module selected?
        
        $this->started = false; // I think this brings you back to the select module part


    }

    public function unlockNewModule()
    {
        // Example: fetch a different module or next version
        \Log::info("Unlocking new quiz for module {$this->selectedModule}");
    }

    protected function handleNextModule(UserModuleHistory $history)
    {
        $user = auth()->user();

        // The actual model ID is saved in module ID
        $moduleID = $this->selectedModule;

        //resolve ai service
        $aiService = app(AiService::class);
        

        // 1️⃣ Generate a revision module after 3 failed attempts
        if ($history->status === 'failed' && $history->attempt_number >= 3) {
            $newModule = $aiService->generateNewModule($moduleID, $history->wrong_questions);
            // $user->modules()->attach($newModule->id); // DONE IN AI SERVICE
            $this->selectedModule = $newModule->id;
            \Log::info("revision done");
        }

        // 2️⃣ Generate a harder module if user passed
        if ($history->status === 'completed') {
            $newModule = $aiService->generateHarderModule($moduleID, $history->right_questions);
            //$user->modules()->attach($newModule->id);
            \Log::info("harder done");
        }
    }

}
