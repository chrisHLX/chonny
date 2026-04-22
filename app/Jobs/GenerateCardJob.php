<?php

namespace App\Jobs;

use App\Models\Module;
use App\Models\User;
use App\Http\Services\CardGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Pipeline;
use App\Models\PipelineStep;


class GenerateCardJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels, Dispatchable;

    protected $userId;
    protected $moduleId;
    protected int $pipelineStepId;

    public function __construct(int $userId, int $moduleId, int $pipelineStepId)
    {
        $this->userId = $userId;
        $this->moduleId = $moduleId;
        $this->pipelineStepId = $pipelineStepId;
    }

    public function handle(CardGenerationService $service)
    {
        $user = User::find($this->userId);
        $module = Module::find($this->moduleId);
        $step = PipelineStep::find($this->pipelineStepId);

        if (! $user || ! $module || ! $step) {
            return;
        }

        // mark step started
        $step->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $service->generateFor($this->userId, $this->moduleId);
            // mark step complete
            $step->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->checkPipelineCompletion($step->pipeline);
        } catch (\Throwable $e) {
            \Log::error('Card generation failed', [
                'user_id' => $this->userId,
                'module_id' => $this->moduleId,
                'error' => $e->getMessage(),
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $step->pipeline->update([
                'status' => 'failed',
            ]);

            throw $e;
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
