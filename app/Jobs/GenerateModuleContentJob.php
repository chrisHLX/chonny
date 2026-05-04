<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use App\Http\Services\AiService;
use App\Models\Module;
use App\Models\ModulePage;
use App\Models\PipelineStep;

class GenerateModuleContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $moduleId;
    protected int $contentStepId;
    protected array $questionSteps; // ['mcq' => stepId, 'true_false' => stepId, ...]
    protected int $userId;

    public function __construct(int $moduleId, int $contentStepId, array $questionSteps, int $userId)
    {
        $this->moduleId      = $moduleId;
        $this->contentStepId = $contentStepId;
        $this->questionSteps = $questionSteps;
        $this->userId        = $userId;
    }

    public function handle(AiService $aiService): void
    {
        $module = Module::find($this->moduleId);
        $step   = PipelineStep::find($this->contentStepId);

        if (! $module || ! $step) {
            return;
        }

        $step->update(['status' => 'running', 'started_at' => now()]);

        try {
            $content = $aiService->generateModuleContent($module, $this->userId);

            ModulePage::create([
                'module_id'   => $module->id,
                'title'       => $module->name,
                'content'     => $content,
                'page_number' => 1,
                'created_by'  => $this->userId,
                'updated_by'  => $this->userId,
            ]);

            $step->update(['status' => 'completed', 'completed_at' => now()]);

            // Pipeline check before proceeding — re-query to confirm the update persisted
            $step->refresh();
            if ($step->status !== 'completed') {
                return;
            }

            foreach ($this->questionSteps as $type => $stepId) {
                GenerateQuestions::dispatch($type, $this->moduleId, $stepId, $this->userId, 'suggestions');
            }

        } catch (\Throwable $e) {
            Log::error("GenerateModuleContentJob failed for module {$this->moduleId}: {$e->getMessage()}");

            $step->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            $step->pipeline->markFailed($e->getMessage());

            throw $e;
        }
    }
}
