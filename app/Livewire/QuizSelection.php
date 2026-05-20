<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Subject;
use App\Models\Category;
use Illuminate\Support\Facades\Log;

class QuizSelection extends Component
{
    public $subjects = [];
    public $selectedModule;
    public $selectedSubject = null;
    public $category_id;

    public function mount()
    {
        $this->category_id = request()->query('category_id');

        if(!$this->category_id) {
            $this->category_id = Category::first()?->id;
        }

        // Load subjects based on category, when is used like an if statement, the first parameter is the value to check, the second is a callback that receives the query builder and the value if the first parameter is truthy
        $this->subjects = Subject::when($this->category_id, function($query, $catId) {
            $query->where('category_id', $catId);
        })->get();

        $this->selectedSubject = $this->subjects->first()?->id;
        $this->selectedModule = $this->modules->first()?->id;

    }

    

    public function getModulesProperty()
    {
        $subjectIds = $this->subjects->pluck('id');

        return auth()->user()
            ->modules()
            ->with('proficiencies')
            ->withPivot(['status'])
            ->whereIn('subject_id', $subjectIds)
            ->when($this->selectedSubject, fn($q) =>
                $q->where('subject_id', $this->selectedSubject)
            )
            ->get();
    }

    public function startQuiz()
    {
        if (!$this->selectedModule) {
            return;
        }

        $this->dispatch('startQuiz', moduleId: $this->selectedModule);
    }

    public function render()
    {
        return view('livewire.quiz-selection');
    }
}