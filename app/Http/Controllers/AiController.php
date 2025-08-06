<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AiRequest;

class AiController extends Controller
{
    //
    public function index() {

        $ai_requests = AiRequest::all();
        return view('Ai/ai_requests', compact('ai_requests'));
    }
}
