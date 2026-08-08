<?php

namespace App\Livewire\Admin;

use App\Models\DiagnosticAttempt;
use App\Models\FunnelEvent;
use App\Models\Question;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class DiagnosticStats extends Component
{
    use WithPagination;

    /**
     * Guest → registered signup funnel, sourced from funnel_events (see GuestRoadmap,
     * RegisteredUserController). Answers the question this whole roadmap feature was built to
     * answer: are guests actually curious enough to click, and does clicking correlate with
     * signing up — not just "we assume nobody wants to sign up."
     *
     * signup_completed is logged once per diagnostic module a guest had pending at registration
     * time (RegisteredUserController::claimGuestQuizResults() loops per module) — a guest with
     * more than one pending diagnostic at once would be double-counted by a raw event count, so
     * signup totals here are deduplicated by distinct user_id rather than raw row count.
     */
    public function getFunnelProperty(): array
    {
        $counts = FunnelEvent::selectRaw('event, count(*) as count')
            ->groupBy('event')
            ->pluck('count', 'event');

        $profileViewed  = (int) ($counts['profile_viewed'] ?? 0);
        $roadmapClicked = (int) ($counts['roadmap_clicked'] ?? 0);
        $signupStarted  = (int) ($counts['signup_started'] ?? 0);

        $uniqueSignups = FunnelEvent::where('event', 'signup_completed')
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        $clickedSessionIds = FunnelEvent::where('event', 'roadmap_clicked')
            ->whereNotNull('guest_session_id')
            ->pluck('guest_session_id')
            ->unique();

        $signupsAfterClick = FunnelEvent::where('event', 'signup_completed')
            ->whereNotNull('user_id')
            ->whereIn('guest_session_id', $clickedSessionIds)
            ->distinct('user_id')
            ->count('user_id');

        $rate = fn (int $numerator, int $denominator) => $denominator > 0
            ? round(($numerator / $denominator) * 100, 1)
            : 0.0;

        return [
            'profileViewed'     => $profileViewed,
            'roadmapClicked'    => $roadmapClicked,
            'signupStarted'     => $signupStarted,
            'uniqueSignups'     => $uniqueSignups,
            'clickThroughRate'  => $rate($roadmapClicked, $profileViewed),
            'signupsAfterClick' => $signupsAfterClick,
            'nonClickerSignups' => max(0, $uniqueSignups - $signupsAfterClick),
            'clickerSignupRate' => $rate($signupsAfterClick, $roadmapClicked),
        ];
    }

    /**
     * Builds the same "u:{user}:{module}" / "s:{session}:{module}" identity key used to match a
     * funnel_events row to a DiagnosticAttempt row across this whole class — pulled out once here
     * so getSummaryProperty()/getDropOffProperty()/getContextCompletionProperty() share a single
     * definition instead of three independently-maintained copies.
     */
    private function identityKey(?int $userId, ?string $sessionId, int $moduleId): string
    {
        return $userId ? "u:{$userId}:{$moduleId}" : "s:{$sessionId}:{$moduleId}";
    }

    private function assessmentStartedKeys(): \Illuminate\Support\Collection
    {
        return FunnelEvent::where('event', 'assessment_started')
            ->get(['module_id', 'user_id', 'guest_session_id'])
            ->map(fn ($e) => $this->identityKey($e->user_id, $e->guest_session_id, $e->module_id))
            ->unique();
    }

    /**
     * "started" here means real engagement — a matching assessment_started funnel event — not
     * just a DiagnosticAttempt row. DiagnosticQuizRunner::mount() calls startQuizInternal(),
     * which calls recordAttemptStarted() unconditionally on every page load of a diagnostic
     * module (before the user clicks anything), so a raw DiagnosticAttempt::count() measures
     * page views, not starts. That inflated the headline number with anyone who merely loaded
     * the module page — including link-preview bots/crawlers, since the route is public — and
     * made the completion rate look far worse than it actually is among people who engaged.
     * pageViews is kept and exposed separately so that raw signal isn't lost, just no longer
     * mislabeled as "started".
     */
    public function getSummaryProperty(): array
    {
        $pageViews = DiagnosticAttempt::count();

        $startedKeys = $this->assessmentStartedKeys();

        $engaged = DiagnosticAttempt::get(['module_id', 'user_id', 'session_id', 'completed_at'])
            ->filter(fn ($a) => $startedKeys->contains($this->identityKey($a->user_id, $a->session_id, $a->module_id)));

        $started   = $engaged->count();
        $completed = $engaged->whereNotNull('completed_at')->count();

        $guestEngaged   = $engaged->whereNull('user_id');
        $guestStarted   = $guestEngaged->count();
        $guestCompleted = $guestEngaged->whereNotNull('completed_at')->count();

        return [
            'pageViews'      => $pageViews,
            'started'        => $started,
            'completed'      => $completed,
            'rate'           => $started > 0 ? round(($completed / $started) * 100, 1) : 0.0,
            'guestStarted'   => $guestStarted,
            'guestCompleted' => $guestCompleted,
            'authStarted'    => $started - $guestStarted,
            'authCompleted'  => $completed - $guestCompleted,
        ];
    }

    public function getChartDataProperty(): array
    {
        $rows = DiagnosticAttempt::where('started_at', '>=', now()->subDays(29)->startOfDay())
            ->get(['started_at', 'completed_at']);

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[now()->subDays($i)->format('Y-m-d')] = ['started' => 0, 'completed' => 0];
        }

        foreach ($rows as $row) {
            $key = $row->started_at->format('Y-m-d');
            if (array_key_exists($key, $days)) {
                $days[$key]['started']++;
            }
            if ($row->completed_at && array_key_exists($ck = $row->completed_at->format('Y-m-d'), $days)) {
                $days[$ck]['completed']++;
            }
        }

        $max = max(array_column($days, 'started')) ?: 1;

        return collect($days)->map(fn($counts, $date) => [
            'date'      => $date,
            'started'   => $counts['started'],
            'completed' => $counts['completed'],
            'pct'       => (int) round(($counts['started'] / $max) * 100),
            'label'     => Carbon::parse($date)->format('M j'),
        ])->values()->all();
    }

    /**
     * Where people are getting stuck, per subject (grouped separately since each subject's
     * diagnostic has a different question count, so raw question numbers aren't comparable
     * across subjects). Sourced from DiagnosticAttempt.last_question_index/total_questions,
     * updated on every question transition by DiagnosticQuizRunner::recordAttemptProgress() —
     * so an abandoned attempt (guest or auth) shows exactly which question it stalled on,
     * not just that it never finished.
     *
     * last_question_index stays 0 for two genuinely different situations: never clicked
     * "Start Assessment" at all, vs. clicked it and abandoned before submitting Q1 — both used
     * to be indistinguishable. Split using the assessment_started funnel event
     * (DiagnosticQuizRunner::startAssessment()), matched to each attempt the same
     * user_id/guest_session_id + module_id identity shape getContextCompletionProperty() below
     * already uses.
     */
    public function getDropOffProperty(): \Illuminate\Support\Collection
    {
        $startedKeys = $this->assessmentStartedKeys();

        return DiagnosticAttempt::with('subject')
            ->whereNull('completed_at')
            ->whereNotNull('last_question_index')
            ->whereNotNull('total_questions')
            ->where('total_questions', '>', 0)
            ->get(['subject_id', 'module_id', 'user_id', 'session_id', 'last_question_index', 'total_questions'])
            ->groupBy('subject_id')
            ->map(function ($group) use ($startedKeys) {
                $byQuestion = $group
                    ->groupBy(fn ($a) => $a->last_question_index + 1) // 1-based, human-readable
                    ->map->count()
                    ->sortKeys();

                $stuckOnQ1 = $group->filter(fn ($a) => $a->last_question_index === 0);
                $neverStarted = $stuckOnQ1->filter(fn ($a) => !$startedKeys->contains($this->identityKey($a->user_id, $a->session_id, $a->module_id)))->count();

                return [
                    'subject'         => $group->first()->subject?->name ?? '—',
                    'incomplete'      => $group->count(),
                    'total_questions' => $group->first()->total_questions,
                    'byQuestion'      => $byQuestion,
                    'worstQuestion'   => $byQuestion->sortDesc()->keys()->first(),
                    'worstCount'      => $byQuestion->max(),
                    'neverStarted'    => $neverStarted,
                    'abandonedOnQ1'   => $stuckOnQ1->count() - $neverStarted,
                ];
            })
            ->sortByDesc('incomplete')
            ->values();
    }

    /**
     * The read the context-aware-diagnostic work exists to produce (see Phase 7 of the
     * implementation plan): does declaring context correlate with finishing, and does having
     * authored context-specific variants for that subject correlate with it further? Computed at
     * query time from existing tables — DiagnosticAttempt, the context_declared funnel event
     * (DiagnosticQuizRunner::handleContextDeclared()), and question_context_option — no new
     * storage, matching the "extend funnel_events, don't build new analytics" decision.
     *
     * Declaration is matched to an attempt by (user_id, module_id) for auth or
     * (guest_session_id, module_id) for guest — the same identity shape funnel_events already
     * uses elsewhere in this class (see getFunnelProperty()'s guest_session_id joins).
     */
    public function getContextCompletionProperty(): array
    {
        $declaredKeys = FunnelEvent::where('event', 'context_declared')
            ->get(['module_id', 'user_id', 'guest_session_id'])
            ->map(fn ($e) => $this->identityKey($e->user_id, $e->guest_session_id, $e->module_id))
            ->unique();

        $subjectsWithVariants = Question::whereHas('contextOptions')
            ->with('modules:id,subject_id')
            ->get()
            ->flatMap(fn ($q) => $q->modules->pluck('subject_id'))
            ->unique();

        $buckets = [
            'declared_with_variants'    => ['label' => 'Declared context, variants available', 'started' => 0, 'completed' => 0],
            'declared_without_variants' => ['label' => 'Declared context, no variants yet',     'started' => 0, 'completed' => 0],
            'not_declared'              => ['label' => 'Did not declare context',               'started' => 0, 'completed' => 0],
        ];

        DiagnosticAttempt::get(['module_id', 'user_id', 'session_id', 'subject_id', 'completed_at'])
            ->each(function ($attempt) use ($declaredKeys, $subjectsWithVariants, &$buckets) {
                $key      = $this->identityKey($attempt->user_id, $attempt->session_id, $attempt->module_id);
                $declared = $declaredKeys->contains($key);

                $bucket = !$declared
                    ? 'not_declared'
                    : ($subjectsWithVariants->contains($attempt->subject_id) ? 'declared_with_variants' : 'declared_without_variants');

                $buckets[$bucket]['started']++;
                if ($attempt->completed_at) {
                    $buckets[$bucket]['completed']++;
                }
            });

        return collect($buckets)->map(fn ($b) => [
            'label'     => $b['label'],
            'started'   => $b['started'],
            'completed' => $b['completed'],
            'rate'      => $b['started'] > 0 ? round(($b['completed'] / $b['started']) * 100, 1) : 0.0,
        ])->values()->all();
    }

    public function getSubjectBreakdownProperty(): \Illuminate\Support\Collection
    {
        return DiagnosticAttempt::with('subject')
            ->get(['subject_id', 'completed_at'])
            ->groupBy('subject_id')
            ->map(function ($group) {
                $started   = $group->count();
                $completed = $group->whereNotNull('completed_at')->count();

                return [
                    'subject'   => $group->first()->subject?->name ?? '—',
                    'started'   => $started,
                    'completed' => $completed,
                    'rate'      => $started > 0 ? round(($completed / $started) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('started')
            ->values();
    }

    public function render()
    {
        return view('livewire.admin.diagnostic-stats', [
            'summary'          => $this->summary,
            'funnel'           => $this->funnel,
            'chartData'        => $this->chartData,
            'subjectBreakdown' => $this->subjectBreakdown,
            'dropOff'          => $this->dropOff,
            'contextCompletion' => $this->contextCompletion,
            'attempts'         => DiagnosticAttempt::with(['module', 'subject', 'user'])
                ->latest('started_at')
                ->paginate(25),
        ])->layout('layouts.app');
    }
}
