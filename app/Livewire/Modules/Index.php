<?php

namespace App\Livewire\Modules;

use Livewire\Component;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use App\Models\ModulePage;
use App\Models\Module;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Concept;
use App\Models\Proficiency;
use App\Models\Pipeline;
use App\Models\PipelineStep;
use App\Models\Category;

class Index extends Component
{

    public $categoryId;
    public $currentSubjectId;
    public $statusFilter = 'all'; // all | completed | in_progress | not_started
    public $proficiencyFilter = null;
    public $category;
    
    // Sync these properties with the query string, using custom keys and excluding default values to 
    // to prevent url mismatches when filters are in their default state.
    protected $queryString = [
        'categoryId' => ['as' => 'category_id'],
        'currentSubjectId' => ['as' => 'subject_id'],
        'statusFilter' => ['except' => 'all'],
        'proficiencyFilter' => ['except' => null],
    ];

    public function syncSubject()
    {
        $validSubject = Subject::where('id', $this->currentSubjectId)
            ->where('category_id', $this->categoryId)
            ->exists();

        if (! $validSubject) {
            $this->currentSubjectId = Subject::where('category_id', $this->categoryId)
                ->first()?->id;
        }
    }

    public function updatedCategoryId()
    {
        $this->syncSubject();
        $this->category = Category::find($this->categoryId);
    }

    public function updatedCurrentSubjectId()
    {
        $this->reset('proficiencyFilter', 'statusFilter');
    }
    public function mount()
    {
        $this->categoryId ??= Category::first()?->id;

        $this->syncSubject();

        $this->category = Category::find($this->categoryId);
    }

    public function getModulesProperty()
    {
        $query = Module::where('subject_id', $this->currentSubjectId)
            ->with([
                'users' => fn ($q) => $q->where('user_id', auth()->id()),
                'questions',
                'proficiencies',
            ]);

        \Log::info('subject_id: ' . $this->currentSubjectId);   

        if ($this->proficiencyFilter) {
            $query->whereHas('proficiencies', function ($q) {
                $q->where('proficiencies.id', $this->proficiencyFilter);
            });
        }

        
        $modules = $query->get();

        if ($this->statusFilter !== 'all') {
            $modules = $modules->filter(function ($module) {
                $userModule = $module->users->first();
                return $userModule?->pivot->status === $this->statusFilter;
            });
        }
        
        return $modules;
    }

    public function getProficienciesProperty()
    {
        if (!$this->currentSubjectId) {
            return collect();
        }

        return Proficiency::where('subject_id', $this->currentSubjectId)
            ->orderBy('index') // since you added this column 👌
            ->get();
    }

    public function render()
    {
        return view('livewire.modules.index')
            ->layout('layouts.app');
    }
}
