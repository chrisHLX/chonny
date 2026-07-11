<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class LoLDiagnosticModuleSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'League of Legends')->first();

        if (! $subject) {
            $this->command->error('❌ League of Legends subject not found. Run SubjectSeeder first.');
            return;
        }

        $proficiency = Proficiency::where('name', 'Beginner')
            ->where('subject_id', $subject->id)
            ->first();

        if (! $proficiency) {
            $this->command->error('❌ Beginner proficiency not found for League of Legends subject. Run ProficiencySeeder first.');
            return;
        }

        $module = $this->seedModule($subject, $proficiency);
        $this->seedQuestions($module);
    }

    private function seedModule(Subject $subject, Proficiency $proficiency): Module
    {
        $module = Module::firstOrCreate(
            ['name' => 'Ranked Playstyle Assessment', 'subject_id' => $subject->id],
            [
                'description' => 'Discover how you naturally think and make decisions in ranked. There are no right or wrong answers.',
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

        $this->command->info('✅ LoL Ranked Playstyle Assessment module seeded.');

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

        $this->command->info("✅ LoL diagnostic questions: {$created} created, {$linked} linked to module.");
    }

    private function surveyQuestions(): array
    {
        return [
            [
                'type'     => 'survey_mcq',
                'question' => 'What is your current ranked tier?',
                'answer'   => [
                    'question_key' => 'current_rating',
                    'options'      => [
                        ['text' => 'Iron / Bronze',     'value' => 1],
                        ['text' => 'Silver / Gold',     'value' => 2],
                        ['text' => 'Platinum / Emerald','value' => 3],
                        ['text' => 'Diamond',            'value' => 4],
                        ['text' => 'Master+',            'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'Which role do you main?',
                'answer'   => [
                    'question_key' => 'primary_role',
                    'options'      => [
                        ['text' => 'Top Lane',              'value' => 1],
                        ['text' => 'Jungle',                'value' => 2],
                        ['text' => 'Mid Lane',              'value' => 3],
                        ['text' => 'ADC',                   'value' => 4],
                        ['text' => 'Support',               'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'What is your main goal right now?',
                'answer'   => [
                    'question_key' => 'primary_goal',
                    'options'      => [
                        ['text' => 'Climb out of my current rank',  'value' => 1],
                        ['text' => 'Master a specific champion',     'value' => 2],
                        ['text' => 'Improve my teamfighting',        'value' => 3],
                        ['text' => 'Understand macro play better',   'value' => 4],
                        ['text' => 'Hit Diamond or higher',          'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'What do you think is holding you back the most?',
                'answer'   => [
                    'question_key' => 'self_assessed_weakness',
                    'options'      => [
                        ['text' => 'Mechanics and champion mastery', 'value' => 1],
                        ['text' => 'Decision making in-game',        'value' => 2],
                        ['text' => 'Map awareness and vision',       'value' => 3],
                        ['text' => 'Teamwork and communication',     'value' => 4],
                        ['text' => "I'm not sure",                   'value' => 5],
                    ],
                ],
            ],
        ];
    }

    private function questions(): array
    {
        return [
            [
                'question' => "In the first 5 minutes of a ranked game, what's driving most of your decisions?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Not making mistakes in lane — fundamentals first.',
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => 'Reading the matchup and adjusting how I trade as it plays out.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => 'Finding windows to bully them out of farm.',
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => "Playing my own pace — the lane result won't decide the game anyway.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'patience' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You just killed the enemy laner. They're dead for 35 seconds. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Shove the wave hard and crash it under their tower.',
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'aggression' => 1]],
                        ],
                        [
                            'text'               => 'Immediately check the minimap and rotate to the most impactful place.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => 'Back to spend gold and come back with an item spike.',
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => 'Ward deep and look to all-in again when they return.',
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'kill_instinct' => 2]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "The enemy laner is playing passive under tower. You have a clear lane advantage. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Freeze the wave and deny them CS for as long as possible.',
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 2]],
                        ],
                        [
                            'text'               => "Push hard and roam — a passive opponent is an invitation to impact other lanes.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'pressure_orientation' => 1]],
                        ],
                        [
                            'text'               => 'Zone them with aggressive positioning and look for an all-in.',
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'kill_instinct' => 2]],
                        ],
                        [
                            'text'               => 'Farm safely, spend gold when the window comes, and scale.',
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'structure_preference' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "A fight breaks out 2v2 in your lane before either team planned it. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Read the health bars and cooldowns instantly — I know in a second if I should commit.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'kill_instinct' => 2]],
                        ],
                        [
                            'text'               => 'Look for an angle to set up a kill rather than just trading.',
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'independence' => 2]],
                        ],
                        [
                            'text'               => 'Play safe — unplanned fights are usually coin flips.',
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => 'Trade even and disengage — the objective is to farm, not brawl.',
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'structure_preference' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Drake is spawning in 90 seconds. What are you doing right now?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Setting up vision and positioning around the objective.',
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => 'Looking to kill someone before they can contest — take the pick, then drake.',
                            'diagnostic_payload' => ['traits' => ['kill_instinct' => 2, 'pressure_orientation' => 2]],
                        ],
                        [
                            'text'               => 'Checking if I can force a fight before they arrive.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'aggression' => 1]],
                        ],
                        [
                            'text'               => 'Shoving a side wave to bring a gold advantage into the fight.',
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'structure_preference' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your team wins a clean 5v5 teamfight. 20 seconds before they respawn. What do you prioritise?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Rush the nearest structure before they come back.',
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'pressure_orientation' => 2]],
                        ],
                        [
                            'text'               => 'Secure Baron or Dragon for the objective buff.',
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'structure_preference' => 1]],
                        ],
                        [
                            'text'               => 'Look for whatever turns this lead into more damage.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => 'Recall, buy, come back full — item power matters more than raw map pressure.',
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'defensive_discipline' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "The enemy's been split-pushing for 5 minutes, picking up free objectives. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Shadow their split pusher — one person always follows them.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => 'Group and force a 5v4 teamfight while they are out of position.',
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'kill_instinct' => 2]],
                        ],
                        [
                            'text'               => 'Match their split push with your own on the other side.',
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'creativity' => 2]],
                        ],
                        [
                            'text'               => 'Group mid and siege — a split pusher cannot answer a 5-man push.',
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You are losing a close game. Your team is turtling under nexus towers. What is your play?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Find a pick on their carry when they step up — one kill can flip this.',
                            'diagnostic_payload' => ['traits' => ['kill_instinct' => 2, 'reactivity' => 2]],
                        ],
                        [
                            'text'               => 'Set up vision and wait for them to make the mistake.',
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'control_orientation' => 2]],
                        ],
                        [
                            'text'               => 'Look for a base race — if they take a tower, race for their base.',
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'independence' => 2]],
                        ],
                        [
                            'text'               => 'Call a coordinated 5-man push on the next objective.',
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'structure_preference' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your team needs engage but everyone's picked damage, and you're last to lock in. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Pick what the team needs — a bad composition loses before the game starts.',
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => "Play what I'm best at and outplay the bad comp.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'aggression' => 1]],
                        ],
                        [
                            'text'               => 'Find a champion that can flex between engage and damage.',
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'adaptability' => 2]],
                        ],
                        [
                            'text'               => 'Type in chat and see if a teammate swaps before I decide.',
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'defensive_discipline' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "When you are playing at your sharpest, which of these best describes it?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Controlled and aware — I know where every threat is and my decisions flow from that.',
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Instinctive and fast — I'm reacting to the game faster than I can think about it.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => "Aggressive and decisive — I'm creating the action and they're following my lead.",
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => "Decisive and self-directed — I'm running my gameplan and no one can stop it.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'aggression' => 1]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
