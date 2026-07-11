<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class WoWDiagnosticModuleSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'World of Warcraft: The War Within')->first();

        if (! $subject) {
            $this->command->error('❌ WoW subject not found. Run SubjectSeeder first.');
            return;
        }

        $proficiency = Proficiency::where('name', 'Beginner')
            ->where('subject_id', $subject->id)
            ->first();

        if (! $proficiency) {
            $this->command->error('❌ Beginner proficiency not found for WoW subject. Run ProficiencySeeder first.');
            return;
        }

        $module = $this->seedModule($subject, $proficiency);
        $this->seedQuestions($module);
    }

    private function seedModule(Subject $subject, Proficiency $proficiency): Module
    {
        $module = Module::firstOrCreate(
            ['name' => 'Arena Playstyle Assessment', 'subject_id' => $subject->id],
            [
                'description' => 'Discover how you naturally think and make decisions in arena. There are no right or wrong answers.',
                'type'        => 'diagnostic',
                'status'      => 'ready',
                'published'   => true,
                'parent_id'   => null,
                'created_by'  => User::where('email', User::SYSTEM_ENGINE_EMAIL)->value('id'),
            ]
        );

        if (! $module->proficiencies()->where('proficiency_id', $proficiency->id)->exists()) {
            $module->proficiencies()->attach($proficiency->id);
        }

        $this->command->info('✅ Arena Playstyle Assessment module seeded.');

        return $module;
    }

    private function seedQuestions(Module $module): void
    {
        $created = 0;
        $linked  = 0;

        $allQuestions = array_merge(
            array_map(fn($q) => array_merge($q, ['type' => 'diagnostic_mcq']), $this->questions()),
            $this->surveyQuestions(),
        );

        foreach ($allQuestions as $q) {
            $question = Question::firstOrCreate(
                ['question' => $q['question']],
                [
                    'type'       => $q['type'],
                    'difficulty' => 'easy',
                    'skill_type' => 'application',
                    'answer'     => $q['answer'],
                ]
            );

            if ($question->wasRecentlyCreated) {
                $created++;
            }

            $module->questions()->syncWithoutDetaching([$question->id]);
            $linked++;
        }

        $this->command->info("✅ WoW diagnostic questions: {$created} created, {$linked} linked to module.");
    }

    private function surveyQuestions(): array
    {
        return [
            [
                'type'     => 'survey_mcq',
                'question' => 'What is your current highest Arena rating?',
                'answer'   => [
                    'question_key' => 'current_rating',
                    'options'      => [
                        ['text' => 'Under 1400', 'value' => 1],
                        ['text' => '1400–1799',  'value' => 2],
                        ['text' => '1800–2099',  'value' => 3],
                        ['text' => '2100–2399',  'value' => 4],
                        ['text' => '2400+',      'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'Which role do you play most often?',
                'answer'   => [
                    'question_key' => 'primary_role',
                    'options'      => [
                        ['text' => 'Healer',                   'value' => 1],
                        ['text' => 'Melee DPS',                'value' => 2],
                        ['text' => 'Ranged DPS',               'value' => 3],
                        ['text' => 'I play all roles equally', 'value' => 4],
                        ['text' => 'Not sure / New to Arena',  'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'What is your main goal?',
                'answer'   => [
                    'question_key' => 'primary_goal',
                    'options'      => [
                        ['text' => 'Learn Arena fundamentals',   'value' => 1],
                        ['text' => 'Increase my rating',         'value' => 2],
                        ['text' => 'Master my class',            'value' => 3],
                        ['text' => 'Become a better teammate',   'value' => 4],
                        ['text' => 'Push Gladiator or higher',   'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'What do you believe is holding you back the most?',
                'answer'   => [
                    'question_key' => 'self_assessed_weakness',
                    'options'      => [
                        ['text' => 'Mechanics',        'value' => 1],
                        ['text' => 'Decision making',  'value' => 2],
                        ['text' => 'Awareness',        'value' => 3],
                        ['text' => 'Teamwork',         'value' => 4],
                        ['text' => "I'm not sure",     'value' => 5],
                    ],
                ],
            ],
        ];
    }

    private function questions(): array
    {
        return [
            [
                'question' => "What are you thinking in the first 10 seconds of a match before anyone has committed a cooldown?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "I'm already scanning for which target looks most vulnerable.",
                            'diagnostic_payload' => ['traits' => ['kill_instinct' => 2, 'aggression' => 1]],
                        ],
                        [
                            'text'               => "I want to understand their setup before I commit to anything.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "I follow the opener we agreed on before the game.",
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "I react to whatever they show me first and adjust from there.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You're mid-burst cooldowns on your kill target when your healer calls a swap to help a teammate who's in danger. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Swap immediately — protecting the team is the priority.",
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Finish the window — abandoning mid-burst usually wastes both targets.",
                            'diagnostic_payload' => ['traits' => ['kill_instinct' => 2, 'independence' => 1]],
                        ],
                        [
                            'text'               => "Quickly weigh how close the kill is before deciding.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "React on instinct — if the swap feels right in the moment, I commit.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'kill_instinct' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You're running a planned CC chain on your kill target. A different enemy unexpectedly drops to 10% health. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Swap instantly — a real kill window beats a planned one every time.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'kill_instinct' => 2]],
                        ],
                        [
                            'text'               => "Stick to the plan — an incomplete chain is almost always worse.",
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Call it to the team first and let them confirm before committing.",
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => "Improvise with whatever I have — I'll make something work.",
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'risk_tolerance' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "The enemy healer is consistently playing behind pillars, resetting your pressure every time you build it. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Rotate to cut off their pillar routes and control the space.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Switch to a different kill target — a healer playing defensively is doing their job.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => "Keep sustained pressure and wait for them to make a greedy mistake.",
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'pressure_orientation' => 1]],
                        ],
                        [
                            'text'               => "Use an unexpected angle or timing to bait them out of position.",
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'aggression' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You can see a clean kill window your teammates haven't noticed. They're calling for a reset. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Play my read — I'm in the best position to see it.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => "Honor the reset — team coherence is worth more than one window.",
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => "Communicate what I see fast — if they react, great. If not, I'll make the call.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'independence' => 1]],
                        ],
                        [
                            'text'               => "Start the reset but commit if the window is still open in two seconds.",
                            'diagnostic_payload' => ['traits' => ['risk_tolerance' => 2, 'aggression' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your team is ahead on cooldowns but you're personally low on health. The enemy pushes in hard. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Absorb the pressure — the cooldown lead will pay off if I survive.",
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Trade into them aggressively — they're likely dry on cooldowns too.",
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'risk_tolerance' => 1]],
                        ],
                        [
                            'text'               => "React beat by beat — use defensives only when it actually looks critical.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'risk_tolerance' => 1]],
                        ],
                        [
                            'text'               => "Disengage entirely — the advantage doesn't expire if I stay alive.",
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'independence' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Of these four, which demands the most of your mental attention during active arena play?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Enemy cooldowns and trinket status — my decisions are built around what they have left.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'structure_preference' => 1]],
                        ],
                        [
                            'text'               => "My own positioning and where I can escape to.",
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'independence' => 1]],
                        ],
                        [
                            'text'               => "Where the momentum is shifting and which direction the game is tipping.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'pressure_orientation' => 1]],
                        ],
                        [
                            'text'               => "Openings to create action — I'm constantly scanning for a window to go.",
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'kill_instinct' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You win a close game where your original plan fell apart early. What was the actual winning factor?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "I adapted in real-time and found new angles as the game evolved.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'creativity' => 1]],
                        ],
                        [
                            'text'               => "I spotted the right moment and committed to the kill.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => "I stayed disciplined under pressure and didn't waste a single cooldown.",
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'structure_preference' => 1]],
                        ],
                        [
                            'text'               => "I took over and created a window rather than waiting for one.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'aggression' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Two games in, you're losing to a team you have no excuse to lose to. What changes?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Identify exactly what's causing the losses before changing anything.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Try something they haven't seen — the current gameplan clearly isn't working.",
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'risk_tolerance' => 1]],
                        ],
                        [
                            'text'               => "Ramp up the pressure and force them into reactive mode.",
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'pressure_orientation' => 1]],
                        ],
                        [
                            'text'               => "Lock in and clean up the execution — the plan is fine, the errors aren't.",
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "When you're playing at your sharpest, which of these best describes what your play actually looks like?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Controlled and deliberate — every decision is intentional and nothing is wasted.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => "Relentless — I keep pressure going and they're always reacting to me.",
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'aggression' => 1]],
                        ],
                        [
                            'text'               => "Instinctive — I'm reading the game faster than I can consciously think.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => "Self-directed — I create my own windows without waiting for calls.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'kill_instinct' => 1]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
