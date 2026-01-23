<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Http\Services\AiService;
use App\Http\Services\UserModuleService;
use App\Http\Services\SuggestionsService;
use App\Models\Module;
use App\Models\User;
use App\Models\Pipeline;
use App\Models\PipelineStep;

class SuggestionJob implements ShouldQueue // What is should que and what is implements
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    
    protected int $moduleID;
    protected int $userID;
    protected int $pipelineStepID;

    public function __construct(int $moduleID, int $userID, int $pipelineStepID)
    {
        $this->moduleID = $moduleID;
        $this->userID = $userID;
        $this->pipelineStepID = $pipelineStepID;
    }

    public function handle(AiService $aiService, UserModuleService $userModuleService, SuggestionsService $suggestionsService) 
    {
        //grab the models/collection instance
        $user = User::where('id', $this->userID)->first();
        $module = Module::where('id', $this->moduleID)->first();
        $step = PipelineStep::find($this->pipelineStepID);

        if (! $user || ! $module || ! $step) {
            return;
        }

        // mark step started
        $step->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $response = $userModuleService->nextModuleResponse($user, $module);

            // mark step complete
            $step->update([
                'status' => 'completed',
                'finished_at' => now(),
            ]);

            $this->checkPipelineCompletion($step->pipeline);
        } catch (\Throwable $e) {
            \Log::error("SuggestionJob failed inside nextModuleResponse(): ".$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $step->pipeline->update([
                'status' => 'failed',
            ]);

        
            throw $e; // keep queue behaviour consistent
        }
    }

    protected function checkPipelineCompletion(Pipeline $pipeline): void
    {
        $allDone = $pipeline->steps()
            ->whereIn('status', ['pending', 'running'])
            ->count() === 0;

        if ($allDone) {
            $pipeline->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }

   
  
    
    
}