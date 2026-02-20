<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Module;
use App\Models\Card;
use App\Models\Pipeline;
use App\Models\Question;

class Collection extends Component
{

    public $selectedCardId = null;

    public function mount()
    {
        $this->loadInitialCard();
    }

    protected function loadInitialCard()
    {
        $card = auth()->user()
            ->cards()
            ->latest()
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

    public function render()
    {
        return view('livewire.Collection');
    }
}
