<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Models\UserAxisMastery;
use App\Models\UserConceptMastery;
use App\Models\UserConceptSkillMastery;
use App\Models\UserNextStep;
use App\Models\UserProfileEvidence;
use App\Models\UserProfileInsight;
use App\Models\UserSubjectContext;
use App\Models\UserTraitEvidence;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class WeakAreas extends Component
{
    use WithPagination;

    public string $tab = 'concepts';
    public int $threshold = 50;
    public ?int $viewingUserId = null;

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedThreshold(): void
    {
        $this->resetPage();
    }

    public function viewUser(int $userId): void
    {
        $this->viewingUserId = $userId;
    }

    public function closeUserDetail(): void
    {
        $this->viewingUserId = null;
    }

    public function getSummaryProperty(): array
    {
        $totalTracked = UserConceptMastery::distinct('user_id')->count('user_id');

        $usersWithWeak = UserConceptMastery::where('mastery_percentage', '<', $this->threshold)
            ->distinct('user_id')
            ->count('user_id');

        $avgMastery = round(UserConceptMastery::avg('mastery_percentage') ?? 0, 1);

        $skillBreakdown = UserConceptSkillMastery::select('skill_type', DB::raw('AVG(mastery_percentage) as avg'))
            ->groupBy('skill_type')
            ->pluck('avg', 'skill_type')
            ->map(fn($v) => round($v, 1))
            ->all();

        $weakestSkill = collect($skillBreakdown)->sortKeys()->sortBy(fn($v) => $v)->keys()->first() ?? '—';

        return compact('totalTracked', 'usersWithWeak', 'avgMastery', 'skillBreakdown', 'weakestSkill');
    }

    public function getWeakConceptsProperty()
    {
        return UserConceptMastery::with(['concept.subject'])
            ->select(
                'concept_id',
                DB::raw('ROUND(AVG(mastery_percentage), 1) as avg_mastery'),
                DB::raw('COUNT(DISTINCT user_id) as user_count'),
                DB::raw('SUM(CASE WHEN mastery_percentage < 50 THEN 1 ELSE 0 END) as struggling_count')
            )
            ->groupBy('concept_id')
            ->having('avg_mastery', '<', $this->threshold)
            ->orderBy('avg_mastery')
            ->paginate(15);
    }

    public function getWeakUsersProperty()
    {
        return UserConceptMastery::with('user')
            ->select(
                'user_id',
                DB::raw('ROUND(AVG(mastery_percentage), 1) as avg_mastery'),
                DB::raw('COUNT(*) as concept_count'),
                DB::raw('SUM(CASE WHEN mastery_percentage < 50 THEN 1 ELSE 0 END) as weak_count')
            )
            ->groupBy('user_id')
            ->having('weak_count', '>', 0)
            ->orderBy('avg_mastery')
            ->paginate(15);
    }

    public function getWeakAxesProperty()
    {
        return UserAxisMastery::with(['axis.category'])
            ->select(
                'axis_id',
                DB::raw('ROUND(AVG(mastery_percentage), 1) as avg_mastery'),
                DB::raw('COUNT(DISTINCT user_id) as user_count')
            )
            ->groupBy('axis_id')
            ->having('avg_mastery', '<', $this->threshold)
            ->orderBy('avg_mastery')
            ->get();
    }

    // --- User detail drill-down (diagnostic profile, diagnostic answers, wrong module answers) ---
    // Product-improvement view: lets an admin inspect exactly what a given user saw and answered,
    // not just their aggregate mastery numbers.

    public function getViewingUserProperty(): ?User
    {
        return $this->viewingUserId ? User::find($this->viewingUserId) : null;
    }

    /**
     * One entry per completed diagnostic the user has taken (a user can have one per subject —
     * WoW, SC2, LoL, etc. are separate diagnostic modules). Returns the raw AI-generated profile
     * JSON as stored on the module_user pivot, decoded — this is deliberately the full, unedited
     * output (not a hand-picked subset) so product review can see exactly what the AI produced,
     * including any malformed/unexpected fields.
     */
    public function getUserDiagnosticProfilesProperty(): \Illuminate\Support\Collection
    {
        if (!$this->viewingUserId) {
            return collect();
        }

        return User::findOrFail($this->viewingUserId)
            ->modules()
            ->where('type', 'diagnostic')
            ->wherePivot('status', 'completed')
            ->with('subject')
            ->get()
            ->map(fn ($module) => [
                'subject_name' => $module->subject?->name ?? '—',
                'module_name'  => $module->name,
                'completed_at' => $module->pivot->completed_at,
                'profile'      => $module->pivot->diagnostic_profile
                    ? json_decode($module->pivot->diagnostic_profile, true)
                    : null,
            ]);
    }

    /**
     * The raw diagnostic_mcq answers behind the trait scores in the profile above — one row per
     * question the user answered during a diagnostic, with which trait it fed and how many points.
     */
    public function getUserTraitEvidenceProperty()
    {
        if (!$this->viewingUserId) {
            return collect();
        }

        return UserTraitEvidence::where('user_id', $this->viewingUserId)
            ->with(['question', 'trait', 'module'])
            ->orderByDesc('answered_at')
            ->get();
    }

    /**
     * The raw survey_mcq (self-reported) answers from diagnostics — separate from trait evidence,
     * see the "traits vs profile evidence" distinction documented for the diagnostic system.
     */
    public function getUserProfileEvidenceProperty()
    {
        if (!$this->viewingUserId) {
            return collect();
        }

        return UserProfileEvidence::where('user_id', $this->viewingUserId)
            ->with(['question', 'module'])
            ->orderByDesc('answered_at')
            ->get();
    }

    /**
     * Regular (non-diagnostic) module questions this user has gotten wrong — last_answer_correct
     * is the most recently recorded attempt's outcome, so a question the user eventually got right
     * after retrying won't show here (attempts/correct_count are shown alongside for that context).
     */
    public function getUserWrongAnswersProperty()
    {
        if (!$this->viewingUserId) {
            return collect();
        }

        return User::findOrFail($this->viewingUserId)
            ->answeredQuestions()
            ->wherePivot('last_answer_correct', false)
            ->with('modules:id,name,type')
            ->orderByPivot('last_answered_at', 'desc')
            ->get();
    }

    /**
     * What the user has actually declared (race/class/role/...) per subject — see
     * SubjectContextService. Shown separately from the insight/next-step sections below because
     * it's the one input here that's never inferred: it directly gates which modules
     * findBestModuleForConcepts()/findModuleForConcept() will even consider recommending.
     */
    public function getUserDeclaredContextProperty()
    {
        if (!$this->viewingUserId) {
            return collect();
        }

        return UserSubjectContext::where('user_id', $this->viewingUserId)
            ->with(['dimension.subject', 'option'])
            ->get()
            ->sortBy(fn ($c) => [$c->dimension?->subject?->name, $c->dimension?->order]);
    }

    /**
     * The grounded, structured read of each diagnostic completion — distinct from the raw
     * diagnostic_profile JSON shown further down (which is the unedited AI output). This is what
     * the next-step system actually acts on: growth-area/strength concepts here are the same rows
     * UserNextStep::insight()/findBestModuleForConcepts() consume, not free text.
     */
    public function getUserProfileInsightsProperty()
    {
        if (!$this->viewingUserId) {
            return collect();
        }

        return UserProfileInsight::where('user_id', $this->viewingUserId)
            ->with(['subject', 'archetype', 'strengthConcepts', 'growthAreaConcepts'])
            ->orderByDesc('generated_at')
            ->get();
    }

    /**
     * The full recommendation trail — every UserNextStep this user has ever been given, in each
     * subject, oldest to newest (matches how NextStepService::buildHistoryContext() itself reads
     * the chain, so this reflects the same "story" the AI sees, not an arbitrary sort). Reflections
     * and their interpreted evidence are eager-loaded so the "why" behind a regeneration is visible
     * without a second click. This is the section the product-review use case actually cares about:
     * not just current mastery numbers, but what was recommended, whether it landed, and why the
     * system moved on to the next thing.
     */
    public function getUserNextStepsProperty()
    {
        if (!$this->viewingUserId) {
            return collect();
        }

        return UserNextStep::where('user_id', $this->viewingUserId)
            ->with(['subject', 'module', 'concept', 'reflection.evidence.concept'])
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn ($step) => $step->subject?->name ?? '—');
    }

    public function render()
    {
        return view('livewire.admin.weak-areas', [
            'summary'      => $this->summary,
            'weakConcepts' => $this->weakConcepts,
            'weakUsers'    => $this->weakUsers,
            'weakAxes'     => $this->weakAxes,
            'viewingUser'            => $this->viewingUser,
            'userDeclaredContext'    => $this->userDeclaredContext,
            'userProfileInsights'    => $this->userProfileInsights,
            'userNextSteps'          => $this->userNextSteps,
            'userDiagnosticProfiles' => $this->userDiagnosticProfiles,
            'userTraitEvidence'      => $this->userTraitEvidence,
            'userProfileEvidence'    => $this->userProfileEvidence,
            'userWrongAnswers'       => $this->userWrongAnswers,
        ])->layout('layouts.app');
    }
}
