<?php

namespace App\Http\Controllers;

use App\Models\Pipeline;

class PipelineController extends Controller
{
    public function status(Pipeline $pipeline)
    {
        return response()->json([
            'status' => $pipeline->status,
            'steps' => $pipeline->steps->map(fn ($step) => [
                'name' => $step->name,
                'status' => $step->status,
                'error' => $step->error_message,
            ]),
        ]);
    }

    public function nextModule(Pipeline $pipeline)
    {
        $pipeline->load('steps');

        return view('pipelines.next-module', compact('pipeline'));
    }
    
}
