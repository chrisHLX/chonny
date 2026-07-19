<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Module;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Concept;
use App\Models\Proficiency;
use App\Models\Category;
use App\Models\UserProfileInsight;
use App\Models\UserNextStep;
use App\Models\UserLearningPathStage;
use App\Enums\NextStepStatus;
use App\Http\Services\ReviewQuestionService;
use App\Http\Services\NextStepService;
use App\Http\Services\SubjectContextService;
use App\Jobs\GenerateReviewContentJob;

class Collection extends Component
{

    public $selectedModuleId = null;
    public $activeQuestionTab = 'history'; // default tab
    public array $flaggedExplanations = []; // questionId => explanation string, in-memory only

    public $categoryId;
    public $currentSubjectId;

    // "Your Path" timeline — how many events are currently shown; bumped by loadMoreTimeline().
    public int $timelineLimit = 20;

    protected $queryString = [
        'categoryId' => ['as' => 'category_id'],
        'currentSubjectId' => ['as' => 'subject_id'],
        'statusFilter' => ['except' => 'all'],
        'proficiencyFilter' => ['except' => null],
    ];

    public function mount()
    {
        // $this->categoryId/$currentSubjectId are already populated from the URL query string
        // by Livewire (see $queryString below) before mount() runs. Fall back to the last
        // explicitly selected context remembered in session, then Category::first() — see
        // DashboardController for why (a contextless visit shouldn't reset to whatever's first
        // in the DB).
        $this->categoryId = $this->categoryId ?? session('context.category_id') ?? Category::first()->id;
        session(['context.category_id' => $this->categoryId]);

        $subjects = Subject::where('category_id', $this->categoryId)->get();

        $this->currentSubjectId = $this->currentSubjectId
            ?? session('context.subject_id')
            ?? $subjects->first()?->id;

        // A remembered/URL subject_id may belong to a different category than the one just
        // resolved above — don't let a stale cross-category value scope every query below to
        // a subject that isn't even in $subjects.
        if (!$subjects->contains('id', $this->currentSubjectId)) {
            $this->currentSubjectId = $subjects->first()?->id;
        }

        session(['context.subject_id' => $this->currentSubjectId]);

        $this->loadInitialModule();
    }

    protected function loadInitialModule()
    {
        $this->selectedModuleId = $this->enrolledModules->first()?->id;
    }

    public function selectModule($moduleId)
    {
        $this->selectedModuleId = $moduleId;
    }

    public function getEnrolledModulesProperty()
    {
        return auth()->user()
            ->modules()
            ->where('subject_id', $this->currentSubjectId)
            ->orderByPivot('last_activity_at', 'desc')
            ->get();
    }

    public function getSelectedModuleProperty()
    {
        if (!$this->selectedModuleId) {
            return null;
        }

        return auth()->user()
            ->modules()
            ->where('modules.id', $this->selectedModuleId)
            ->first();
    }

