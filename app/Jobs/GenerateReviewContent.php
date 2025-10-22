<?php

namespace App\Jobs;

use App\Http\Services\ReviewQuestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateReviewContent implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected $consecutiveFails;
    protected $module;

    public function __construct($consecutiveFails, $module)
    {
        $this->consecutiveFails = $consecutiveFails;
        $this->module = $module;
    }

    public function handle(ReviewQuestionService $reviewService)
    {
        \Log::info("Processing review content in background for module: {$this->module->name}");

        // Call your existing service
        $reviewService->getReviewContentsForQuestions($this->consecutiveFails, $this->module);

        \Log::info("Review content generation complete for module: {$this->module->name}");
    }
}
