<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subject;
use App\Models\Concept;
use Illuminate\Support\Facades\File;

class ConceptSeeder extends Seeder
{
    public function run(): void
    {
        $conceptFiles = [
            'data/concepts.json',
            'data/newconcepts.json',
        ];

        $loadedCount = 0;
        $skippedCount = 0;

        foreach ($conceptFiles as $filePath) {
            $jsonPath = database_path($filePath);

            if (!File::exists($jsonPath)) {
                $this->command->warn("⚠️ Concept file not found: {$jsonPath}");
                continue;
            }

            $concepts = json_decode(File::get($jsonPath), true);

            foreach ($concepts as $data) {
                $subject = Subject::where('name', $data['subject'])->first();

                if (!$subject) {
                    $this->command->warn("⚠️ Subject not found for concept: {$data['name']}");
                    $skippedCount++;
                    continue;
                }

                $concept = Concept::firstOrCreate(
                    ['name' => $data['name'], 'subject_id' => $subject->id],
                    ['description' => $data['description'] ?? null]
                );

                if ($concept->wasRecentlyCreated) {
                    $loadedCount++;
                }
            }
        }

        $this->command->info("✅ Concepts seeded! Created: {$loadedCount}, Skipped: {$skippedCount}");
    }
}
