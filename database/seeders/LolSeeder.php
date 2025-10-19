<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Module;
use App\Models\Question;
use App\Models\Concept;

class LolSeeder extends Seeder
{
    public function run()
    {
        // Load the JSON file containing all your questions (e.g. Laning Fundamentals)
        $questionsData = json_decode(file_get_contents(database_path('data/lolquestions.json')), true);

        // Define which module to link these questions to
        $moduleName = 'Laning Fundamentals';

        // Find the module in the database
        $module = Module::where('name', $moduleName)->first();

        if (!$module) {
            echo "❌ Module not found: {$moduleName}\n";
            return;
        }

        // Get all concepts related to this module's subject
        $subjectConcepts = Concept::where('subject_id', $module->subject_id)->get();
        echo "Module '{$module->name}' has {$subjectConcepts->count()} concepts for its subject.\n";

        foreach ($questionsData as $data) {
            $questionText = trim(strtolower($data['question']));

            // Find the question in DB (case-insensitive)
            $question = Question::whereRaw('LOWER(TRIM(question)) = ?', [$questionText])->first();

            if (!$question) {
                echo "⚠️ Question not found: {$data['question']}\n";
                continue;
            }

            // Link question to module
            $module->questions()->syncWithoutDetaching([$question->id]);
            echo "✅ Linked question: '{$data['question']}' to module '{$module->name}'\n";

            // Link related concepts (if provided)
            $conceptNames = $data['concepts'] ?? [];
            foreach ($conceptNames as $conceptName) {
                $concept = $subjectConcepts->first(function ($c) use ($conceptName) {
                    return strtolower(trim($c->name)) === strtolower(trim($conceptName));
                });

                if ($concept) {
                    $question->concepts()->syncWithoutDetaching([$concept->id]);
                    echo "   ↳ Linked concept '{$conceptName}'\n";
                } else {
                    echo "   ⚠ Concept '{$conceptName}' not found in subject concepts for module '{$module->name}'\n";
                }
            }
        }

        echo "\n🎉 Seeder complete! All matching questions linked to '{$moduleName}'.\n";
    }
}