<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;


class UserProgressController extends Controller
{
    // display the users progress
    public function index()
    {
        $user = auth()->user();

        $modules = $user->modules()->with('questions')->get();
        // log the  modules and their questions
        \Log::info('User modules with questions', ['modules' => $modules->toArray()]);
        $answeredQuestions = $user->answeredQuestions()
            ->with(['concepts', 'units'])
            ->withPivot(['attempts', 'correct_count', 'total_time_spent'])
            ->get();

        return view('dashboard.progress', [
            'modules' => $modules,
            'answeredQuestions' => $answeredQuestions,
        ]);
    }

}
