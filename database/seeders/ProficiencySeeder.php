<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Module;  

class ProficiencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //}
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
            ['subject_id' => 3, 'name' => 'High School', 'description' => 'Basic anatomy, physiology, and medical literacy. Easy-to-read content.'],
            ['subject_id' => 3, 'name' => 'College', 'description' => 'Pre-medical level understanding, introduces pathophysiology and pharmacology basics.'],
            ['subject_id' => 3, 'name' => 'Graduate', 'description' => 'Advanced knowledge, clinical reasoning, diagnosis, and therapeutics.'],
            ['subject_id' => 3, 'name' => 'Residency', 'description' => 'Hands-on skills, case-based reasoning, complex differential diagnosis.'],
            ['subject_id' => 3, 'name' => 'Specialist', 'description' => 'Expert-level, highly specialized, research-focused knowledge.'],
        ];

        DB::table('proficiencies')->insert($proficiencies);
    }
}

