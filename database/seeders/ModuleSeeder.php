<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Module;
use App\Models\Proficiency;
use App\Models\Subject;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('data/modules.json');

        if (!File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: {$jsonPath}");
            return;
        }

        $modules = json_decode(File::get($jsonPath), true);

        foreach ($modules as $data) {
            $subject = Subject::where('name', $data['subject'])->first();

            if (!$subject) {
                $this->command->warn("Subject not found for module: {$data['name']}");
                continue;
            }

            $newModule = Module::firstOrCreate(
                ['name' => $data['name'], 'subject_id' => $subject->id],
                [
                    'description' => $data['description'] ?? null,
                    'race' => $data['race'] ?? null,
                    'published' => $data['published'] ?? false,
                    'created_by' => $data['created_by'] ?? null,
                ]
            );

            $newModule->proficiencies()->attach(
                Proficiency::where('name', $data['proficiency'])
                    ->where('subject_id', $subject->id)
                    ->firstOrFail()->id
            );

        }



        $this->command->info('✅ Modules seeded successfully!');
    }
}
