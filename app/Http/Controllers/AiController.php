<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\AiRequest;

use App\Jobs\TagJob;

use App\Http\Services\AiService;
use App\Http\Services\CreditService;

class AiController extends Controller
{
    protected AiService $aiService;
    protected CreditService $creditService;


    public function __construct(AiService $aiService, CreditService $creditService)
    {
        $this->aiService = $aiService;
        $this->creditService = $creditService;
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

}