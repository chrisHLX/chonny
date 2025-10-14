<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Module;
use App\Models\Concept;
use App\Models\Subject;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        // Get all subjects for the toggle
        $subjects = Subject::all();

        // Determine the current subject (default to the first if none selected)
        $currentSubjectId = $request->query('subject_id') ?? $subjects->first()?->id;

        if (!$currentSubjectId) {
            return redirect()->back()->with('error', 'No subjects found.');
        }

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
            ->with(['questions.users' => fn($q) => $q->where('user_id', $user->id)])
            ->get();

        // Leaderboard: averages mastery across concepts for all users in this subject
        $leaderboard = DB::table('user_concept_mastery')
            ->join('concepts', 'user_concept_mastery.concept_id', '=', 'concepts.id')
            ->join('users', 'user_concept_mastery.user_id', '=', 'users.id')
            ->where('concepts.subject_id', $currentSubjectId)
            ->select(
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
            'currentSubjectId',
            'modules',
            'createdModules',
            'concepts',
            'leaderboard'
        ));
    }

}
