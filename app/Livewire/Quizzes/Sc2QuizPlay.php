<?php

namespace App\Livewire\Quizzes;

use App\Models\PageViewEvent;
use App\Models\QuizAttempt;
use App\Quiz\QuizService;
use App\Quiz\Sc2\Sc2Quiz;
use App\Quiz\Sc2\Sc2TechTree;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Plays one level of a race's StarCraft II quiz. Works as WowQuizPlay does: the dealt questions
 * and their answers stay on the server (QuizService's pending session, then the attempt row),
 * and the page learns which option was right only after the player has answered.
 */
class Sc2QuizPlay extends Component
{
    #[Locked]
    public string $race;

    #[Locked]
    public int $level;

    #[Locked]
    public ?int $attemptId = null;

    #[Locked]
    public ?string $pendingKey = null;

    #[Locked]
    public int $index = 0;

    #[Locked]
    public bool $showResults = false;

    public function mount(string $race, int $level, QuizService $quizzes, Sc2TechTree $tree): void
    {
        abort_unless(isset($tree->raceNames()[$race], $quizzes->game('sc2')->levels()[$level]), 404);

        $this->race = $race;
        $this->level = $level;

        PageViewEvent::log('sc2_quiz_play', null, null, "{$race}:{$level}");

        $this->pendingKey = $quizzes->prepare('sc2', Sc2Quiz::subjectFor($race), $level);
    }

    public function answer(string $key, QuizService $quizzes): void
    {
        if (! $this->attemptId && $this->pendingKey) {
            $this->attemptId = $quizzes->begin($this->pendingKey, auth()->user(), session()->getId())?->id;
            $this->pendingKey = null;
        }

        $attempt = $this->attempt();
        if ($attempt?->exists) {
            $quizzes->answer($attempt, $this->index, $key);
        }
    }

    public function next(): void
    {
        $attempt = $this->attempt();
        if (! $attempt || $attempt->answerFor($this->index) === null) {
            return;
        }

        if ($this->index + 1 >= $attempt->total) {
            $this->showResults = true;

            return;
        }

        $this->index++;
    }

    public function retry(): void
    {
        $this->redirectRoute('sc2-quiz.play', ['race' => $this->race, 'level' => $this->level], navigate: true);
    }

    private function attempt(): ?QuizAttempt
    {
        if (! $this->attemptId) {
            return app(QuizService::class)->pending($this->pendingKey);
        }

        $attempt = QuizAttempt::find($this->attemptId);

        return $attempt && $attempt->belongsToViewer(auth()->user(), session()->getId()) ? $attempt : null;
    }

    public function render(QuizService $quizzes, Sc2TechTree $tree)
    {
        $attempt = $this->attempt();
        $levels = $quizzes->game('sc2')->levels();
        $raceName = $tree->raceNames()[$this->race];

        return view('livewire.quizzes.sc2-quiz-play', [
            'attempt' => $attempt,
            'question' => $attempt?->question($this->index),
            'chosen' => $attempt?->answerFor($this->index),
            'levelInfo' => $levels[$this->level],
            'nextLevel' => $levels[$this->level + 1] ?? null,
            'raceName' => $raceName,
            'color' => config('platform.games.sc2.color'),
        ])->layout('layouts.app', [
            'title' => "{$raceName} quiz, level {$this->level}: {$levels[$this->level]->title} | StarCraft II | MindCollector",
            'description' => "A StarCraft II quiz on {$raceName} units and buildings, built from the game's own data.",
        ]);
    }
}
