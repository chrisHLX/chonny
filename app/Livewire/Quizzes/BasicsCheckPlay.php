<?php

namespace App\Livewire\Quizzes;

use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\QuizAttempt;
use App\Models\Specialization;
use App\Quiz\QuizService;
use App\Quiz\Wow\BasicsCheck;
use App\Quiz\Wow\WowQuiz;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /wow/quiz/basics — does a player new to arena have the basics? One question per basic
 * (BasicsCheck), built from the spec they play, and a result that says basic by basic what they
 * have and what to work on, with the comp page's guide and the arena model one click away.
 *
 * Same shape as ConceptDrillPlay, deliberately: the questions and the answer key live on the
 * QuizAttempt row (or the session, until the first answer), never in a public property, and every
 * property the server owns is #[Locked]. No concept mastery is written (rule 35).
 */
class BasicsCheckPlay extends Component
{
    #[Locked]
    public ?Specialization $spec = null;

    #[Locked]
    public ?int $attemptId = null;

    #[Locked]
    public ?string $pendingKey = null;

    #[Locked]
    public int $index = 0;

    #[Locked]
    public bool $showResults = false;

    public function mount(QuizService $quizzes, ?string $classSlug = null, ?string $specSlug = null): void
    {
        PageViewEvent::log('basics_check');

        if ($classSlug === null) {
            return;
        }

        $this->spec = Specialization::with('gameClass')
            ->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug)->whereHas('game', fn ($g) => $g->where('slug', 'wow')))
            ->where('slug', $specSlug)
            ->first();

        abort_unless($this->spec, 404);

        PageViewEvent::log('basics_check', $this->spec->class_id, $this->spec->id);

        // Dealt, not saved: a row is written on the first answer (QuizService::PENDING_KEY).
        $this->pendingKey = $quizzes->prepare('wow', WowQuiz::basicsSubjectFor($this->spec), 1);
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
        $this->redirectRoute('wow-basics', ['classSlug' => $this->spec->gameClass->slug, 'specSlug' => $this->spec->slug], navigate: true);
    }

    private function attempt(): ?QuizAttempt
    {
        if (! $this->attemptId) {
            return $this->pendingKey ? app(QuizService::class)->pending($this->pendingKey) : null;
        }

        $attempt = QuizAttempt::find($this->attemptId);

        return $attempt && $attempt->belongsToViewer(auth()->user(), session()->getId()) ? $attempt : null;
    }

    /**
     * Each basic asked, with whether it was answered right, in BasicsCheck order.
     *
     * @return array<int, array{key: string, title: string, lesson: string, brain: string, right: bool}>
     */
    private function results(QuizAttempt $attempt): array
    {
        $out = [];

        foreach ($attempt->questions ?? [] as $i => $q) {
            $key = BasicsCheck::basicOf($q['type'] ?? '');

            if ($key && isset(BasicsCheck::BASICS[$key])) {
                $out[] = BasicsCheck::BASICS[$key] + ['key' => $key, 'right' => ($attempt->answers[$i] ?? null) === $q['correct']];
            }
        }

        return $out;
    }

    public function render()
    {
        $attempt = $this->attempt();
        $label = $this->spec ? "{$this->spec->name} {$this->spec->gameClass->name}" : null;

        return view('livewire.quizzes.basics-check-play', [
            'attempt' => $attempt,
            'question' => $attempt?->question($this->index),
            'chosen' => $attempt?->answerFor($this->index),
            'specLabel' => $label,
            'results' => $attempt && $this->showResults ? $this->results($attempt) : [],
            'classes' => $this->spec ? collect() : GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
                ->with(['specializations' => fn ($q) => $q->orderBy('name')])
                ->orderBy('name')
                ->get(),
        ])->layout('layouts.app', [
            'title' => ($label ? "Arena basics check — {$label}" : 'Arena basics check').' | MindCollector',
            'description' => 'New to WoW arena? Eight questions on the basics — goes, crowd control, diminishing returns, defensives, kicks, line of sight — built from your own spec, with what to work on.',
        ]);
    }
}
