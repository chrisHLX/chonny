<?php

namespace App\Jobs;

use App\Http\Services\AiService;
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

    public function handle(AiService $aiService): void
    {
        $prompt = Prompt::find($this->promptId);

        if (!$prompt) {
            Log::warning("⚠️ AnswerPromptJob: prompt #{$this->promptId} not found.");
            return;
        }

        $prompt->update(['status' => 'processing']);

        Log::info("🚀 AnswerPromptJob: answering prompt #{$this->promptId}");

        try {
            $answer = $aiService->answerQuestion(
                $prompt->question,
                $prompt->context_snapshot,
                $prompt->user_id
            );

            $prompt->update(['answer' => $answer, 'status' => 'completed']);

            Log::info("✅ AnswerPromptJob: prompt #{$this->promptId} completed.");
        } catch (\Throwable $e) {
            $prompt->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error("❌ AnswerPromptJob: prompt #{$this->promptId} failed — {$e->getMessage()}");
        }
    }
}
