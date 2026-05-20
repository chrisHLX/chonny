<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('categories')->insert([
            [
                'name' => 'Games',
                'description' => 'Video games, esports, board games, competitive gaming, and entertainment-based interactive experiences.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Arts',
                'description' => 'Creative disciplines such as visual arts, music, literature, design, and all forms of artistic expression.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Science',
                'description' => 'Scientific fields including biology, physics, chemistry, medicine, mathematics, and empirical research disciplines.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Humanities',
                'description' => 'Philosophy, history, culture, ethics, languages, and other studies related to human society and thought.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Trades',
                'description' => 'Practical and vocational trades such as mechanics, construction, hydraulics, electrical work, and industrial skills.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Technology',
                'description' => 'Computing, programming, software development, AI, cybersecurity, networking, and modern technical fields.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

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

        $proficiencies = [
            // StarCraft 2
            ['subject_id' => 1, 'name' => 'Beginner', 'description' => 'New to the game, knows basic units and mechanics.'],
            ['subject_id' => 1, 'name' => 'Casual', 'description' => 'Understands the game but hasn’t mastered build orders or matchups.'],
            ['subject_id' => 1, 'name' => 'Intermediate', 'description' => 'Can execute standard strategies, knows key timings and counters.'],
            ['subject_id' => 1, 'name' => 'Advanced', 'description' => 'Comfortable with multiple strategies, scouting, and adapting builds.'],
            ['subject_id' => 1, 'name' => 'Expert', 'description' => 'High-level competitive play, understands meta shifts, macro/micro optimization.'],

            // League of Legends
            ['subject_id' => 2, 'name' => 'Beginner', 'description' => 'Knows basic champions and map layout.'],
            ['subject_id' => 2, 'name' => 'Casual', 'description' => 'Understands roles, summoner spells, and objectives.'],
            ['subject_id' => 2, 'name' => 'Intermediate', 'description' => 'Knows counters, lane matchups, and simple macro strategies.'],
            ['subject_id' => 2, 'name' => 'Advanced', 'description' => 'Can plan rotations, team fights, and advanced mechanics.'],
            ['subject_id' => 2, 'name' => 'Expert', 'description' => 'Competitive/stream-level strategic depth, understands meta and complex synergy.'],

            // Medicine
            ['subject_id' => 3, 'name' => 'Elementary', 'description' => 'Very simple, child-friendly explanations of basic health and the human body. Uses everyday examples, no technical terms.'],
            ['subject_id' => 3, 'name' => 'High School', 'description' => 'Basic anatomy, physiology, and medical literacy. Easy-to-read content.'],
            ['subject_id' => 3, 'name' => 'College', 'description' => 'Pre-medical level understanding, introduces pathophysiology and pharmacology basics.'],
            ['subject_id' => 3, 'name' => 'Graduate', 'description' => 'Advanced knowledge, clinical reasoning, diagnosis, and therapeutics.'],
            ['subject_id' => 3, 'name' => 'Residency', 'description' => 'Hands-on skills, case-based reasoning, complex differential diagnosis.'],
            ['subject_id' => 3, 'name' => 'Specialist', 'description' => 'Expert-level, highly specialized, research-focused knowledge.'],
        ];

        DB::table('proficiencies')->insert($proficiencies);
        
        
    }
}
