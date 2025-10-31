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

    protected $question;
    protected $module;

    /**
     * Create a new job instance.
     */
    public function __construct(Question $question, Module $module)
    {
        $this->question = $question;
        $this->module = $module;
    }

    /**
     * Execute the job.
     */
    public function handle(ReviewQuestionService $reviewQuestionService)
    {
        Log::info("🚀 Generating review content for {$this->question->question} question in module {$this->module->name}");
        \Log::info('Question dump', [$this->question->toArray()]);

        $reviewContent = $reviewQuestionService->getReviewContent($this->question, $this->module);

        $cacheKey = "review_content:{$this->question->id}";

        // Cache each review content for 1 hour
        Cache::put($cacheKey, $reviewContent, now()->addHour());

        Log::info("✅ Cached review content for Question ID {$this->question->id} The Content is: " . $reviewContent);
    }
}
