<?php

namespace App\Http\Controllers\Training;

use App\Enums\Training\NoteKind;
use App\Enums\Training\RevisitOutcome;
use App\Http\Controllers\Controller;
use App\Models\PageViewEvent;
use App\Models\Training\Note;
use App\Models\Training\Response;
use App\Models\User;
use App\Training\Assessment;
use App\Training\Curriculum;
use App\Training\Progress;
use Illuminate\Http\Request;

/**
 * Training (2026-10-23): lessons in reasoning (cybernetics, causal reasoning, statistics, systems,
 * decisions, experiments, incentives, AI, human factors) with notes that come back for a revisit.
 * Not a game, so it sits beside Home in the sidebar rather than in the game switcher, and it spans
 * every game. The why: VISION.md, "What building it has taught". The engine: docs/training.md.
 */
class TrainingController extends Controller
{
    public function __construct(private Curriculum $curriculum) {}

    public function home(Request $request)
    {
        PageViewEvent::log('training_home');
        $user = $request->user();
        if (! $user) {
            return view('training.home', ['curriculum' => $this->curriculum, 'progress' => null]);
        }

        $progress = new Progress($this->curriculum, $user);
        $assessment = new Assessment($this->curriculum);

        return view('training.home', [
            'curriculum' => $this->curriculum,
            'progress' => $progress,
            'nextLesson' => collect($this->curriculum->lessons())->first(fn ($l) => ! $progress->isComplete($l['slug'])),
            'formA' => $assessment->result($user, 'A'),
            'formB' => $assessment->result($user, 'B'),
            'formBOpen' => $assessment->formBOpen($user),
            'dueNotes' => $user->trainingNotes()->due()->with('revisits')->orderBy('revisit_on')->get(),
            'dueClaims' => $user->trainingClaims()->whereNull('outcome')->whereDate('revisit_on', '<=', today())->get(),
            'noteCount' => $user->trainingNotes()->count(),
        ]);
    }

    public function subject(Request $request, string $subject)
    {
        $s = $this->curriculum->subject($subject) ?? abort(404);
        PageViewEvent::log('training_subject');

        return view('training.subject', [
            'subject' => $s,
            'lessons' => $this->curriculum->lessonsFor($subject),
            'progress' => new Progress($this->curriculum, $request->user()),
            'curriculum' => $this->curriculum,
        ]);
    }

    public function lesson(Request $request, string $lesson)
    {
        $l = $this->curriculum->lesson($lesson) ?? abort(404);
        PageViewEvent::log('training_lesson');

        $notes = $request->user()->trainingNotes()->where('lesson_slug', $lesson)->withCount('revisits')->orderBy('id')->get();
        $kinds = collect(NoteKind::cases())->map(fn ($k) => ['value' => $k->value, 'label' => $k->label(), 'prompt' => $k->prompt(), 'days' => $k->revisitAfterDays()])->values();

        return view('training.lesson', [
            'lesson' => $l,
            'subject' => $this->curriculum->subject($l['subject']),
            'next' => $this->curriculum->next($lesson),
            'concepts' => array_map(fn ($c) => $this->curriculum->concept($c), $l['concepts']),
            'kinds' => $kinds,
            // Built here: a multi-line @json([...]) literal breaks Blade's parser (Traps, CLAUDE.md).
            'client' => [
                'slug' => $l['slug'],
                'state' => (new Progress($this->curriculum, $request->user()))->lessonState($lesson),
                'notes' => $notes->map->toClient()->values(),
                'kinds' => $kinds,
                'urls' => [
                    'responses' => route('training.responses.store', $lesson),
                    'notes' => route('training.notes.store', $lesson),
                    'note' => url('/training/notes'),
                    'notebook' => route('training.notebook'),
                ],
            ],
        ]);
    }

