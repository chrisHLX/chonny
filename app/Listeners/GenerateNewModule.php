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
        // need an if statement to check if the user has attempted the module 3 times
        \Log::info("User {$event->history->user_id} has attempted module {$event->history->module_id} {$event->history->attempt_number} times.");
        // How do we want this to work? 
        // What is the goal? To help the user learn the material better
        // We want to generate a new module that focuses on the questions the user got wrong
        // But what is the criteria for generating a new module?
        // Do modules to unlock new modules?
        if ($event->history->attempt_number >= 3) {
            $module = Module::find($event->history->module_id);
            if ($module) {
                // Logic to generate a new module (placeholder)
                Log::info("User Has attempted the module 3 times.");
                // Actual module generation logic would go here
                $IDs = $event->history->wrong_questions ?? [];

                $Questions = Question::whereIn('id', $IDs)->get();

                if ($Questions->isEmpty()) {
                    Log::info("No questions found for the provided IDs.");
                } else {
                    foreach ($Questions as $question) {
                        Log::info("The user gets the following question wrong:{$question->question}");
                    }
                }
                
                // What we want to send is the questions for the purpose of creating a new Module to help the user learn
               // $newModuleTest = $this->aiService->versionQ($IDs);
            }
        }

    }
}

