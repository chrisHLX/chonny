<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Module;
use App\Models\Question;
use Illuminate\Http\Request;

class ModuleQuizController extends Controller
{
    //

    public function show(Module $module)
    {
        return view('modules.show', [
            'module' => $module,
            'questions' => $module->questions()->inRandomOrder()->where('difficulty', 'easy')->limit(5)->get(),
        ]);
    }

    public function start(Request $request, Module $module)
    {
        $user = auth()->user();

        // Attach if not already attached
        if (! $user->modules->contains($module->id)) {
            $user->modules()->attach($module->id, [
                'status' => 'in_progress',
                'current_difficulty' => 'easy',
                'last_activity_at' => now()
            ]);
        }

        return redirect()->route('modules.quiz', $module);
    }

}
