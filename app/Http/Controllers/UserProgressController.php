<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Module;
use App\Models\Card;
use App\Models\Pipeline;
use App\Models\Question;


class UserProgressController extends Controller
{
    // display the users progress
    public function index()
    {
        $user = auth()->user();


        $pipeline = $user->pipelines()->latest()->with('steps')->first();
        \Log::info('User pipeline', ['pipeline' => $pipeline]);
        
        if ($pipeline === null) {
            return view('dashboard.pending');
        }

        $pipelineStatus = $pipeline->steps->where('name', 'Generate Card')->first();
        

        if ($pipelineStatus->status !== 'completed') {
            return view('dashboard.pending');
        }
        // modules user is enrolled in
        $modules = $user->modules()->with('questions')->get();

        $wrongQuestionIds = $user->answeredQuestions()
            ->whereColumn('attempts', '>', 'correct_count') // what is where column and how come where doesnt work
            ->pluck('questions.id');

        $wrongQuestions = Question::whereIn('id', $wrongQuestionIds)
            ->with('contents')
            ->get();

        

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
            'wrongQuestions' => $wrongQuestions,
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
