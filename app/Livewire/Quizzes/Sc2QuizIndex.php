<?php

namespace App\Livewire\Quizzes;

use App\Models\PageViewEvent;
use App\Quiz\QuizService;
use App\Quiz\Sc2\Sc2Quiz;
use App\Quiz\Sc2\Sc2TechTree;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /sc2/quiz: the first StarCraft II content (2026-10-18). Pick a race, then its levels with your
 * best score on each; the playstyle diagnostic sits above, for a player who wants to start there.
 */
class Sc2QuizIndex extends Component
{
    #[Locked]
    public ?string $race = null;

    public function mount(Sc2TechTree $tree, ?string $race = null): void
    {
        abort_if($race !== null && ! isset($tree->raceNames()[$race]), 404);

        $this->race = $race;
        // The bare count, and the race as the slot when one was picked by its address.
        PageViewEvent::log('sc2_quiz', null, null, $race);
    }

    public function render(QuizService $quizzes, Sc2TechTree $tree)
    {
        $quiz = $quizzes->game('sc2');
        $results = $quizzes->bestBySubject('sc2', auth()->user(), session()->getId());
        $best = $this->race ? ($results[Sc2Quiz::subjectFor($this->race)] ?? []) : [];
        $recommended = collect($quiz->levels())->keys()->first(fn (int $n) => ! (($best[$n] ?? null)?->passed()));
        $raceName = $this->race ? $tree->raceNames()[$this->race] : null;

        return view('livewire.quizzes.sc2-quiz-index', [
            'races' => $tree->raceNames(),
            'raceName' => $raceName,
            'raceResults' => collect($tree->raceNames())->mapWithKeys(fn ($name, $slug) => [$slug => $results[Sc2Quiz::subjectFor($slug)] ?? []])->all(),
            'levels' => $quiz->levels(),
            'levelCount' => count($quiz->levels()),
            'best' => $best,
            'recommended' => $recommended,
            'counts' => collect($tree->all())->map(fn ($units) => count($units))->all(),
            'gameVersion' => $tree->gameVersion(),
            'extractedVersion' => $tree->extractedVersion(),
            'corrections' => $tree->corrections(),
            'diagnosticUrl' => config('app.screener_sc2_module_id') ? route('sc2-diagnostic') : null,
            'color' => config('platform.games.sc2.color'),
        ])->layout('layouts.app', [
            'title' => ($raceName ? "{$raceName} quiz" : 'StarCraft II quizzes').': units, costs and tech | MindCollector',
            'description' => $raceName
                ? "How well do you know {$raceName}? Costs, supply, build times, the tech tree and what each unit can hit, from StarCraft II's own data."
                : 'StarCraft II quizzes for Terran, Protoss and Zerg: costs, supply, build times, the tech tree and what each unit can hit, from the game\'s own data.',
        ]);
    }
}
