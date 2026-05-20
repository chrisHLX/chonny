<?php

namespace App\Http\Services;

use App\Models\Question;
use App\Models\Content;
use App\Models\Module;
use Illuminate\Support\Facades\Log;

class ReviewQuestionService
{
    protected $aiService;

    public function __construct(AiService $aiService)
    {
        // Inject your AI service (could be GPT-3.5 wrapper or similar)
        $this->aiService = $aiService;
    }

    /**
     * Get review content for a question
     *
     * @param Question $question
     * @return Content|string
     */
    public function getReviewContent(Question $question, Module $module, $userID)
    {
        // Check if the question has an existing content block
        $content = $question->contents()->latest()->first();

        //Module Info
        $moduleInfo = "Module Name: " . $module->name . " - Subject: " . $module->subject['name'];

        if ($content) {
            Log::info("Found existing content for Question ID {$question->id}");
            return $content->content;
        }

        // If no content exists, generate one using AI
        Log::info("Generating AI content for Question ID {$question->id}");
        $generatedContent = $this->aiService->generateContentForQuestion($question, $moduleInfo, $userID);

        // Optionally save AI-generated content for future use
        $newContent = Content::create([
            'created_by' => null, // system/AI
            'content' => $generatedContent,
            'source' => 'AI-generated',
            'parent_id' => null,
        ]);

        // Attach the new content to the question
        $question->contents()->attach($newContent->id);

        return $generatedContent;
    }

    /**
     * Handle multiple weak questions
     *
     * @param \Illuminate\Support\Collection $questions
     * @return array
     */
    public function getReviewContentsForQuestions($questions, Module $module)
    {
        $reviews = [];

        foreach ($questions as $question) {
            $reviews[] = [
                'question_id' => $question->id,
                'question' => $question->question,
                'review_content' => $this->getReviewContent($question, $module),
            ];
        }

        return $reviews;
    }
}