    public function notebook(Request $request)
    {
        PageViewEvent::log('training_notebook');
        $user = $request->user();
        $filters = $request->validate([
            'kind' => ['nullable', 'string'],
            'status' => ['nullable', 'in:open,revisited,resolved'],
            'subject' => ['nullable', 'string'],
        ]);

        $query = $user->trainingNotes()->with('revisits')->latest();
        if (! empty($filters['kind']) && NoteKind::tryFrom($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['subject']) && $this->curriculum->subject($filters['subject'])) {
            $query->whereIn('lesson_slug', $this->curriculum->subject($filters['subject'])['lessons']);
        }

        // How often coming back to a note moved understanding: the loop's own result.
        $outcomes = $user->trainingNotes()
            ->join('training_note_revisits', 'training_notes.id', '=', 'training_note_revisits.training_note_id')
            ->selectRaw('training_note_revisits.outcome as outcome, count(*) as n')
            ->groupBy('training_note_revisits.outcome')->pluck('n', 'outcome');

        return view('training.notebook', [
            'due' => $user->trainingNotes()->due()->with('revisits')->orderBy('revisit_on')->get(),
            'notes' => $query->paginate(30)->withQueryString(),
            'filters' => $filters,
            'curriculum' => $this->curriculum,
            'outcomes' => $outcomes,
            'outcomeOptions' => RevisitOutcome::cases(),
            'kinds' => NoteKind::options(),
        ]);
    }

    public function progress(Request $request)
    {
        PageViewEvent::log('training_progress');
        $user = $request->user();
        $progress = new Progress($this->curriculum, $user);
        $assessment = new Assessment($this->curriculum);
        $claims = $user->trainingClaims()->whereNotNull('outcome')->get();

        return view('training.progress', [
            'curriculum' => $this->curriculum,
            'progress' => $progress,
            'mastery' => $progress->mastery(),
            'journal' => $progress->journal(),
            'formA' => $assessment->result($user, 'A'),
            'formB' => $assessment->result($user, 'B'),
            'formBOpen' => $assessment->formBOpen($user),
            'calibration' => [
                'high' => $claims->where('confidence_before', '>=', 70),
                'low' => $claims->where('confidence_before', '<', 70),
            ],
        ]);
    }

    /**
     * The course's own feedback loop (admin): passages learners marked confusing, questioned or
     * disagreed with, and the wrong answers they chose first. Read it to fix lessons.
     */
    public function feedback()
    {
        PageViewEvent::log('training_feedback');
        $flags = Note::whereIn('kind', ['confusing', 'question', 'disagree'])
            ->get(['lesson_slug', 'block_id', 'quote', 'kind', 'body', 'user_id'])->groupBy('lesson_slug');
        $firsts = Response::whereIn('exercise', Response::CHOICE)->where('attempt', 1)
            ->get(['lesson_slug', 'exercise', 'choice', 'correct'])
            ->groupBy(fn ($r) => $r->lesson_slug.'|'.$r->exercise);

        $rows = [];
        foreach ($this->curriculum->lessons() as $slug => $lesson) {
            $passages = collect($flags->get($slug, []))
                ->groupBy(fn ($a) => $a->block_id.'|'.mb_strtolower(trim($a->quote)))
                ->map(fn ($g) => [
                    'quote' => $g->first()->quote,
                    'block' => $g->first()->block_id,
                    'learners' => $g->pluck('user_id')->unique()->count(),
                    'kinds' => $g->countBy(fn ($a) => $a->kind->value)->all(),
                    'bodies' => $g->pluck('body')->filter()->take(5)->values()->all(),
                ])
                ->sortByDesc('learners')->take(6)->values()->all();

            $questions = [];
            foreach (Response::CHOICE as $ex) {
                $q = $this->curriculum->question($lesson, $ex);
                $answers = $firsts->get($slug.'|'.$ex, collect());
                if (! $q || $answers->isEmpty()) {
                    continue;
                }
                $questions[] = [
                    'exercise' => $ex,
                    'question' => $q['question'],
                    'n' => $answers->count(),
                    'rightFirst' => $answers->where('correct', true)->count(),
                    'options' => collect($q['options'])->map(fn ($o, $i) => [
                        'text' => $o['text'], 'correct' => $o['correct'], 'chosen' => $answers->where('choice', $i)->count(),
                    ])->all(),
                ];
            }
            if ($passages || $questions) {
                $rows[] = compact('lesson', 'passages', 'questions');
            }
        }

        return view('training.feedback', [
            'rows' => $rows,
            'learners' => User::whereHas('trainingResponses')->orWhereHas('trainingNotes')->count(),
        ]);
    }
}
