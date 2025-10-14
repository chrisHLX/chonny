<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Question;
use App\Models\Concept;

class QuestionConceptSeeder extends Seeder
{
    public function run()
    {
        $questionsData = json_decode(File::get(database_path('data/questions.json')), true);

        foreach ($questionsData as $data) {
            $question = Question::where('question', $data['question'])->first();

            if (!$question) {
                echo "⚠ Question not found in DB: {$data['question']}\n";
                continue;
            }

            $attachedConceptIds = [];

            // Loop through all modules this question is linked to
            foreach ($question->modules as $module) {
                // Get concepts for this module's subject
                $subjectConcepts = Concept::where('subject_id', $module->subject_id)->get();

                // Ensure $data['concepts'] is always an array
                $conceptNames = (array) ($data['concepts'] ?? []);

                foreach ($conceptNames as $conceptName) {
                    // Case-insensitive match against concepts for the module's subject
                    $concept = $subjectConcepts->first(function ($c) use ($conceptName) {
                        return strtolower(trim($c->name)) === strtolower(trim($conceptName));
                    });

                    if ($concept) {
                        $attachedConceptIds[] = $concept->id;
                        echo "  - Will link concept '{$conceptName}' to question '{$question->question}'\n";
                    } else {
                        echo "⚠ Concept '{$conceptName}' not found for module '{$module->name}' subject.\n";
                    }
                }
            }

            // Attach all unique concept IDs to the question
            if (!empty($attachedConceptIds)) {
                $question->concepts()->syncWithoutDetaching(array_unique($attachedConceptIds));
                echo "✅ Linked " . count(array_unique($attachedConceptIds)) . " concepts to question '{$question->question}'.\n";
            }
        }

        $this->command->info('✅ Question-Concept relationships seeded successfully.');
    }
}
