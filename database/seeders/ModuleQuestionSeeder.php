<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Module;
use App\Models\Question;
use App\Models\Concept;

class ModuleQuestionSeeder extends Seeder
{
    public function run()
    {
        $data = json_decode(file_get_contents(database_path('data/module_questions.json')), true);
        

        foreach ($data as $entry) {
            $module = Module::where('name', $entry['module_name'])->first();

            if (!$module) {
                echo "Module not found: " . $entry['module_name'] . "\n";
                continue;
            }

            // Find questions (case-insensitive, trimmed)
            $questions = Question::whereRaw('LOWER(TRIM(question)) = ?', [strtolower(trim($entry['question']))])->get();

            if ($questions->isEmpty()) {
                echo "Question not found: " . $entry['question'] . "\n";
                continue;
            }

            // Link questions to module
            $module->questions()->syncWithoutDetaching($questions->pluck('id')->toArray());
            echo "Linked " . count($questions) . " questions to module: " . $module->name . "\n";

            // Get all concepts for this module's subject
            $subjectConcepts = Concept::where('subject_id', $module->subject_id)->get();
            echo "Module '{$module->name}' has {$subjectConcepts->count()} concepts for its subject.\n";

            $conceptNames = $entry['concepts'] ?? [];
            
            foreach ($questions as $question) {
                foreach ($conceptNames as $conceptName) {
                    $concept = $subjectConcepts->first(function ($c) use ($conceptName) {
                        return strtolower(trim($c->name)) === strtolower(trim($conceptName));
                    });

                    if ($concept) {
                        $question->concepts()->syncWithoutDetaching($concept->id);
                        echo "  - Linked concept '{$conceptName}' to question '{$question->question}'.\n";
                    } else {
                        echo "⚠ Concept '{$conceptName}' not found in subject concepts for module '{$module->name}'\n";
                    }
                }
            }


        }
    }
}
