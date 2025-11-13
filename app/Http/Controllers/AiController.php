<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AiRequest;
use App\Http\Services\AiService;

class AiController extends Controller
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    
    //
    
    public function index() {

        $ai_requests = AiRequest::all();
        return view('Ai/ai_requests', compact('ai_requests'));
    }

    // Test function to call AiService test method on the home dashboard
    public function test(){
        $this->aiService->testContent();
    }

    public function test2(){
        $this->aiService->addCredits();
    }
}
