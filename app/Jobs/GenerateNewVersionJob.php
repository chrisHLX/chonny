<?php
namespace App\Jobs;

use App\Models\Module;
use App\Models\Question;
use App\Services\QuestionGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateNewVersionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $module;
    protected $newVersion;
    protected $wrongQuestions;

    public function __construct(Module $module, string $newVersion, array $wrongQuestions)
    {
        $this->module = $module;
        $this->newVersion = $newVersion;
        $this->wrongQuestions = $wrongQuestions;
    }

    public function handle(QuestionGeneratorService $questionGenerator)
    {
        // Create new module in user_modules
        $newModule = Module::create([
            'version' => $this->newVersion,
            'parent_module_id' => $this->module->id, // Link to the attempt's module
            'user_id' => $this->module->user_id,
        ]);

        // Generate AI-targeted questions
        $questionsData = $questionGenerator->generate($this->wrongQuestions);
        foreach ($questionsData as $qData) {
            Question::create([
                'module_id' => $newModule->id,
                'question_text' => $qData['text'],
                'answer' => $qData['answer'],
                // Add other fields as needed
            ]);
        }

        // Save to user_module_history
        DB::table('user_module_history')->insert([
            'module_id' => $newModule->id,
            'parent_module_id' => $this->module->id,
            'wrong_questions' => json_encode($this->wrongQuestions), // Already sorted
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Optional: Log for monitoring
        \Log::info("New module version generated: {$this->newVersion} for user {$this->module->user_id}");
    }
}