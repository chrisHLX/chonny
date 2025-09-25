<?php

namespace App\Listeners;

use App\Events\ModuleAttempted;
use App\Models\Module;
use Illuminate\Support\Facades\Log;
use App\Models\Question;
use App\Http\Services\AiService;

class GenerateNewModule
{
    /**
     * Handle the event.
     */
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function handle(ModuleAttempted $event): void
    {
        // If the user is on the 3rd attempt for the module, generate a new module if the user is struggling
        \Log::info("User {$event->history->user_id} has attempted module {$event->history->module_id} {$event->history->attempt_number} times.");
        if ($event->history->attempt_number >= 3) {
            $module = Module::find($event->history->module_id);
            if ($module) {
                // Logic to generate a new module (placeholder)
                Log::info("Generating a new module for user {$event->history->user_id} after 3 attempts on module {$module->id}");
                // Actual module generation logic would go here
                $IDs = $event->history->wrong_questions ?? [];

                $Questions = Question::whereIn('id', $IDs)->get();

                foreach ($Questions as $question) {
                    Log::info("The user gets the following question wrong:{$question->question}");
                }

                $newModuleTest = $this->aiService->versionQ($IDs);
            }
        }

    }
}

