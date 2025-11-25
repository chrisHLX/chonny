<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AiRequest;
use App\Http\Services\AiService;
use App\Http\Services\UserModuleService;
use App\Models\Module;
use App\Models\User;
use App\Jobs\SuggestionJob;

class AiController extends Controller
{
    protected AiService $aiService;
    protected UserModuleService $userModuleService;

    public function __construct(AiService $aiService, UserModuleService $userModuleService)
    {
        $this->aiService = $aiService;
        $this->userModuleService = $userModuleService;
    }

    
    //
    
    public function index() {

        $ai_requests = AiRequest::all();
        return view('Ai/ai_requests', compact('ai_requests'));
    }

    // Test function to call AiService test method on the home dashboard
    public function test(){
        //$this->aiService->testContent();
        //$this->aiService->createModule();
    }

    public function test2() {
        $userID = auth()->user()->id;
        $moduleID = 3;
        
        // dispatch job stores the response in the DB for retrieval.
        SuggestionJob::dispatch($moduleID, $userID);  
    }

    public function testOriginal(){
        //$this->aiService->addCredits();
        $user = auth()->user();
        $module = Module::where('id', 3)->first();

        $suggestions = $this->userModuleService->nextModuleResponse($user, $module);
        
        return view('modules.next-module', compact('suggestions'));
        
        //dd($response);    
    
        
    }
}