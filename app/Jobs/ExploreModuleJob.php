<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use App\Models\Module;
use App\Models\Pipeline;
use App\Models\PipelineStep;
use App\Http\Services\AiService;
use App\Http\Services\ResearchService;

class ExploreModuleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $moduleId;
    protected int $pipelineId;
    protected int $contentStepId;
    protected int $mcqStepId;
    protected int $trueFalseStepId;
    protected int $userId;
    protected string $intent;
    protected string $priorKnowledge;

    public function __construct(
        int $moduleId,
        int $pipelineId,
        int $contentStepId,
        int $mcqStepId,
        int $trueFalseStepId,
        int $userId,
        string $intent,
        string $priorKnowledge = ''
    ) {
        $this->moduleId        = $moduleId;
        $this->pipelineId      = $pipelineId;
        $this->contentStepId   = $contentStepId;
        $this->mcqStepId       = $mcqStepId;
        $this->trueFalseStepId = $trueFalseStepId;
        $this->userId          = $userId;
        $this->intent          = $intent;
        $this->priorKnowledge  = $priorKnowledge;
    }

    public function handle(AiService $aiService, ResearchService $researchService): void
    {
        $module      = Module::find($this->moduleId);
        $contentStep = PipelineStep::find($this->contentStepId);

        if (! $module || ! $contentStep) {
            return;
        }

        $contentStep->update(['status' => 'running', 'started_at' => now()]);

        try {
            $subject     = $module->subject;
            $proficiency = $module->proficiencies()->first();

            $topic          = $this->intent . ' — ' . $subject->name;
            $research       = $researchService->fetchLatestMaterial($topic, $subject->name, $this->userId, $module->subject_id, $module->id);
            $researchContext = $research['summary'] ?? '';

            $result = $aiService->generateExploreContent($this->intent, $subject, $proficiency, $this->userId, $this->priorKnowledge, $researchContext);

            $module->update([
                'name'        => $result['title'],
                'description' => $result['description'],
            ]);

            foreach ($result['pages'] as $i => $page) {
                $module->modulePages()->create([
                    'title'       => $page['title'],
                    'content'     => $page['content'],
                    'page_number' => $i + 1,
                    'created_by'  => $this->userId,
                    'updated_by'  => $this->userId,
                ]);
            }

            $contentStep->update(['status' => 'completed', 'completed_at' => now()]);

            GenerateQuestions::dispatch('mcq',        $this->moduleId, $this->mcqStepId,       $this->userId, 'explore');
            GenerateQuestions::dispatch('true_false',  $this->moduleId, $this->trueFalseStepId, $this->userId, 'explore');

        } catch (\Throwable $e) {
            Log::error("ExploreModuleJob failed for module {$this->moduleId}: " . $e->getMessage());

            $contentStep->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            Pipeline::find($this->pipelineId)?->update(['status' => 'failed']);
            $module->update(['status' => 'failed']);
        }
    }
}
