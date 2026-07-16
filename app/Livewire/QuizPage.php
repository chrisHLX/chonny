<?php

namespace App\Livewire;

use App\Models\Module;
use Livewire\Component;

class QuizPage extends Component
{
    public string $mode = 'selection';     // 'selection' | 'running' | 'review-feedback'
    public ?int $selectedModule = null;
    public bool $selectedModuleIsDiagnostic = false;

    public function mount(?int $moduleId = null): void
    {
        $id = $moduleId ?? (int) request()->query('moduleId') ?: null;

        if ($id) {
            $this->selectedModule = $id;
            $this->selectedModuleIsDiagnostic = Module::where('id', $id)->value('type') === 'diagnostic';
            $this->mode = 'running';
        }
    }

    protected $listeners = [
        'startQuiz'     => 'handleStartQuiz',
        'quizFinished'  => 'handleQuizFinished',
        'backToSelection' => 'resetToSelection',
    ];

    public function handleStartQuiz(int $moduleId)
    {
        $this->selectedModule = $moduleId;
        $this->selectedModuleIsDiagnostic = Module::where('id', $moduleId)->value('type') === 'diagnostic';
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
        $this->selectedModuleIsDiagnostic = false;
    }

    public function render()
    {
        return view('livewire.quiz-page')
            ->layout('layouts.app');
    }
}