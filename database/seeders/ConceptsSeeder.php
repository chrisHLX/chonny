<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Concept;
use App\Models\Subject;

class ConceptsSeeder extends Seeder
{
    public function run(): void
    {
        $json = File::get(database_path('data/concepts.json'));
        $concepts = json_decode($json, true);

        foreach ($concepts as $data) {
            $subject = Subject::where('name', $data['subject'])->first();

            if (!$subject) {
                $this->command->warn("Subject not found for concept: {$data['name']}");
                continue;
            }

            Concept::firstOrCreate(
                ['name' => $data['name'], 'subject_id' => $subject->id],
                ['description' => $data['description'] ?? null]
            );
        }

        $this->command->info('✅ Concepts seeded successfully.');
    }
}
