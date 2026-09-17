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
        $results = $quizzes->bestBySubject('wow', auth()->user(), session()->getId());
        $best = $this->spec ? ($results[WowQuiz::subjectFor($this->spec)] ?? []) : [];

        // The first level not yet passed is the one to take next.
        $recommended = collect($quiz->levels())->keys()->first(fn (int $n) => ! (($best[$n] ?? null)?->passed()));

        $title = $this->spec ? "{$this->spec->name} {$this->spec->gameClass->name} quiz" : 'WoW class quizzes';

        // Every spec this player has finished a level of, for the picker's colouring and the
        // "Your results" list. Keyed by spec id.
        $specResults = collect($results)
            ->mapWithKeys(fn (array $levels, string $subject) => [(int) str_replace('spec:', '', $subject) => $levels])
            ->all();

        return view('livewire.quizzes.wow-quiz-index', [
            'specResults' => $specResults,
            'resultSpecs' => $this->spec || $specResults === []
                ? collect()
                : Specialization::with('gameClass')->whereIn('id', array_keys($specResults))->get()
                    ->sortByDesc(fn (Specialization $s) => collect($specResults[$s->id])->max('completed_at'))
                    ->values(),
            'levelCount' => count($quiz->levels()),
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
