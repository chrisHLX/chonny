<?php

namespace App\Training;

use App\Models\Training\Response;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One learner's state across Training, read from their answers.
 *
 * Mastery counts only transfer questions (a new situation in a different domain) answered right on
 * the first attempt. Getting the in-lesson case right shows the lesson was followed; getting the
 * transfer right shows the idea moved somewhere new. Nothing here writes UserConceptMastery (rule
 * 35): Training's concepts are its own, read back from its own answers.
 */
class Progress
{
    /** @var Collection<string, Collection<int, Response>> */
    private Collection $byLesson;

    public function __construct(private Curriculum $curriculum, User $user)
    {
        $this->byLesson = $user->trainingResponses()->orderBy('id')->get()->groupBy('lesson_slug');
    }

    public function lessonState(string $slug): array
    {
        $lesson = $this->curriculum->lesson($slug);
        $rows = $this->byLesson->get($slug, collect());
        $written = fn (string $ex) => $rows->where('exercise', $ex)->last()?->body;
        $choice = function (string $ex) use ($rows, $lesson) {
            $q = $this->curriculum->question($lesson, $ex);
            $attempts = $rows->where('exercise', $ex)->values();

            return [
                'attempts' => $attempts->map(fn ($r) => [
                    'choice' => $r->choice,
                    'correct' => $r->correct,
                    'feedback' => $q['options'][$r->choice]['feedback'] ?? '',
                ])->all(),
                'solved' => $attempts->contains('correct', true),
                'firstTry' => $attempts->first()?->correct,
            ];
        };

        $state = [
            'interpretation' => $written('interpretation'),
            'case' => $choice('case'),
            'transfer' => $choice('transfer'),
            'evidence' => $written('evidence'),
            'reflection' => $written('reflection'),
        ];
        $state['complete'] = $state['case']['attempts'] !== [] && $state['transfer']['attempts'] !== [] && filled($state['evidence']);

        return $state;
    }

    public function isComplete(string $slug): bool
    {
        return $this->lessonState($slug)['complete'];
    }

    public function isStarted(string $slug): bool
    {
        return $this->byLesson->has($slug);
    }

    public function completedCount(): int
    {
        return collect(array_keys($this->curriculum->lessons()))->filter(fn ($s) => $this->isComplete($s))->count();
    }

    /** @return array<string, array{name: string, attempted: int, firstTry: int, caseAttempted: int, caseFirstTry: int}> */
    public function mastery(): array
    {
        $out = [];
        foreach ($this->curriculum->concepts() as $slug => $c) {
            $out[$slug] = ['name' => $c['name'], 'attempted' => 0, 'firstTry' => 0, 'caseAttempted' => 0, 'caseFirstTry' => 0];
        }
        foreach ($this->curriculum->lessons() as $slug => $lesson) {
            $rows = $this->byLesson->get($slug, collect());
            $t = $rows->firstWhere('exercise', 'transfer');
            $c = $rows->firstWhere('exercise', 'case');
            foreach ($lesson['concepts'] as $concept) {
                if (! isset($out[$concept])) {
                    continue;
                }
                if ($t) {
                    $out[$concept]['attempted']++;
                    $out[$concept]['firstTry'] += $t->correct ? 1 : 0;
                }
                if ($c) {
                    $out[$concept]['caseAttempted']++;
                    $out[$concept]['caseFirstTry'] += $c->correct ? 1 : 0;
                }
            }
        }

        return $out;
    }

    /** First interpretations next to the reflection written at the end of each lesson. */
    public function journal(): array
    {
        $out = [];
        foreach ($this->curriculum->lessons() as $slug => $lesson) {
            $rows = $this->byLesson->get($slug, collect());
            $first = $rows->where('exercise', 'interpretation')->last();
            if (! $first) {
                continue;
            }
            $out[] = [
                'lesson' => $lesson,
                'first' => $first->body,
                'firstAt' => $first->updated_at,
                'reflection' => $rows->where('exercise', 'reflection')->last()?->body,
            ];
        }

        return $out;
    }
}
