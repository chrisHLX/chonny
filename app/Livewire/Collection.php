<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Module;
use App\Models\Card;
use App\Models\Pipeline;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Concept;
use App\Models\Proficiency;
use App\Models\Category;
use App\Jobs\GenerateReviewContentJob;

class Collection extends Component
{

    public $selectedCardId = null;
    public $activeQuestionTab = 'history'; // default tab

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
        
        $this->categoryId = $this->categoryId ?? Category::first()->id;

        $subjects = Subject::where('category_id', $this->categoryId)->get();

        $this->currentSubjectId = $this->currentSubjectId 
            ?? $subjects->first()?->id;
        
        $this->loadInitialCard();
    }

    protected function loadInitialCard()
    {
        $card = auth()->user()
            ->cards()
            ->latest()
            ->whereHas('module', function ($q) {
                $q->where('subject_id', $this->currentSubjectId);
            })
            ->first();

        $this->selectedCardId = $card?->id;
    }

    public function selectCard($cardId)
    {
        $this->selectedCardId = $cardId;
    }

    public function getCardsProperty()
    {
        return auth()->user()
            ->cards()
            ->with(['module', 'proficiency'])
            ->whereHas('module', function ($q) {
                $q->where('subject_id', $this->currentSubjectId);
            })
            ->latest()
            ->get();
    }

    public function getModulesProperty()
    {
        return auth()->user()
            ->modules()
            ->with('questions')
            ->when($this->selectedCardId, function ($query) {
                $query->whereHas('cards', function ($q) {
                    $q->where('cards.id', $this->selectedCardId);
                });
            })
            ->get();
    }

    public function getAnsweredQuestionsProperty()
    {
        if (!$this->selectedCardId) {
            return collect();
        }

        $card = Card::find($this->selectedCardId);

        if (!$card) {
            return collect();
        }

        return auth()->user()
            ->answeredQuestions()
            ->whereHas('modules', fn ($q) => $q->where('modules.id', $card->module_id))
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

    public function regenerateExplanation($questionId)
    {
        $question = Question::find($questionId);
        // Dispatch a job to regenerate the explanation content
        dd("currently  not implemented");
    }

    public function render()
    {
        return view('livewire.collection');
    }
}