    public function getAnsweredQuestionsProperty()
    {
        if (!$this->selectedModuleId) {
            return collect();
        }

        return auth()->user()
            ->answeredQuestions()
            ->whereHas('modules', fn ($q) => $q->where('modules.id', $this->selectedModuleId))
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

    /**
     * Subject-scoped, not module-scoped — same invariant getEnrolledModulesProperty() already
     * follows on this page (see CLAUDE.md's "subject-scoped queries" architectural invariant).
     * A user's flagged-question library is meant to feel like a standalone thing worth
     * remembering, not something buried behind selecting a specific module first.
     */
    public function getFlaggedQuestionsProperty()
    {
        return auth()->user()
            ->flaggedQuestions()
            ->whereHas('modules', fn ($q) => $q->where('modules.subject_id', $this->currentSubjectId))
            ->with(['concepts', 'modules'])
            ->get();
    }

    public function explainQuestion($questionId)
    {
        $question = Question::with('modules')->find($questionId);

        if (!$question) {
            return;
        }

        // Resolve to the module matching the currently-selected subject, not an arbitrary
        // first() — a question can belong to modules in more than one subject, and the AI
        // explanation prompt includes the module's name/subject context, so this must match
        // what the user is actually looking at.
        $module = $question->modules->firstWhere('subject_id', $this->currentSubjectId)
            ?? $question->modules->first();

        if (!$module) {
            return;
        }

        $this->flaggedExplanations[$questionId] = app(ReviewQuestionService::class)
            ->getReviewContent($question, $module, auth()->id());
    }

    public function unflagQuestion($questionId)
    {
        auth()->user()->flaggedQuestions()->detach($questionId);
    }

    public function loadMoreTimeline(): void
    {
        $this->timelineLimit += 20;
    }

    /**
     * "Learning Path" — the persisted, authenticated counterpart to the guest-facing roadmap
     * preview (RoadmapService::buildGuestRoadmap()). Deliberately rendered parallel to "Your
     * Path" below rather than merged into it: this is a forward-looking plan, "Your Path" is a
     * backward-looking history — different shapes, different purpose, matching the same reasoning
     * milestone-path.blade.php's docblock gives for not sharing a component with the timeline.
     *
     * The 'first_module' stage never trusts the module_id UserLearningPathStage froze at persist
     * time — it always mirrors the live UserNextStep for this subject (same query and
     * completion-check DashboardController's $activeNextStep uses), so this can never show a
     * different recommendation than the dashboard's Next Experiment card. Concretely: this used
     * to independently guess a module via RoadmapService::findFirstModule() and freeze it forever,
     * which could (and did) disagree with NextStepService/RecommendationService's own, separately
     * evolving pick — the exact "two disconnected what's-next opinions shown at once" bug pattern
     * CLAUDE.md documents retiring twice already (SuggestionJob, recommended_module). This makes
     * the roadmap a view over NextStepService's single decision, not a second one.
     *
     * Stages after 'first_module' with no special handling below (the static config copy — "Win
     * Conditions", "Practice Drills", etc.) never self-report complete — there is currently no
     * real module/task tracking any of them, so there's nothing to light up. Known, deliberately
     * accepted gap, not a bug: filling it in means either building that content or generating it,
     * which is future work.
     *
     * When there's no live UserNextStep at all, that's not always "nothing has happened yet" —
     * it's the normal end state once RecommendationService runs out of candidate concepts for a
     * small content bank (confirmed in production: an SC2 user exhausted it after two module
     * completions). Falling back to the frozen persist-time guess in that case actively lies
     * about progress (it can show a module the user completed long ago). NextStepService::
     * hasAnyStepEverExisted() distinguishes that case from the genuine edge case of no next-step
     * system ever having run for this subject, which is the only time the frozen guess is shown.
     *
     * 'context_dimensions' (declared class/race/role/... — see SubjectContextService) is a
     * second, independently-completable stage, not gated behind 'first_module': a user can
     * declare their class before ever touching a module, so it reports its own complete/next/
     * future state regardless of 'first_module''s state (a declared-early context stage can show
     * 'complete' even while 'first_module' still shows 'next'). Only these two stage_keys ever
     * carry real tracking, though — every other stage (the plain static copy — "Win Conditions",
     * etc.) unconditionally stays 'future' and is never eligible to become 'next', since there is
     * nothing behind it a user could actually act on yet.
     */
    public function getLearningPathProperty()
    {
        $stages = UserLearningPathStage::where('user_id', auth()->id())
            ->where('subject_id', $this->currentSubjectId)
            ->orderBy('order_index')
            ->get();

        if ($stages->isEmpty()) {
            return collect();
        }

        $activeNextStep = UserNextStep::where('user_id', auth()->id())
            ->where('subject_id', $this->currentSubjectId)
            ->whereIn('step_type', ['task', 'module'])
            ->whereIn('status', ['pending', 'attempted'])
            ->latest()
            ->first();

        if ($activeNextStep) {
            $activeNextStep = app(NextStepService::class)->checkAndCompleteModuleStep($activeNextStep);
        }

        $contentExhausted = !$activeNextStep
            && app(NextStepService::class)->hasAnyStepEverExisted(auth()->id(), $this->currentSubjectId);

        $contextDeclared = app(SubjectContextService::class)->hasDeclaredAllRequiredDimensions(auth()->id(), $this->currentSubjectId);

        $nextAssigned = false;

        return $stages->values()->map(function ($stage, $index) use ($activeNextStep, $contentExhausted, $contextDeclared, &$nextAssigned) {
            if ($index === 0) {
                return ['title' => $stage->title, 'detail' => $stage->detail, 'status' => 'complete'];
            }

            if ($stage->stage_key === 'first_module') {
                if ($activeNextStep) {
                    $nextAssigned = true;
                    return ['title' => $activeNextStep->title, 'detail' => $activeNextStep->instructions, 'status' => 'next'];
                }

                if ($contentExhausted) {
                    return [
                        'title'  => 'All available modules completed',
                        'detail' => "You've worked through everything currently available for your growth areas. More content is on the way.",
                        'status' => 'complete',
                    ];
                }

                $nextAssigned = true;
                return ['title' => $stage->title, 'detail' => $stage->detail, 'status' => 'next'];
            }

            if ($stage->stage_key === 'context_dimensions') {
                if ($contextDeclared) {
                    return ['title' => $stage->title, 'detail' => $stage->detail, 'status' => 'complete'];
                }

                if (!$nextAssigned) {
                    $nextAssigned = true;
                    return ['title' => $stage->title, 'detail' => $stage->detail, 'status' => 'next'];
                }

                return ['title' => $stage->title, 'detail' => $stage->detail, 'status' => 'future'];
            }

            // Every other stage is plain static copy with no real module/task tracking it — always
            // 'future', regardless of position. Unlike 'first_module'/'context_dimensions' above,
            // there is nothing here a user could actually act on, so it must never claim 'next'.
            return ['title' => $stage->title, 'detail' => $stage->detail, 'status' => 'future'];
        });
    }

    /**
     * "Your Path" — a chronological, subject-scoped record of real events only (no fabricated
     * scores/deltas, no trend lines). Assembled from four existing tables and merged/sorted in
     * PHP rather than a SQL UNION: at this app's per-user data volumes (a handful of diagnostics,
     * tens of flagged questions/completed modules, a handful of concluded quest chains) sorting a
     * merged in-memory collection is cheap. A DB-level UNION would only earn its complexity if any
     * of these grew unbounded per user, which none currently do — flagging this per the brief
     * rather than silently forcing a fancier query.
     */
    public function getAllTimelineEventsProperty()
    {
        $userId = auth()->id();

        $diagnosticEvents = UserProfileInsight::where('user_id', $userId)
            ->where('subject_id', $this->currentSubjectId)
            ->get()
            ->map(fn ($insight) => [
                'type'      => 'diagnostic',
                'icon'      => 'icon-compass',
                'timestamp' => \Carbon\Carbon::parse($insight->generated_at),
                'title'     => 'Diagnostic completed',
                'detail'    => $insight->profile_title,
            ]);

        // Reuses the already-loaded, already subject-scoped Livewire computed property rather
        // than a second query — flaggedQuestions() is withTimestamps(), so pivot->created_at
        // (when the flag was made) is already there for free.
        $flagEvents = $this->flaggedQuestions
            ->map(fn ($question) => [
                'type'      => 'flag',
                'icon'      => 'icon-diamond',
                'timestamp' => \Carbon\Carbon::parse($question->pivot->created_at),
                'title'     => 'Question flagged',
                'detail'    => $question->question,
            ]);

        // Same reuse for enrolledModules — filtered here to completed, non-diagnostic modules
        // only. Diagnostic completions are deliberately excluded: they already surface as their
        // own "Diagnostic completed" event above, so including them here would double them up.
        $moduleEvents = $this->enrolledModules
            ->filter(fn ($m) => $m->type !== 'diagnostic'
                && ($m->pivot->status ?? null) === 'completed'
                && $m->pivot->completed_at)
            ->map(fn ($m) => [
                'type'      => 'module',
                'icon'      => 'icon-complete',
                'timestamp' => \Carbon\Carbon::parse($m->pivot->completed_at),
                'title'     => 'Module completed',
                'detail'    => $m->name,
            ]);

        // Reuses the exact copy from next-experiment.blade.php's conclusion-card states, and the
        // same positive/struggling rule via NextStepService::isPositiveReflectionTrend() — see
        // that method's docblock for why this isn't reimplemented inline here.
        $nextStepService = app(NextStepService::class);
        $questEvents = UserNextStep::where('user_id', $userId)
            ->where('subject_id', $this->currentSubjectId)
            ->where('status', NextStepStatus::Concluded->value)
            ->with('concept')
            ->get()
            ->map(function ($step) use ($nextStepService) {
                $conceptName = $step->concept?->name ?? 'this area';
                $isPositive  = $nextStepService->isPositiveReflectionTrend($step);

                return [
                    'type'      => 'quest_concluded',
                    'icon'      => 'icon-delta',
                    'timestamp' => \Carbon\Carbon::parse($step->completed_at ?? $step->updated_at),
                    'title'     => $isPositive ? 'Investigation complete' : 'Finding filed',
                    'detail'    => $isPositive
                        ? "You've run 3 experiments on {$conceptName} and reported how they went. Mindcollector's read: this may no longer be your biggest gap. Deeper re-profiling based on your accumulated evidence is coming."
                        : "You've given {$conceptName} three genuine attempts. This angle doesn't seem to be the way in — that's useful information, not a failure. Mindcollector will use it to try a different approach.",
                ];
            });

        return $diagnosticEvents
            ->concat($flagEvents)
            ->concat($moduleEvents)
            ->concat($questEvents)
            ->sortByDesc('timestamp')
            ->values();
    }

    public function getTimelineEventsProperty()
    {
        return $this->allTimelineEvents->take($this->timelineLimit);
    }

    public function getTimelineTotalCountProperty()
    {
        return $this->allTimelineEvents->count();
    }

    public function render()
    {
        return view('livewire.collection');
    }
}
