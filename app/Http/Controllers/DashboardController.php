<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Module;
use App\Models\Concept;
use App\Models\Subject;
use App\Models\Category;
use App\Models\UserNextStep;
use App\Http\Services\NextStepService;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        // Category/subject context only ever travels through URL query params (context-bar
        // pills use route_with_context()) — nothing persists it. Any link that omits them
        // (a plain route('dashboard'), the nav sidebar, a bookmark) used to silently reset to
        // Category::first()/Subject::first() regardless of what the user was last looking at.
        // Remember the last explicit selection in session so a contextless visit resumes there
        // instead of resetting to whatever happens to be first in the DB.
        $categoryId = $request->get('category_id') ?? session('context.category_id');

        // Default category if none selected or remembered
        if (!$categoryId) {
            $categoryId = Category::first()->id;
        }

        session(['context.category_id' => $categoryId]);

        $subjects = Subject::where('category_id', $categoryId)->get();

        // Default subject
        $currentSubjectId = $request->get('subject_id')
            ?? session('context.subject_id')
            ?? $subjects->first()?->id;

        // A remembered subject_id may belong to a different category than the one just
        // resolved above (e.g. session held a subject from before a category switch) —
        // don't let a stale cross-category value silently scope every query below to a
        // subject that isn't even in $subjects.
        if (!$subjects->contains('id', $currentSubjectId)) {
            $currentSubjectId = $subjects->first()?->id;
        }

        session(['context.subject_id' => $currentSubjectId]);

        // Find the most recently completed diagnostic module FOR THE CURRENTLY SELECTED SUBJECT ONLY —
        // a diagnostic profile from a different subject must never be shown against this subject's context.
        $completedDiagnostic = $user->modules()
            ->where('modules.type', 'diagnostic')
            ->where('subject_id', $currentSubjectId ?? 0)
            ->wherePivot('status', 'completed')
            ->orderByPivot('completed_at', 'desc')
            ->first();

        // Decode the diagnostic profile if available
        $diagnosticProfile = null;
        if ($completedDiagnostic && $completedDiagnostic->pivot->diagnostic_profile) {
            $diagnosticProfile = json_decode($completedDiagnostic->pivot->diagnostic_profile, true);
        }

        // Active next-step (task or module) for the selected subject — mirrors the same subject-scoping
        // invariant as $completedDiagnostic above (never "most recent across all subjects").
        $activeNextStep = UserNextStep::where('user_id', $user->id)
            ->where('subject_id', $currentSubjectId ?? 0)
            ->whereIn('step_type', ['task', 'module'])
            ->whereIn('status', ['pending', 'attempted'])
            ->latest()
            ->first();

        // If the recommended module has since been completed, flip the step and generate the
        // next one right here (synchronous, on dashboard load — deliberately not a queued job,
        // see NextStepService::checkAndCompleteModuleStep). No-ops instantly for task-type steps.
        if ($activeNextStep) {
            $activeNextStep = app(NextStepService::class)->checkAndCompleteModuleStep($activeNextStep);
        }

        // If the selected subject has no completed diagnostic, offer a subject-specific CTA
        // instead of silently showing nothing (or, previously, another subject's profile).
        $subjectDiagnosticModule = null;
        if (!$diagnosticProfile) {
            $subjectDiagnosticModule = Module::where('subject_id', $currentSubjectId ?? 0)
                ->where('type', 'diagnostic')
                ->where('published', true)
                ->where('status', 'ready')
                ->first();
        }

        // Whether the user has completed any non-diagnostic module in this subject —
        // diagnostic completions don't feed mastery, so mastery panels stay empty otherwise.
        $hasContentActivity = $user->modules()
            ->where('subject_id', $currentSubjectId ?? 0)
            ->where('modules.type', '!=', 'diagnostic')
            ->wherePivot('status', 'completed')
            ->exists();

        // Nudge: find a published diagnostic for this category the user hasn't completed yet
        $hasDiagnosticComplete = $user->modules()
            ->where('modules.type', 'diagnostic')
            ->wherePivot('status', 'completed')
            ->whereHas('subject', fn($q) => $q->where('category_id', $categoryId))
            ->exists();

        $diagnosticNudge = $hasDiagnosticComplete ? null : Module::whereHas('subject', fn($q) => $q->where('category_id', $categoryId))
            ->where('type', 'diagnostic')
            ->where('published', true)
            ->where('status', 'ready')
            ->first();

        // User modules filtered by subject
        $modules = $user->modules()
            ->where('subject_id', $currentSubjectId)
            ->get();

        // Modules created by user, filtered by subject
        $createdModules = Module::where('created_by', $user->id)
            ->where('subject_id', $currentSubjectId)
            ->get();

        // Concepts filtered by subject, with user-specific mastery for questions
        $concepts = Concept::where('subject_id', $currentSubjectId)
            ->with([
                'questions.users' => fn($q) => $q->where('user_id', $user->id),
                'userConceptMasteries' => fn($q) => $q->where('user_id', $user->id),
                ])
            ->get();

        // Leaderboard: averages mastery across concepts for all users in this subject
        $leaderboard = DB::table('user_concept_mastery')
            ->join('concepts', 'user_concept_mastery.concept_id', '=', 'concepts.id')
            ->join('users', 'user_concept_mastery.user_id', '=', 'users.id')
            ->where('concepts.subject_id', $currentSubjectId)
            ->select(
                'users.id',
                'users.name',
                DB::raw('AVG(user_concept_mastery.mastery_percentage) as total_mastery')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_mastery')
            ->limit(5)
            ->get();

        return view('dashboard', compact(
            'user',
            'subjects',
            'categoryId',
            'currentSubjectId',
            'modules',
            'createdModules',
            'concepts',
            'leaderboard',
            'hasContentActivity',
            'diagnosticNudge',
            'completedDiagnostic',
            'diagnosticProfile',
            'subjectDiagnosticModule',
            'activeNextStep'
        ));
    }

}
