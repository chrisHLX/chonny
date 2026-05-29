<?php

namespace App\Jobs;

use App\Http\Services\AiService;
use App\Http\Services\ResearchService;
use App\Models\Prompt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnswerPromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $promptId) {}

    public function handle(AiService $aiService, ResearchService $researchService): void
    {
        $prompt = Prompt::find($this->promptId);

        if (!$prompt) {
            Log::warning("⚠️ AnswerPromptJob: prompt #{$this->promptId} not found.");
            return;
        }

        $prompt->update(['status' => 'processing']);

        Log::info("🚀 AnswerPromptJob: answering prompt #{$this->promptId} via {$prompt->model}");

        try {
            if ($prompt->model === 'gemini') {
                $result  = $researchService->answerQuestion(
                    $prompt->question,
                    $prompt->context_snapshot,
                    $prompt->user_id
                );
                $answer  = $result['answer'];
                $sources = $result['sources'];
            } else {
                $answer  = $aiService->answerQuestion(
                    $prompt->question,
                    $prompt->context_snapshot,
                    $prompt->user_id
                );
                $sources = [];
            }

            $prompt->update(['answer' => $answer, 'sources' => $sources, 'status' => 'completed']);

            Log::info("✅ AnswerPromptJob: prompt #{$this->promptId} completed.");
        } catch (\Throwable $e) {
            $prompt->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error("❌ AnswerPromptJob: prompt #{$this->promptId} failed — {$e->getMessage()}");
        }
    }
}
