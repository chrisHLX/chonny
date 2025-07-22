<?php

namespace Database\Seeders;

use App\Models\Module;

use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\File;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $jsonPath = database_path('data/modules.json');

        if (!File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: {$jsonPath}");
            return;
        }

        $data = json_decode(File::get($jsonPath), true);

        foreach ($data as $moduleData) {
            Module::firstOrCreate(
                [
                    'name' => $moduleData['name'],
                    'description' => $moduleData['description'] ?? null,
                    'race' => $moduleData['race'] ?? null,
                    'difficulty_level' => $moduleData['difficulty_level'] ?? null,
                    'published' => $moduleData['published'] ?? false,
                    'created_by' => $moduleData['created_by'] ?? null,
                ]
            );
        }

        $this->command->info('✅ Modules seeded successfully!');
    }
}
