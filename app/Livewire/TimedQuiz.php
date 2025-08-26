<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Question;
use App\Models\Module;
use App\Models\User;

class TimedQuiz extends Component
{
    public $modules;
    public $selectedModule;
    public $questions;
    public $currentIndex = 0;
    public $answer;
    public $feedback;
    public $elapsed = 0; // now always synced from Alpine
    public $questionTimes = [];
    public $score = 0;
    public $completed = false;
    public $started = false;

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

        $this->questions = $module->questions->shuffle()->take(5)->values();
        $this->started = true;
        $this->completed = false;
        $this->score = 0;
        $this->elapsed = 0;
        $this->questionTimes = [];
        $this->currentIndex = 0;
        $this->answer = [];
        $this->feedback = null;
    }

    public function submit($params = [])
    {
        $question = $this->questions[$this->currentIndex];
        $correct = false;
        $this->elapsed = isset($params['elapsed']) ? (int) $params['elapsed'] : 0;
        
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
            $correct = collect($correctPairs)->every(fn($v, $k) => isset($userPairs[$k]) && $userPairs[$k] === $v);
            break;

        case 'ordering':
            $correctOrder = $question->answer['steps'];
                // Debug raw
                logger()->info('Raw Livewire answer', ['answer' => $this->answer]);
            // Livewire gives us $this->answer — if it's JSON from hidden input, decode it
            $userOrder = $this->answer;

            if (is_string($userOrder)) {
                $decoded = json_decode($userOrder, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $userOrder = $decoded;
                }
            }

            // Ensure it's an array
            if (!is_array($userOrder)) {
                $userOrder = [];
            }

            $correct = $userOrder === $correctOrder;
            break;

     }



        $this->feedback = $correct ? "✅ Correct! Time: {$this->elapsed}s" : "❌ Incorrect. Time: {$this->elapsed}s";
        if ($correct) $this->score++;

        $this->questionTimes[] = $this->elapsed;
        \Log::info("Estimated time: {$this->elapsed}s");
        // ✅ TRACK PROGRESS
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
        $this->elapsed = 0; // reset per question
        $this->currentIndex++;
        // Update the pivot table for showing user module progress
        if ($this->currentIndex >= $this->questions->count()) {
            $this->completed = true;
             // Update module_user pivot table
            $user = auth()->user();
            $moduleId = $this->selectedModule;

                if ($user && $moduleId) {
                    $user->modules()->syncWithoutDetaching([
                        $moduleId => [
                            'score' => $this->score / $this->questions->count() * 100,
                            'status' => 'completed',
                            'last_activity_at' => now(),
                            'completed_at' => now()
                        ]
                    ]);
                }
        }
    }

    public function render()
    {
        return view('livewire.timed-quiz');
    }
}
