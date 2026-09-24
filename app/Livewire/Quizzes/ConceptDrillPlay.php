<?php

namespace App\Livewire\Quizzes;

use App\Learning\ConceptCoverage;
use App\Learning\WowConcepts;
use App\Models\Concept;
use App\Models\PageViewEvent;
use App\Models\QuizAttempt;
use App\Models\Specialization;
use App\Quiz\QuizService;
use App\Quiz\Wow\WowQuiz;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Drills one concept's generated questions, as one spec.
 *
 * Nothing here is authored. Every question is built from the spell data at the moment the attempt
 * starts, so a drill cannot hold a wrong cooldown or a PvE duration the way the stored bank did.
 *
 * Same shape as WowQuizPlay, deliberately: questions and answers live on the QuizAttempt row and
 * never in a public property, because a Livewire snapshot is readable in the browser and would
 * hand the player the answer key. Every property the server owns is #[Locked] — an unlocked one
 * is writable by anyone who posts to /livewire/update, bound or not.
 */
class ConceptDrillPlay extends Component
{
    #[Locked]
    public Concept $concept;

    #[Locked]
    public Specialization $spec;

    #[Locked]
    public ?int $attemptId = null;

    #[Locked]
    public int $index = 0;

    #[Locked]
    public bool $showResults = false;

    public function mount(string $classSlug, string $specSlug, string $conceptSlug, QuizService $quizzes): void
    {
        $spec = Specialization::with('gameClass')
            ->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug)->whereHas('game', fn ($g) => $g->where('slug', 'wow')))
            ->where('slug', $specSlug)
            ->first();

        $concept = WowConcepts::findBySlug($conceptSlug);

        abort_unless($spec && $concept, 404);

        // A concept with no generated question types has no drill and never will until the data
        // holds a field to ask about. 404 rather than an empty page: the index says why.
        abort_unless(ConceptCoverage::isGenerable($concept->name), 404);

        $this->spec = $spec;
        $this->concept = $concept;

        PageViewEvent::log('concept_drill_play', $spec->class_id, $spec->id, WowConcepts::slug($concept));

        $this->attemptId = $quizzes->start('wow', WowQuiz::drillSubjectFor($concept, $spec), 1, auth()->user(), session()->getId())?->id;
    }

    public function answer(string $key, QuizService $quizzes): void
    {
        $attempt = $this->attempt();
        if ($attempt) {
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
        $this->redirectRoute('wow-quiz.drill', $this->routeParams(), navigate: true);
    }

    /** @return array<string, string> */
    private function routeParams(): array
    {
        return [
            'classSlug' => $this->spec->gameClass->slug,
            'specSlug' => $this->spec->slug,
            'conceptSlug' => WowConcepts::slug($this->concept),
        ];
    }

    private function attempt(): ?QuizAttempt
    {
        $attempt = $this->attemptId ? QuizAttempt::find($this->attemptId) : null;

        return $attempt && $attempt->belongsToViewer(auth()->user(), session()->getId()) ? $attempt : null;
    }

    public function render()
    {
        $attempt = $this->attempt();
        $label = "{$this->spec->name} {$this->spec->gameClass->name}";

        return view('livewire.quizzes.concept-drill-play', [
            'attempt' => $attempt,
            'question' => $attempt?->question($this->index),
            'chosen' => $attempt?->answerFor($this->index),
            'specLabel' => $label,
            'brainSections' => ConceptCoverage::brainSectionsFor($this->concept->name),
        ])->layout('layouts.app', [
            'title' => "{$this->concept->name} drill — {$label} | MindCollector",
            'description' => "Practise {$this->concept->name} as {$label}. Every question is built from live game data, so the answers are current.",
        ]);
    }
}
