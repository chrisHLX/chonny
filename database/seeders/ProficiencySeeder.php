<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Subject;

class ProficiencySeeder extends Seeder
{
    public function run(): void
    {
        $ids = Subject::whereIn('name', [
            'StarCraft 2',
            'League of Legends',
            'World of Warcraft: The War Within',
            'Poker',
            'Medicine',
            'Music',
            'Ancient History',
            'Industrial Materials',
            'Programming',
            'Global Macro',
        ])->pluck('id', 'name');

        $proficiencies = [
            // StarCraft 2
            [
                'subject_id' => $ids['StarCraft 2'], 'index' => 0, 'name' => 'Beginner',
                'description' => 'New to the game, learning basic units, controls, and core mechanics.',
                'outcomes' => json_encode([
                    'Understands the basic goal of the game (economy, army, win condition)',
                    'Can consistently produce workers and basic combat units',
                    'Recognises core buildings (e.g. Barracks, Gateway, Hatchery)',
                    'Uses basic controls (selecting units, issuing commands, camera movement)',
                    'Avoids long periods of inactivity (no production for extended time)',
                ]),
            ],
            [
                'subject_id' => $ids['StarCraft 2'], 'index' => 1, 'name' => 'Casual',
                'description' => 'Understands the game but lacks consistency and structured play.',
                'outcomes' => json_encode([
                    'Maintains worker production with minimal gaps in the early game',
                    'Avoids major supply blocks during early stages',
                    'Follows a simple build order up to an early goal (e.g. first expansion or unit timing)',
                    'Produces units consistently instead of banking large amounts of resources',
                    'Recognises basic threats (early rushes, obvious attacks)',
                ]),
            ],
            [
                'subject_id' => $ids['StarCraft 2'], 'index' => 2, 'name' => 'Intermediate',
                'description' => 'Can execute standard strategies and understands key game timings.',
                'outcomes' => json_encode([
                    'Executes a standard build order to its first timing without major errors',
                    'Maintains continuous worker production while building an army',
                    'Spends resources efficiently with minimal floating',
                    'Uses scouting to identify opponent\'s early strategy',
                    'Responds correctly to common early-game aggression (e.g. rushes, early pushes)',
                ]),
            ],
            [
                'subject_id' => $ids['StarCraft 2'], 'index' => 3, 'name' => 'Advanced',
                'description' => 'Comfortable adapting strategy, multitasking, and controlling the map.',
                'outcomes' => json_encode([
                    'Adjusts build order based on scouting information',
                    'Maintains production while controlling units in multiple locations',
                    'Balances economy and army production based on game state',
                    'Controls map vision and denies opponent expansions',
                    'Executes coordinated attacks or defenses with proper unit positioning',
                ]),
            ],
            [
                'subject_id' => $ids['StarCraft 2'], 'index' => 4, 'name' => 'Expert',
                'description' => 'High-level competitive understanding with refined execution and decision-making.',
                'outcomes' => json_encode([
                    'Adapts strategy dynamically throughout the game based on opponent behavior',
                    'Maintains near-continuous production across all structures under pressure',
                    'Optimises economy, upgrades, and army composition simultaneously',
                    'Applies consistent pressure while defending and expanding efficiently',
                    'Identifies and exploits opponent weaknesses in real time',
                ]),
            ],

            // League of Legends
            ['subject_id' => $ids['League of Legends'], 'index' => 0, 'name' => 'Beginner', 'description' => 'Knows basic champions and map layout.'],
            ['subject_id' => $ids['League of Legends'], 'index' => 1, 'name' => 'Casual', 'description' => 'Understands roles, summoner spells, and objectives.'],
            ['subject_id' => $ids['League of Legends'], 'index' => 2, 'name' => 'Intermediate', 'description' => 'Knows counters, lane matchups, and simple macro strategies.'],
            ['subject_id' => $ids['League of Legends'], 'index' => 3, 'name' => 'Advanced', 'description' => 'Can plan rotations, team fights, and advanced mechanics.'],
            ['subject_id' => $ids['League of Legends'], 'index' => 4, 'name' => 'Expert', 'description' => 'Competitive/stream-level strategic depth, understands meta and complex synergy.'],

            // World of Warcraft: The War Within
            [
                'subject_id' => $ids['World of Warcraft: The War Within'], 'index' => 0, 'name' => 'Beginner',
                'description' => 'New to PvP, learning the three roles, basic abilities, and how to survive in unstructured combat.',
                'outcomes' => json_encode([
                    'Understands the purpose of Tank, Healer, and DPS roles in PvP',
                    'Knows what crowd control is and can identify common CC types (stun, root, silence)',
                    'Uses the PvP Trinket to break crowd control at appropriate moments',
                    'Understands Line of Sight and uses terrain to avoid incoming damage',
                    'Can identify which enemy to focus in a basic skirmish',
                ]),
            ],
            [
                'subject_id' => $ids['World of Warcraft: The War Within'], 'index' => 1, 'name' => 'Casual',
                'description' => 'Understands PvP fundamentals and participates actively in battlegrounds and skirmishes.',
                'outcomes' => json_encode([
                    'Understands Diminishing Returns and avoids wasting CC chains',
                    'Tracks own defensive cooldowns and uses them reactively',
                    'Communicates target switches in group PvP',
                    'Maintains basic positioning in battlegrounds (range, LoS, clustering)',
                    'Recognises the enemy team composition and adjusts target priority',
                ]),
            ],
            [
                'subject_id' => $ids['World of Warcraft: The War Within'], 'index' => 2, 'name' => 'Intermediate',
                'description' => 'Competes in arena or rated battlegrounds with awareness of cooldowns, CC chains, and composition.',
                'outcomes' => json_encode([
                    'Tracks enemy offensive and defensive cooldowns during a fight',
                    'Executes CC chains with teammates to set up kills',
                    'Switches targets efficiently when a kill window opens',
                    'Adapts positioning based on enemy pressure and healer position',
                    'Identifies team composition win conditions before or during a match',
                ]),
            ],
            [
                'subject_id' => $ids['World of Warcraft: The War Within'], 'index' => 3, 'name' => 'Advanced',
                'description' => 'Consistently performs at a high level in rated PvP with refined awareness and game sense.',
                'outcomes' => json_encode([
                    'Manages cooldowns proactively rather than reactively',
                    'Coordinates CC timing with teammates for maximum efficiency',
                    'Recognises enemy setup attempts and pre-empts them',
                    'Applies sustained pressure while protecting teammates',
                    'Adapts comp strategy mid-match based on win conditions',
                ]),
            ],
            [
                'subject_id' => $ids['World of Warcraft: The War Within'], 'index' => 4, 'name' => 'Expert',
                'description' => 'Elite Arena or RBG player with deep mechanical and strategic mastery.',
                'outcomes' => json_encode([
                    'Tracks all enemy cooldowns simultaneously during a fight',
                    'Exploits DR windows and healer cooldown states to force kills',
                    'Dictates fight pace and forces enemies into unfavourable positions',
                    'Executes complex cross-CC setups with precision timing',
                    'Reads and counters any composition at a high level',
                ]),
            ],

            // Poker
            [
                'subject_id' => $ids['Poker'], 'index' => 0, 'name' => 'Beginner',
                'description' => 'New to poker, learning hand rankings, basic rules, and how a hand of Hold\'em is played.',
                'outcomes' => json_encode([
                    'Understands the two ways to win a pot (showdown or getting all opponents to fold)',
                    'Knows the basic hand rankings and can identify the winning hand at showdown',
                    'Understands what position is and why acting later is an advantage',
                    'Recognises the four betting rounds (preflop, flop, turn, river)',
                    'Can explain the basic idea of pot odds (cost to call vs size of the pot)',
                ]),
            ],
            [
                'subject_id' => $ids['Poker'], 'index' => 1, 'name' => 'Casual',
                'description' => 'Understands preflop and postflop fundamentals and plays with basic positional and sizing awareness.',
                'outcomes' => json_encode([
                    'Adjusts starting hand selection based on position',
                    'Understands the difference between opening, calling, and 3-betting preflop',
                    'Uses pot odds to make basic continue-or-fold decisions',
                    'Recognises dry vs wet board textures and their effect on continuation betting',
                    'Understands the purpose of checking beyond simply having a weak hand',
                ]),
            ],
            [
                'subject_id' => $ids['Poker'], 'index' => 2, 'name' => 'Intermediate',
                'description' => 'Plays with range-based thinking and can construct basic value/bluff balance.',
                'outcomes' => json_encode([
                    'Thinks in terms of opponent ranges rather than single hands',
                    'Sizes bets differently for value, protection, and bluffing purposes',
                    'Recognises blockers and uses them to inform bluffing decisions',
                    'Tracks betting patterns across multiple streets as a single coherent story',
                    'Adjusts strategy against clearly exploitable opponent tendencies',
                ]),
            ],
            [
                'subject_id' => $ids['Poker'], 'index' => 3, 'name' => 'Advanced',
                'description' => 'Consistently applies balanced strategy while adapting to specific opponents and table dynamics.',
                'outcomes' => json_encode([
                    'Constructs balanced betting ranges that resist simple exploitation',
                    'Uses implied odds to evaluate speculative hands beyond direct pot odds',
                    'Reads and adjusts to opponents\' table image and recent tendencies',
                    'Plans multi-street lines in advance rather than deciding street by street',
                    'Manages variance and bankroll considerations as part of in-game decision-making',
                ]),
            ],
            [
                'subject_id' => $ids['Poker'], 'index' => 4, 'name' => 'Expert',
                'description' => 'High-level strategic understanding with refined range construction, adaptability, and mental-game discipline.',
                'outcomes' => json_encode([
                    'Balances exploitative adjustments against game-theoretically sound baseline strategy',
                    'Reads subtle deviations in bet sizing and timing as range-narrowing information',
                    'Maintains disciplined bankroll and variance management under significant swings',
                    'Adapts strategy dynamically across an entire session based on evolving table dynamics',
                    "Identifies and exploits leaks in strong opponents' otherwise balanced strategies",
                ]),
            ],

            // Medicine
            ['subject_id' => $ids['Medicine'], 'index' => 0, 'name' => 'Elementary', 'description' => 'Very simple, child-friendly explanations of basic health and the human body.'],
            ['subject_id' => $ids['Medicine'], 'index' => 1, 'name' => 'High School', 'description' => 'Basic anatomy, physiology, and medical literacy. Easy-to-read content.'],
            ['subject_id' => $ids['Medicine'], 'index' => 2, 'name' => 'College', 'description' => 'Pre-medical level understanding, includes pathophysiology and pharmacology basics.'],
            ['subject_id' => $ids['Medicine'], 'index' => 3, 'name' => 'Graduate', 'description' => 'Advanced knowledge, clinical reasoning, diagnosis, and therapeutics.'],
            ['subject_id' => $ids['Medicine'], 'index' => 4, 'name' => 'Residency', 'description' => 'Hands-on skills, case-based reasoning, complex differential diagnosis.'],
            ['subject_id' => $ids['Medicine'], 'index' => 5, 'name' => 'Specialist', 'description' => 'Expert-level, highly specialized, research-focused knowledge.'],

            // Music
            ['subject_id' => $ids['Music'], 'index' => 0, 'name' => 'Beginner', 'description' => 'Basic exposure to artistic concepts, tools, and creative exercises.'],
            ['subject_id' => $ids['Music'], 'index' => 1, 'name' => 'Developing', 'description' => 'Can apply simple techniques and understands foundational principles.'],
            ['subject_id' => $ids['Music'], 'index' => 2, 'name' => 'Intermediate', 'description' => 'Builds coherent work and explores style, composition, and critique.'],
            ['subject_id' => $ids['Music'], 'index' => 3, 'name' => 'Advanced', 'description' => 'Uses deliberate technique, symbolism, and deeper artistic intent.'],
            ['subject_id' => $ids['Music'], 'index' => 4, 'name' => 'Mastery', 'description' => 'Highly refined creative execution, interpretation, and original artistic voice.'],

            // Ancient History
            ['subject_id' => $ids['Ancient History'], 'index' => 0, 'name' => 'Foundational', 'description' => 'Basic understanding of key ideas, people, and historical context.'],
            ['subject_id' => $ids['Ancient History'], 'index' => 1, 'name' => 'Introductory', 'description' => 'Can explain simple concepts and identify major themes.'],
            ['subject_id' => $ids['Ancient History'], 'index' => 2, 'name' => 'Analytical', 'description' => 'Begins comparing ideas, arguments, and historical perspectives.'],
            ['subject_id' => $ids['Ancient History'], 'index' => 3, 'name' => 'Advanced Analysis', 'description' => 'Can interpret complexity, critique arguments, and synthesize viewpoints.'],
            ['subject_id' => $ids['Ancient History'], 'index' => 4, 'name' => 'Scholarly', 'description' => 'Engages with nuanced theory, deep interpretation, and original insight.'],

            // Industrial Materials
            ['subject_id' => $ids['Industrial Materials'], 'index' => 0, 'name' => 'Apprentice', 'description' => 'Learns basic tools, safety, and simple procedures.'],
            ['subject_id' => $ids['Industrial Materials'], 'index' => 1, 'name' => 'Junior', 'description' => 'Can complete supervised tasks and understands standard practices.'],
            ['subject_id' => $ids['Industrial Materials'], 'index' => 2, 'name' => 'Qualified', 'description' => 'Performs work independently with reliable competence.'],
            ['subject_id' => $ids['Industrial Materials'], 'index' => 3, 'name' => 'Advanced Tradesperson', 'description' => 'Handles complex work, diagnostics, and efficiency improvements.'],
            ['subject_id' => $ids['Industrial Materials'], 'index' => 4, 'name' => 'Master Tradesperson', 'description' => 'Deep expertise, precision, mentoring, and advanced troubleshooting.'],

            // Programming
            ['subject_id' => $ids['Programming'], 'index' => 0, 'name' => 'Beginner', 'description' => 'Basic familiarity with digital tools and core technical ideas.'],
            ['subject_id' => $ids['Programming'], 'index' => 1, 'name' => 'Practical', 'description' => 'Can perform common tasks and understands simple systems.'],
            ['subject_id' => $ids['Programming'], 'index' => 2, 'name' => 'Intermediate', 'description' => 'Understands technical workflows and problem-solving patterns.'],
            ['subject_id' => $ids['Programming'], 'index' => 3, 'name' => 'Advanced', 'description' => 'Can design, troubleshoot, and connect multiple systems.'],
            ['subject_id' => $ids['Programming'], 'index' => 4, 'name' => 'Expert', 'description' => 'High-level technical reasoning, architecture, and optimization.'],

            // Global Macro
            ['subject_id' => $ids['Global Macro'], 'index' => 0, 'name' => 'Novice', 'description' => 'Basic awareness of money, trade, and how businesses operate at a surface level.'],
            ['subject_id' => $ids['Global Macro'], 'index' => 1, 'name' => 'Foundational', 'description' => 'Understands core concepts such as supply and demand, budgeting, and simple financial statements.'],
            ['subject_id' => $ids['Global Macro'], 'index' => 2, 'name' => 'Competent', 'description' => 'Can interpret financial data, understand market dynamics, and apply basic economic principles.'],
            ['subject_id' => $ids['Global Macro'], 'index' => 3, 'name' => 'Proficient', 'description' => 'Analyses business strategy, investment decisions, and financial performance with confidence.'],
            ['subject_id' => $ids['Global Macro'], 'index' => 4, 'name' => 'Expert', 'description' => 'Deep understanding of economic systems, capital markets, corporate finance, and strategic thinking.'],
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
