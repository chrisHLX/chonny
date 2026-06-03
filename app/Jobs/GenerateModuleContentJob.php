<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use App\Http\Services\AiService;
use App\Http\Services\ResearchService;
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
    protected string $focusContext;

    public function __construct(int $moduleId, int $contentStepId, array $questionSteps, int $userId, string $focusContext = '')
    {
        $this->moduleId      = $moduleId;
        $this->contentStepId = $contentStepId;
        $this->questionSteps = $questionSteps;
        $this->userId        = $userId;
        $this->focusContext  = $focusContext;
    }

    public function handle(AiService $aiService, ResearchService $researchService): void
    {
        $module = Module::find($this->moduleId);
        $step   = PipelineStep::find($this->contentStepId);

        if (! $module || ! $step) {
            return;
        }

        $step->update(['status' => 'running', 'started_at' => now()]);

        try {
            $subject     = $module->subject;
            $proficiency = $module->proficiencies()->first();

            // Research first so content is grounded in factual material
            $topic    = $module->name . ' — ' . $subject->name;
            $research = $researchService->fetchLatestMaterial(
                $topic,
                $subject->name,
                $this->userId,
                $module->subject_id,
                $module->id
            );
            $researchContext = $research['summary'] ?? '';

            $result = $aiService->generateExploreContent(
                $module->name,
                $subject,
                $proficiency,
                $this->userId,
                $this->focusContext,
                $researchContext
            );

            // Update module name/description from AI result
            $module->update([
                'name'        => $result['title'],
                'description' => $result['description'],
            ]);

            // Delete existing pages before writing fresh ones (safe on retry)
            $module->modulePages()->delete();

            foreach ($result['pages'] as $i => $page) {
                $module->modulePages()->create([
                    'title'       => $page['title'],
                    'content'     => $page['content'],
                    'page_number' => $i + 1,
                    'created_by'  => $this->userId,
                    'updated_by'  => $this->userId,
                ]);
            }

            $step->update(['status' => 'completed', 'completed_at' => now()]);

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
