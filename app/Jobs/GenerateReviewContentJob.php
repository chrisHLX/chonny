<?php

namespace App\Jobs;

use App\Models\Question;
use App\Models\Module;
use App\Http\Services\ReviewQuestionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateReviewContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $questionID;
    protected int $moduleID;
    protected int $userID;

    /**
     * Create a new job instance.
     */
    public function __construct(int $questionID, int $moduleID, int $userID)
    {
        $this->questionID = $questionID;
        $this->moduleID = $moduleID;
        $this->userID = $userID;
    }

    /**
     * Execute the job.
     */
    public function handle(ReviewQuestionService $reviewQuestionService)
    {
        $question = Question::find($this->questionID);
        $module = Module::find($this->moduleID);

        if (!$question || !$module) {
            Log::warning("⚠️ Question or Module not found for job: QuestionID={$this->questionID}, ModuleID={$this->moduleID}");
            return;
        }

        Log::info("🚀 Generating review content for '{$question->question}' in module '{$module->name}'");

        $skillType = $question->skill_type?->value ?? $question->skill_type ?? '';
        $reviewContent = $reviewQuestionService->getReviewContent($question, $module, $this->userID, $skillType);

        $cacheKey = "review_content:{$question->id}";

        Cache::put($cacheKey, $reviewContent, now()->addHour());

        Log::info("✅ Cached review content for Question ID {$question->id}: {$reviewContent}");
    }
}
