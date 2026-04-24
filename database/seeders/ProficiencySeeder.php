<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProficiencySeeder extends Seeder
{
    public function run(): void
    {
        $proficiencies = [
            // StarCraft 2 (subject_id: 1)
            ['subject_id' => 1, 'index' => 0, 'name' => 'Beginner', 'description' => 'New to the game, knows basic units and mechanics.'],
            ['subject_id' => 1, 'index' => 1, 'name' => 'Casual', 'description' => 'Understands the game but hasn\'t mastered build orders or matchups.'],
            ['subject_id' => 1, 'index' => 2, 'name' => 'Intermediate', 'description' => 'Can execute standard strategies, knows key timings and counters.'],
            ['subject_id' => 1, 'index' => 3, 'name' => 'Advanced', 'description' => 'Comfortable with multiple strategies, scouting, and adapting builds.'],
            ['subject_id' => 1, 'index' => 4, 'name' => 'Expert', 'description' => 'High-level competitive play, understands meta shifts, macro/micro optimization.'],

            // League of Legends (subject_id: 2)
            ['subject_id' => 2, 'index' => 0, 'name' => 'Beginner', 'description' => 'Knows basic champions and map layout.'],
            ['subject_id' => 2, 'index' => 1, 'name' => 'Casual', 'description' => 'Understands roles, summoner spells, and objectives.'],
            ['subject_id' => 2, 'index' => 2, 'name' => 'Intermediate', 'description' => 'Knows counters, lane matchups, and simple macro strategies.'],
            ['subject_id' => 2, 'index' => 3, 'name' => 'Advanced', 'description' => 'Can plan rotations, team fights, and advanced mechanics.'],
            ['subject_id' => 2, 'index' => 4, 'name' => 'Expert', 'description' => 'Competitive/stream-level strategic depth, understands meta and complex synergy.'],

            // Medicine (subject_id: 3)
            ['subject_id' => 3, 'index' => 0, 'name' => 'Elementary', 'description' => 'Very simple, child-friendly explanations of basic health and the human body.'],
            ['subject_id' => 3, 'index' => 1, 'name' => 'High School', 'description' => 'Basic anatomy, physiology, and medical literacy. Easy-to-read content.'],
            ['subject_id' => 3, 'index' => 2, 'name' => 'College', 'description' => 'Pre-medical level understanding, includes pathophysiology and pharmacology basics.'],
            ['subject_id' => 3, 'index' => 3, 'name' => 'Graduate', 'description' => 'Advanced knowledge, clinical reasoning, diagnosis, and therapeutics.'],
            ['subject_id' => 3, 'index' => 4, 'name' => 'Residency', 'description' => 'Hands-on skills, case-based reasoning, complex differential diagnosis.'],
            ['subject_id' => 3, 'index' => 5, 'name' => 'Specialist', 'description' => 'Expert-level, highly specialized, research-focused knowledge.'],

            // Music (subject_id: 4)
            ['subject_id' => 4, 'index' => 0, 'name' => 'Beginner', 'description' => 'Basic exposure to artistic concepts, tools, and creative exercises.'],
            ['subject_id' => 4, 'index' => 1, 'name' => 'Developing', 'description' => 'Can apply simple techniques and understands foundational principles.'],
            ['subject_id' => 4, 'index' => 2, 'name' => 'Intermediate', 'description' => 'Builds coherent work and explores style, composition, and critique.'],
            ['subject_id' => 4, 'index' => 3, 'name' => 'Advanced', 'description' => 'Uses deliberate technique, symbolism, and deeper artistic intent.'],
            ['subject_id' => 4, 'index' => 4, 'name' => 'Mastery', 'description' => 'Highly refined creative execution, interpretation, and original artistic voice.'],

            // Ancient History (subject_id: 5)
            ['subject_id' => 5, 'index' => 0, 'name' => 'Foundational', 'description' => 'Basic understanding of key ideas, people, and historical context.'],
            ['subject_id' => 5, 'index' => 1, 'name' => 'Introductory', 'description' => 'Can explain simple concepts and identify major themes.'],
            ['subject_id' => 5, 'index' => 2, 'name' => 'Analytical', 'description' => 'Begins comparing ideas, arguments, and historical perspectives.'],
            ['subject_id' => 5, 'index' => 3, 'name' => 'Advanced Analysis', 'description' => 'Can interpret complexity, critique arguments, and synthesize viewpoints.'],
            ['subject_id' => 5, 'index' => 4, 'name' => 'Scholarly', 'description' => 'Engages with nuanced theory, deep interpretation, and original insight.'],

            // Industrial Materials (subject_id: 6)
            ['subject_id' => 6, 'index' => 0, 'name' => 'Apprentice', 'description' => 'Learns basic tools, safety, and simple procedures.'],
            ['subject_id' => 6, 'index' => 1, 'name' => 'Junior', 'description' => 'Can complete supervised tasks and understands standard practices.'],
            ['subject_id' => 6, 'index' => 2, 'name' => 'Qualified', 'description' => 'Performs work independently with reliable competence.'],
            ['subject_id' => 6, 'index' => 3, 'name' => 'Advanced Tradesperson', 'description' => 'Handles complex work, diagnostics, and efficiency improvements.'],
            ['subject_id' => 6, 'index' => 4, 'name' => 'Master Tradesperson', 'description' => 'Deep expertise, precision, mentoring, and advanced troubleshooting.'],

            // Programming (subject_id: 7)
            ['subject_id' => 7, 'index' => 0, 'name' => 'Beginner', 'description' => 'Basic familiarity with digital tools and core technical ideas.'],
            ['subject_id' => 7, 'index' => 1, 'name' => 'Practical', 'description' => 'Can perform common tasks and understands simple systems.'],
            ['subject_id' => 7, 'index' => 2, 'name' => 'Intermediate', 'description' => 'Understands technical workflows and problem-solving patterns.'],
            ['subject_id' => 7, 'index' => 3, 'name' => 'Advanced', 'description' => 'Can design, troubleshoot, and connect multiple systems.'],
            ['subject_id' => 7, 'index' => 4, 'name' => 'Expert', 'description' => 'High-level technical reasoning, architecture, and optimization.'],

            // Global Macro (subject_id: 8)
            ['subject_id' => 8, 'index' => 0, 'name' => 'Novice', 'description' => 'Basic awareness of money, trade, and how businesses operate at a surface level.'],
            ['subject_id' => 8, 'index' => 1, 'name' => 'Foundational', 'description' => 'Understands core concepts such as supply and demand, budgeting, and simple financial statements.'],
            ['subject_id' => 8, 'index' => 2, 'name' => 'Competent', 'description' => 'Can interpret financial data, understand market dynamics, and apply basic economic principles.'],
            ['subject_id' => 8, 'index' => 3, 'name' => 'Proficient', 'description' => 'Analyses business strategy, investment decisions, and financial performance with confidence.'],
            ['subject_id' => 8, 'index' => 4, 'name' => 'Expert', 'description' => 'Deep understanding of economic systems, capital markets, corporate finance, and strategic thinking.'],
        ];

        foreach ($proficiencies as $proficiencyData) {
            DB::table('proficiencies')->updateOrInsert(
                ['subject_id' => $proficiencyData['subject_id'], 'index' => $proficiencyData['index']],
                $proficiencyData
            );
        }

        $this->command->info('✅ All proficiencies seeded successfully!');
    }
}
