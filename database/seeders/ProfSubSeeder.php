<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Module;  

class ProfSubSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    
    public function run(): void
    {
        $proficiencies = [
            // Arts
            ['subject_id' => 4, 'index' => 0, 'name' => 'Beginner', 'description' => 'Basic exposure to artistic concepts, tools, and creative exercises.'],
            ['subject_id' => 4, 'index' => 1, 'name' => 'Developing', 'description' => 'Can apply simple techniques and understands foundational principles.'],
            ['subject_id' => 4, 'index' => 2, 'name' => 'Intermediate', 'description' => 'Builds coherent work and explores style, composition, and critique.'],
            ['subject_id' => 4, 'index' => 3, 'name' => 'Advanced', 'description' => 'Uses deliberate technique, symbolism, and deeper artistic intent.'],
            ['subject_id' => 4, 'index' => 4, 'name' => 'Mastery', 'description' => 'Highly refined creative execution, interpretation, and original artistic voice.'],

            // Humanities
            ['subject_id' => 5, 'index' => 0, 'name' => 'Foundational', 'description' => 'Basic understanding of key ideas, people, and historical context.'],
            ['subject_id' => 5, 'index' => 1, 'name' => 'Introductory', 'description' => 'Can explain simple concepts and identify major themes.'],
            ['subject_id' => 5, 'index' => 2, 'name' => 'Analytical', 'description' => 'Begins comparing ideas, arguments, and historical perspectives.'],
            ['subject_id' => 5, 'index' => 3, 'name' => 'Advanced Analysis', 'description' => 'Can interpret complexity, critique arguments, and synthesize viewpoints.'],
            ['subject_id' => 5, 'index' => 4, 'name' => 'Scholarly', 'description' => 'Engages with nuanced theory, deep interpretation, and original insight.'],

            // Technology
            ['subject_id' => 7, 'index' => 0, 'name' => 'Beginner', 'description' => 'Basic familiarity with digital tools and core technical ideas.'],
            ['subject_id' => 7, 'index' => 1, 'name' => 'Practical', 'description' => 'Can perform common tasks and understands simple systems.'],
            ['subject_id' => 7, 'index' => 2, 'name' => 'Intermediate', 'description' => 'Understands technical workflows and problem-solving patterns.'],
            ['subject_id' => 7, 'index' => 3, 'name' => 'Advanced', 'description' => 'Can design, troubleshoot, and connect multiple systems.'],
            ['subject_id' => 7, 'index' => 4, 'name' => 'Expert', 'description' => 'High-level technical reasoning, architecture, and optimization.'],

            // Trades
            ['subject_id' => 6, 'index' => 0, 'name' => 'Apprentice', 'description' => 'Learns basic tools, safety, and simple procedures.'],
            ['subject_id' => 6, 'index' => 1, 'name' => 'Junior', 'description' => 'Can complete supervised tasks and understands standard practices.'],
            ['subject_id' => 6, 'index' => 2, 'name' => 'Qualified', 'description' => 'Performs work independently with reliable competence.'],
            ['subject_id' => 6, 'index' => 3, 'name' => 'Advanced Tradesperson', 'description' => 'Handles complex work, diagnostics, and efficiency improvements.'],
            ['subject_id' => 6, 'index' => 4, 'name' => 'Master Tradesperson', 'description' => 'Deep expertise, precision, mentoring, and advanced troubleshooting.'],
        ];

        // Replace this with firstOrCreate logic to avoid duplicates if the seeder is run multiple times
        foreach ($proficiencies as $proficiencyData) {
            DB::table('proficiencies')->updateOrInsert(
                ['subject_id' => $proficiencyData['subject_id'], 'index' => $proficiencyData['index']],
                $proficiencyData
            );
        }
        // Update or Insert must be the equi
        
    }

}