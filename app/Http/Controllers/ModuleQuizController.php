<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Module;
use App\Models\Question;

class ModuleQuizController extends Controller
{
    //

    public function show(Module $module)
    {
        if (auth()->guest()) {
            if (!$module->published || $module->parent_id !== null) {
                return redirect()->route('login');
            }
            return view('modules.show', ['module' => $module]);
        }

        if (!auth()->user()->modules->contains($module->id)) {
            return redirect()->route('modules.show', $module->slug);
        }

        return view('modules.show', ['module' => $module]);
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
