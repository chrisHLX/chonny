<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\User;
use App\Models\Concept;
use App\Models\Question;
use App\Http\Services\UserModuleService;


class CollectionController extends Controller
{

   
    protected UserModuleService $userModuleService;
    

    public function __construct(UserModuleService $userModuleService)
    {
        $this->userModuleService = $userModuleService;
    }

    public function index()
    {
        
        $user = auth()->user();

        $pipeline = $user->pipelines()
            ->latest()
            ->with('steps')
            ->firstWhere('type', 'quiz_completion');
        
        if (!$pipeline) {
            return view('dashboard.pending');
        }

        $generateStep = $pipeline->steps
            ->firstWhere('name', 'Generate Card');
        
        if (!$generateStep || $generateStep->status !== 'completed') {
            return view('dashboard.pending');
        }

        return view('collection.index');
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
