<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Module;
use App\Models\Card;


class UserProgressController extends Controller
{
    // display the users progress
    public function indexOld()
    {
        $user = auth()->user();

        $modules = $user->modules()->with('questions')->get();
        
        $answeredQuestions = $user->answeredQuestions()
            ->with(['concepts', 'units'])
            ->withPivot(['attempts', 'correct_count', 'total_time_spent'])
            ->get();

        return view('dashboard.progress', [
            'modules' => $modules,
            'answeredQuestions' => $answeredQuestions,
        ]);
    }

    public function index()
    {
        $user = auth()->user();

        // modules user is enrolled in
        $modules = $user->modules()->with('questions')->get();

        // answered questions (existing)
        $answeredQuestions = $user->answeredQuestions()
            ->with(['concepts', 'units'])
            ->withPivot(['attempts', 'correct_count', 'total_time_spent'])
            ->get();

        // user cards
        $cards = \App\Models\Card::where('user_id', $user->id)
            ->with(['module', 'proficiency'])
            ->orderByDesc('created_at')
            ->get();

        return view('dashboard.progress', [
            'modules' => $modules,
            'answeredQuestions' => $answeredQuestions,
            'cards' => $cards,
        ]);
    }


    public function generateCard(User $user, Module $module, $accuracy, $attempts, $stats)
    {
        // get proficiency for the module
        $proficiency = $module->proficiencies()->first();

        // mint number = next card id
        $nextMint = (Card::max('mint_number') ?? 0) + 1;

        return Card::create([
            'user_id' => $user->id,
            'module_id' => $module->id,
            'proficiency_id' => $proficiency?->id,
            'stats' => $stats,
            'accuracy' => $accuracy,
            'attempts' => $attempts,
            'mint_number' => $nextMint,
            'edition' => 'First Edition',
            'image_path' => "cards/{$module->slug}.png",
        ]);
    }


}
