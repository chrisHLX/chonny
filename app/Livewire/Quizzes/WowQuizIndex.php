<?php

namespace App\Livewire\Quizzes;

use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use App\Quiz\QuizService;
use App\Quiz\Wow\WowQuiz;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /wow/quiz — pick a spec, then its levels with your best score on each.
 *
 * Levels are a recommended order rather than a lock: the next level to take is highlighted, but
 * a player who already knows their kit can start anywhere.
 */
class WowQuizIndex extends Component
{
    #[Locked]
    public ?Specialization $spec = null;

    public function mount(?string $classSlug = null, ?string $specSlug = null): void
    {
        PageViewEvent::log('wow_quiz');

        if ($classSlug === null) {
            return;
        }

        $this->spec = Specialization::with('gameClass')
            ->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug)->whereHas('game', fn ($g) => $g->where('slug', 'wow')))
            ->where('slug', $specSlug)
            ->first();

        abort_unless($this->spec, 404);

        PageViewEvent::log('wow_quiz', $this->spec->class_id, $this->spec->id);
    }

    public function render(QuizService $quizzes)
    {
        $quiz = $quizzes->game('wow');
        $best = $this->spec
            ? $quizzes->bestByLevel('wow', WowQuiz::subjectFor($this->spec), auth()->user(), session()->getId())
            : [];

        // The first level not yet passed is the one to take next.
        $recommended = collect($quiz->levels())->keys()->first(fn (int $n) => ! (($best[$n] ?? null)?->passed()));

        $title = $this->spec ? "{$this->spec->name} {$this->spec->gameClass->name} quiz" : 'WoW class quizzes';

        return view('livewire.quizzes.wow-quiz-index', [
            'classes' => $this->spec ? collect() : GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
                ->with(['specializations' => fn ($q) => $q->orderBy('name')])
                ->orderBy('name')
                ->get(),
            'levels' => $quiz->levels(),
            'best' => $best,
            'recommended' => $recommended,
        ])->layout('layouts.app', [
            'title' => "{$title} | MindCollector",
            'description' => 'Quizzes on your WoW arena kit: your cooldowns, your crowd control and diminishing returns. Built from live game data, so they stay current every patch.',
        ]);
    }
}
