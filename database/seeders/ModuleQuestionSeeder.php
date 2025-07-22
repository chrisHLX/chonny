<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Module;
use App\Models\Question;

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

            $questionIds = Question::where('question', $entry['question'])->pluck('id')->toArray();

            if (!empty($questionIds)) {
                $module->questions()->syncWithoutDetaching($questionIds);
                echo "Linked " . count($questionIds) . " questions to module: " . $module->name . "\n";
            }
        }
    }
}
