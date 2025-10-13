<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Module;
use App\Models\Concept;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $modules = $user->modules()->get();
        $createdModules = Module::where('created_by', $user->id)->get();

        $concepts = Concept::with([
            'questions.users' => fn($q) => $q->where('user_id', $user->id)
        ])->get();

        $leaderboard = DB::table('user_concept_mastery')
            ->join('users', 'user_concept_mastery.user_id', '=', 'users.id')
            ->select(
                'users.name',
                DB::raw('AVG(user_concept_mastery.mastery_percentage) as total_mastery')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_mastery')
            ->limit(5)
            ->get();

        return view('dashboard', compact('user', 'modules', 'createdModules', 'concepts', 'leaderboard'));
    }
}
