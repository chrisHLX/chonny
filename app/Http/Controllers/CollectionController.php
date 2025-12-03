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

        $module = $user->modules()->with('questions')->first();
        
        // $userStats = $this->userModuleService->getUserModuleStats($user, $module);
        $moduleQuestions = $module->questions->pluck('concepts')->toArray();


        dd($moduleQuestions);
        return view('collection.index', compact('modules', 'moduleQuestions'));

    }
}
