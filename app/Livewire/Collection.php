<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Module;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Concept;
use App\Models\Proficiency;
use App\Models\Category;
use App\Http\Services\ReviewQuestionService;
use App\Jobs\GenerateReviewContentJob;

class Collection extends Component
{

    public $selectedModuleId = null;
    public $activeQuestionTab = 'history'; // default tab
    public array $flaggedExplanations = []; // questionId => explanation string, in-memory only

    public $categoryId;
    public $currentSubjectId;

    protected $queryString = [
        'categoryId' => ['as' => 'category_id'],
        'currentSubjectId' => ['as' => 'subject_id'],
        'statusFilter' => ['except' => 'all'],
        'proficiencyFilter' => ['except' => null],
    ];

    public function mount()
    {
        // $this->categoryId/$currentSubjectId are already populated from the URL query string
        // by Livewire (see $queryString below) before mount() runs. Fall back to the last
        // explicitly selected context remembered in session, then Category::first() — see
        // DashboardController for why (a contextless visit shouldn't reset to whatever's first
        // in the DB).
        $this->categoryId = $this->categoryId ?? session('context.category_id') ?? Category::first()->id;
        session(['context.category_id' => $this->categoryId]);

        $subjects = Subject::where('category_id', $this->categoryId)->get();

        $this->currentSubjectId = $this->currentSubjectId
            ?? session('context.subject_id')
            ?? $subjects->first()?->id;

        // A remembered/URL subject_id may belong to a different category than the one just
        // resolved above — don't let a stale cross-category value scope every query below to
        // a subject that isn't even in $subjects.
        if (!$subjects->contains('id', $this->currentSubjectId)) {
            $this->currentSubjectId = $subjects->first()?->id;
        }

        session(['context.subject_id' => $this->currentSubjectId]);

        $this->loadInitialModule();
    }

    protected function loadInitialModule()
    {
        $this->selectedModuleId = $this->enrolledModules->first()?->id;
    }

    public function selectModule($moduleId)
    {
        $this->selectedModuleId = $moduleId;
    }

    public function getEnrolledModulesProperty()
    {
        return auth()->user()
            ->modules()
            ->where('subject_id', $this->currentSubjectId)
            ->orderByPivot('last_activity_at', 'desc')
            ->get();
    }

    public function getSelectedModuleProperty()
    {
        if (!$this->selectedModuleId) {
            return null;
        }

        return auth()->user()
            ->modules()
            ->where('modules.id', $this->selectedModuleId)
            ->first();
    }

    public function getAnsweredQuestionsProperty()
    {
        if (!$this->selectedModuleId) {
            return collect();
        }

        return auth()->user()
            ->answeredQuestions()
            ->whereHas('modules', fn ($q) => $q->where('modules.id', $this->selectedModuleId))
            ->withPivot([
                'attempts',
                'correct_count',
                'last_answered_at',
                'total_time_spent',
                'last_time_spent',
                'last_answer',
                'last_answer_correct',
                'consecutive_fails'
            ])
            ->with(['concepts', 'contents'])
            ->get();

    }


    public function getWrongQuestionsProperty()
    {
        return $this->answeredQuestions
            ->filter(fn ($q) => $q->pivot->attempts > $q->pivot->correct_count);
    }

    /**
     * Subject-scoped, not module-scoped — same invariant getEnrolledModulesProperty() already
     * follows on this page (see CLAUDE.md's "subject-scoped queries" architectural invariant).
     * A user's flagged-question library is meant to feel like a standalone thing worth
     * remembering, not something buried behind selecting a specific module first.
     */
    public function getFlaggedQuestionsProperty()
    {
        return auth()->user()
            ->flaggedQuestions()
            ->whereHas('modules', fn ($q) => $q->where('modules.subject_id', $this->currentSubjectId))
            ->with(['concepts', 'modules'])
            ->get();
    }

    public function explainQuestion($questionId)
    {
        $question = Question::with('modules')->find($questionId);

        if (!$question) {
            return;
        }

        // Resolve to the module matching the currently-selected subject, not an arbitrary
        // first() — a question can belong to modules in more than one subject, and the AI
        // explanation prompt includes the module's name/subject context, so this must match
        // what the user is actually looking at.
        $module = $question->modules->firstWhere('subject_id', $this->currentSubjectId)
            ?? $question->modules->first();

        if (!$module) {
            return;
        }

        $this->flaggedExplanations[$questionId] = app(ReviewQuestionService::class)
            ->getReviewContent($question, $module, auth()->id());
    }

    public function unflagQuestion($questionId)
    {
        auth()->user()->flaggedQuestions()->detach($questionId);
    }

    public function render()
    {
        return view('livewire.collection');
    }
}
