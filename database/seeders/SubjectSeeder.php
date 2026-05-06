<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subject;
use App\Models\Category;

// This seeder uses firstOrCreate, which checks for existing records with the same 'name' before creating a new one. So if you run this seeder again after the initial seeding, it will not create duplicates for subjects that already exist. It will only create new subjects if they do not already exist in the database.
// How would I run this seeder php artisan db:seed --class=SubjectSeeder
class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $games      = Category::where('name', 'Games')->first();
        $science    = Category::where('name', 'Science')->first();
        $arts       = Category::where('name', 'Arts')->first();
        $humanities = Category::where('name', 'Humanities')->first();
        $trades     = Category::where('name', 'Trades')->first();
        $tech       = Category::where('name', 'Technology')->first();
        $commerce   = Category::where('name', 'Commerce')->first();

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
                'name' => 'World of Warcraft: The War Within',
                'description' => 'Master World of Warcraft gameplay, PvP strategy, and class mechanics in the War Within expansion.',
                'category_id' => $games->id,
            ],
            [
                'name' => 'Medicine',
                'description' => 'Study medicine and the structure of the human body and its systems.',
                'category_id' => $science->id,
            ],
            [
                'name' => 'Music',
                'description' => 'Study music theory, composition, and performance across genres and styles.',
                'category_id' => $arts->id,
            ],
            [
                'name' => 'Ancient History',
                'description' => 'Study past ancient events and their impact on the present.',
                'category_id' => $humanities->id,
            ],
            [
                'name' => 'Industrial Materials',
                'description' => 'Learn the principles and practices of industrial materials, including metallurgy, composites, and manufacturing processes.',
                'category_id' => $trades->id,
            ],
            [
                'name' => 'Programming',
                'description' => 'Learn coding languages, software development, and computer science fundamentals.',
                'category_id' => $tech->id,
            ],
            [
                'name' => 'Global Macro',
                'description' => 'Analyze how global events, interest rates, and systemic feedback loops (Reflexivity) drive market prices and national economies.',
                'category_id' => $commerce->id,
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