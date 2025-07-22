<?php 

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Concept;

class ConceptsSeeder extends Seeder
{
    public function run(): void
    {
        $json = File::get(database_path('data/concepts.json'));
        $concepts = json_decode($json, true);

        foreach ($concepts as $data) {
            Concept::firstOrCreate(
                ['name' => $data['name']],
                [
                    'type' => $data['type'],
                    'description' => $data['description'] ?? null,
                ]
            );
        }

        $this->command->info('✅ Concepts seeded from JSON.');
    }
}
