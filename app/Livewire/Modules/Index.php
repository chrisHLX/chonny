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
use App\Models\Tag;

class Index extends Component
{

    public $categoryId;
    public $currentSubjectId;
    public $statusFilter = 'all'; // all | completed | in_progress | not_started
    public $tagFilter = [];
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
        $this->reset('proficiencyFilter', 'statusFilter', 'tagFilter');
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
            ->where('status', 'ready')
            ->with([
                'users' => fn ($q) => $q->where('user_id', auth()->id()),
                'questions',
                'proficiencies',
                'tags'
            ]);

        if ($this->proficiencyFilter) {
            $query->whereHas('proficiencies', function ($q) {
                $q->where('proficiencies.id', $this->proficiencyFilter);
            });
        }

        if (!empty($this->tagFilter)) {
            $query->whereHas('tags', function ($q) {
                $q->whereIn('name', $this->tagFilter);
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

    public function getTagsProperty()
    {
        if (!$this->currentSubjectId) {
            return collect();
        }

        return Tag::where('subject_id', $this->currentSubjectId)
                ->get();
    }

    public function toggleTag($tagName)
    {
        if (in_array($tagName, $this->tagFilter)) {
            $this->tagFilter = array_values(array_diff($this->tagFilter, [$tagName]));
        } else {
            $this->tagFilter = [...$this->tagFilter, $tagName];
        }
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

