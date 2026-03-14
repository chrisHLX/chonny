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

use App\Models\Module;
use App\Models\Pipeline;
use App\Models\PipelineStep;

class GenerateQuestions implements ShouldQueue // ShouldQueue is an interface that tells Laravel this job should be queued)
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // this must be relating to the Illuminate uses above under namespace. 
    // How does this get called? dispatch::jobname?
    protected $type; // ['mcq', 'true_false', 'matching_pairs', 'ordering']
    protected $newModuleID; // protected variable to hold the module model
    protected $pipelineStepID;
    protected $userID;

    public function __construct($type, $newModuleID, $pipelineStepID, $userID)
    {
        $this->type = $type;
        $this->newModuleID = $newModuleID;
        $this->pipelineStepID = $pipelineStepID;
        $this->userID = $userID;
    }

    public function handle(AiService $AiService) // this must be a built in function in jobs to handle the request and we are going to use a function in the AiService
    {
        
        $newModule = Module::find($this->newModuleID);

        $userID = $this->userID;
        $name = $newModule->name;
        $description = $newModule->description;
        $subject = $newModule->subject;
        $module = $newModule;

        $step = PipelineStep::find($this->pipelineStepID);

        if (! $module || ! $step) {
            return;
        }

        // mark step started
        $step->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
        
        $string = $this->PromptBuilder($name, $description, $subject);
        try {
            \Log::info("Generating questions for module: {$name} with type: {$this->type}");
            $response = $AiService->generateQuestions($this->type, $string, $newModule, $userID);

            // mark step complete
            $step->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $module->update(['status' => 'ready']);

            $this->checkPipelineCompletion($step->pipeline);

        } catch (\Throwable $e) {
            Log::error("Error generating questions for module {$name}: {$e->getMessage()}");
            $step->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            $this->checkPipelineCompletion($step->pipeline);
            $module->update(['status' => 'failed']);
            throw $e; // keep queue behaviour consistent
        }


    }

    public function PromptBuilder($name, $description, $subject)
    {
        $prompt = <<<EOT
        Can you create questions for the following module {$name} which is about {$description}. Must be directly relavant to the subject: {$subject}.
        EOT;

        return $prompt;
    }

    protected function checkPipelineCompletion(Pipeline $pipeline): void
    {
        $hasFailed = $pipeline->steps()
            ->where('status', 'failed')
            ->exists();

        $allDone = ! $pipeline->steps()
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($allDone && ! $hasFailed) {
            $pipeline->update(['status' => 'completed']);
        } elseif ($hasFailed) {
            $pipeline->update(['status' => 'failed']);
        }
    }

    
    
}