<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Module;
use App\Models\Question;
use App\Models\Concept;

class ModuleContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->linkQuestionsToModules();
        $this->linkConceptsToQuestions();
    }

    // Pass 1: link questions to modules using module_questions.json
    private function linkQuestionsToModules(): void
    {
        $jsonPath = database_path('data/module_questions.json');

        if (!File::exists($jsonPath)) {
            $this->command->error("❌ module_questions.json not found");
            return;
        }

        $moduleData = json_decode(File::get($jsonPath), true);
        $linkedCount = 0;
        $failedCount = 0;

        foreach ($moduleData as $entry) {
            $module = Module::where('name', $entry['module_name'])->first();

            if (!$module) {
                $this->command->warn("⚠️ Module not found: {$entry['module_name']}");
                $failedCount++;
                continue;
            }

            $questions = Question::whereRaw(
                'LOWER(TRIM(question)) = ?',
                [strtolower(trim($entry['question']))]
            )->get();

            if ($questions->isEmpty()) {
                $this->command->warn("⚠️ Question not found: {$entry['question']}");
                $failedCount++;
                continue;
            }

            $module->questions()->syncWithoutDetaching($questions->pluck('id')->toArray());
            $linkedCount += $questions->count();
        }

        $this->command->info("✅ Questions linked to modules: {$linkedCount}, failed: {$failedCount}");
    }

    // Pass 2: link concepts to questions using the source question JSON files
    // These files contain concept metadata that module_questions.json does not have
    private function linkConceptsToQuestions(): void
    {
        $sourceFiles = [
            'data/questions.json',
            'data/lolquestions.json',
            'data/medical_questions.json',
        ];

        $linkedCount = 0;
        $skippedCount = 0;

        foreach ($sourceFiles as $filePath) {
            $jsonPath = database_path($filePath);

            if (!File::exists($jsonPath)) {
                $this->command->warn("⚠️ File not found: {$filePath}");
                continue;
            }

            $questionsData = json_decode(File::get($jsonPath), true);

            foreach ($questionsData as $data) {
                $conceptNames = $data['concepts'] ?? [];

                if (empty($conceptNames)) {
                    continue;
                }

                $question = Question::whereRaw(
                    'LOWER(TRIM(question)) = ?',
                    [strtolower(trim($data['question']))]
                )->first();

                if (!$question) {
                    $skippedCount++;
                    continue;
                }

                $attachedConceptIds = [];

                foreach ($question->modules as $module) {
                    $subjectConcepts = Concept::where('subject_id', $module->subject_id)->get();

                    foreach ($conceptNames as $conceptName) {
                        $concept = $subjectConcepts->first(function ($c) use ($conceptName) {
                            return strtolower(trim($c->name)) === strtolower(trim($conceptName));
                        });

                        if ($concept) {
                            $attachedConceptIds[] = $concept->id;
                        } else {
                            $this->command->warn("⚠️ Concept '{$conceptName}' not found for module '{$module->name}'");
                        }
                    }
                }

                if (!empty($attachedConceptIds)) {
                    $question->concepts()->syncWithoutDetaching(array_unique($attachedConceptIds));
                    $linkedCount++;
                }
            }
        }

        $this->command->info("✅ Concepts linked to questions: {$linkedCount}, skipped: {$skippedCount}");
    }
}
