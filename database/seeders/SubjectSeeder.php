<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subject;
use App\Models\Category;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        // Lookup categories by name
        $games      = Category::where('name', 'Games')->first();
        $science    = Category::where('name', 'Science')->first();
        $arts       = Category::where('name', 'Arts')->first();
        $humanities = Category::where('name', 'Humanities')->first();
        $trades     = Category::where('name', 'Trades')->first();
        $tech       = Category::where('name', 'Technology')->first();

        $subjects = [
            [
                'name' => 'StarCraft 2',
                'description' => 'Learn and master SC2 strategies and mechanics.',
                'category_id' => $games->id,
            ],
            [
                'name' => 'League of Legends',
                'description' => 'Learn LoL champions, tactics, and strategies.',
                'category_id' => $games->id,
            ],
            [
                'name' => 'Medicine',
                'description' => 'Study medicine and the structure of the human body and its systems.',
                'category_id' => $science->id,
            ],
        ];

        foreach ($subjects as $subjectData) {
            Subject::firstOrCreate(
                ['name' => $subjectData['name']],
                $subjectData
            );
        }

        $this->command->info('✅ Subjects seeded successfully!');
    }
}
