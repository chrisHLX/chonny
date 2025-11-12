<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Http\Services\AiService;
use App\Models\Module;

class GenerateQuestions implements ShouldQueue // What is should que and what is implements
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // this must be relating to the Illuminate uses above under namespace. 
    // How does this get called? dispatch::jobname?
    protected $type; // ['mcq', 'true_false', 'matching_pairs', 'ordering']
    protected $newModule; // what does protected mean? why do they have to be protected

    public function __construct($type, $newModule)
    {
        $this->type = $type;
        $this->newModule = $newModule;
        
    } // creates a new job instance? I noticed its within the class, is this 
    // a feature of object oriented programming, using the construct method within a class to allow the use of internal variables?

    public function handle(AiService $AiService) // this must be a built in function in jobs to handle the request and we are going to use a function in the AiService
    {
        //now we should have recieved the model that we are going to generate the questions for
        $name = $this->newModule->name;
        $description = $this->newModule->description;
        $module = $this->newModule;
        // we are going to send this info to the prompt builder
        $string = $this->PromptBuilder($name, $description);
        try {
            \Log::info("Generating questions for module: {$name} with type: {$this->type}");
            $response = $AiService->generateQuestions($this->type, $string, $this->newModule);
        } catch (\Exception $e) {
            Log::error("Error generating questions for module {$name}: {$e->getMessage()}");
        }


    }

    public function PromptBuilder($name, $description)
    {
        $prompt = <<<EOT
        Can you create questions for the following module {$name} which is about {$description}.
EOT;
        return $prompt;
    }
    
    
}