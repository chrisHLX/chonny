<?php

namespace App\Livewire\Quizzes;

use App\Models\PageViewEvent;
use App\Models\QuizAttempt;
use App\Models\Specialization;
use App\Quiz\QuizService;
use App\Quiz\Wow\WowQuiz;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Plays one level of a spec's quiz.
 *
 * The questions and their answers live on the QuizAttempt row, never in a public property: a
 * Livewire snapshot is readable in the browser, so storing the correct answer there would give it
 * away. The page only learns which option was right after the player has answered.
 */
class WowQuizPlay extends Component
{
    #[Locked]
    public Specialization $spec;

    #[Locked]
    public int $level;

    #[Locked]
    public ?int $attemptId = null;

    /** The dealt-but-unsaved quiz in this session; it becomes $attemptId on the first answer. */
    #[Locked]
    public ?string $pendingKey = null;

    #[Locked]
    public int $index = 0;

    #[Locked]
    public bool $showResults = false;

    public function mount(string $classSlug, string $specSlug, int $level, QuizService $quizzes): void
    {
        $spec = Specialization::with('gameClass')
            ->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug)->whereHas('game', fn ($g) => $g->where('slug', 'wow')))
            ->where('slug', $specSlug)
            ->first();

        abort_unless($spec && isset($quizzes->game('wow')->levels()[$level]), 404);

        $this->spec = $spec;
        $this->level = $level;

        PageViewEvent::log('wow_quiz_play', $spec->class_id, $spec->id, (string) $level);

        // Dealt, not saved: a row is written on the first answer (see QuizService::PENDING_KEY).
        $this->pendingKey = $quizzes->prepare('wow', WowQuiz::subjectFor($spec), $level);
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
        $this->redirectRoute('wow-quiz.play', [
            'classSlug' => $this->spec->gameClass->slug,
            'specSlug' => $this->spec->slug,
            'level' => $this->level,
        ], navigate: true);
    }

    private function attempt(): ?QuizAttempt
    {
        if (! $this->attemptId) {
            return app(QuizService::class)->pending($this->pendingKey);
        }

        $attempt = QuizAttempt::find($this->attemptId);

        return $attempt && $attempt->belongsToViewer(auth()->user(), session()->getId()) ? $attempt : null;
    }

    public function render(QuizService $quizzes)
    {
        $attempt = $this->attempt();
        $levels = $quizzes->game('wow')->levels();
        $label = "{$this->spec->name} {$this->spec->gameClass->name}";

        return view('livewire.quizzes.wow-quiz-play', [
            'attempt' => $attempt,
            'question' => $attempt?->question($this->index),
            'chosen' => $attempt?->answerFor($this->index),
            'levelInfo' => $levels[$this->level],
            'nextLevel' => $levels[$this->level + 1] ?? null,
            'specLabel' => $label,
        ])->layout('layouts.app', [
            'title' => "{$label} — Level {$this->level} quiz | MindCollector",
            'description' => "A quiz on {$label}'s arena kit, built from live game data.",
        ]);
    }
}
