<?php

namespace App\Livewire;

use Livewire\Component;

class QuizPage extends Component
{
    public string $mode = 'selection';     // 'selection' | 'running' | 'review-feedback'
    public ?int $selectedModule = null;

    protected $listeners = [
        'startQuiz'     => 'handleStartQuiz',
        'quizFinished'  => 'handleQuizFinished',
        'backToSelection' => 'resetToSelection',
    ];

    public function handleStartQuiz(int $moduleId)
    {
        $this->selectedModule = $moduleId;
        $this->mode = 'running';
    }

    public function handleQuizFinished()
    {
        // You can decide whether to go back to selection automatically
        // or stay on results screen inside QuizRunner
        // Here we go back — change if you prefer to stay on results
        $this->resetToSelection();
    }

    public function resetToSelection()
    {
        $this->mode = 'selection';
        $this->selectedModule = null;
    }

    public function render()
    {
        return view('livewire.quiz-page')
            ->layout('layouts.app');
    }
}