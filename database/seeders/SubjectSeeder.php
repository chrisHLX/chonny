<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subject;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            ['name' => 'StarCraft 2', 'description' => 'Learn and master SC2 strategies and mechanics.'],
            ['name' => 'League of Legends', 'description' => 'Learn LoL champions, tactics, and strategies.'],
            ['name' => 'Medicine', 'description' => 'Study medicine and the structure of the human body and its systems.'],
        ];

        foreach ($subjects as $subjectData) {
            Subject::firstOrCreate(['name' => $subjectData['name']], $subjectData);
        }

        $this->command->info('✅ Subjects seeded successfully!');
    }
}
