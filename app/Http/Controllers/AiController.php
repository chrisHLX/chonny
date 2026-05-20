<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\AiRequest;
use App\Models\Module;
use App\Models\User;
use App\Models\ModuleSuggestions;

use App\Jobs\SuggestionJob;
use App\Jobs\TagJob;

use App\Http\Services\AiService;
use App\Http\Services\UserModuleService;
use App\Http\Services\CreditService;
use App\Http\Services\CardGenerationService;

class AiController extends Controller
{
    protected AiService $aiService;
    protected UserModuleService $userModuleService;
    protected CreditService $creditService;


    public function __construct(AiService $aiService, UserModuleService $userModuleService, CreditService $creditService, CardGenerationService $cardGenerationService)
    {
        $this->aiService = $aiService;
        $this->userModuleService = $userModuleService;
        $this->creditService = $creditService;
        $this->cardGenerationService = $cardGenerationService;
    }

    
    //
    
    public function index() {

        $ai_requests = AiRequest::all();
        return view('Ai/ai_requests', compact('ai_requests'));
    }

    // Function to generate and add tags to module_tag table based on module content
    public function test2(){

        TagJob::dispatch();
    
    }

    //add credits
    public function test() {
        $user = auth()->user()->id;
        // $module = auth()->user()->modules()->first();
        
        $this->creditService->addAiCredits($user, 100, "sign up credits");

        return redirect()->route('dashboard');

        // return $this->aiService->generateQuestions('ordering', 'test content', $module, $user);
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