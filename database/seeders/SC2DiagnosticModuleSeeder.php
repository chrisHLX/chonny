<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class SC2DiagnosticModuleSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'StarCraft 2')->first();

        if (! $subject) {
            $this->command->error('❌ StarCraft 2 subject not found. Run SubjectSeeder first.');
            return;
        }

        $proficiency = Proficiency::where('name', 'Beginner')
            ->where('subject_id', $subject->id)
            ->first();

        if (! $proficiency) {
            $this->command->error('❌ Beginner proficiency not found for StarCraft 2 subject. Run ProficiencySeeder first.');
            return;
        }

        $module = $this->seedModule($subject, $proficiency);
        $this->seedQuestions($module);
    }

    private function seedModule(Subject $subject, Proficiency $proficiency): Module
    {
        $module = Module::firstOrCreate(
            ['name' => 'Commander Profile Assessment', 'subject_id' => $subject->id],
            [
                'description' => 'Discover how you naturally think and command on the ladder. There are no right or wrong answers.',
                'type'        => 'diagnostic',
                'status'      => 'ready',
                'published'   => true,
                'parent_id'   => null,
            ]
        );

        if (! $module->proficiencies()->where('proficiency_id', $proficiency->id)->exists()) {
            $module->proficiencies()->attach($proficiency->id);
        }

        $this->command->info('✅ SC2 Commander Profile Assessment module seeded.');

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

        $this->command->info("✅ SC2 diagnostic questions: {$created} created, {$linked} linked to module.");
    }

    private function surveyQuestions(): array
    {
        return [
            [
                'type'     => 'survey_mcq',
                'question' => 'What is your current ladder rank?',
                'answer'   => [
                    'question_key' => 'current_rating',
                    'options'      => [
                        ['text' => 'Bronze / Silver',   'value' => 1],
                        ['text' => 'Gold',               'value' => 2],
                        ['text' => 'Platinum',           'value' => 3],
                        ['text' => 'Diamond',            'value' => 4],
                        ['text' => 'Master+',            'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'Which race do you play?',
                'answer'   => [
                    'question_key' => 'primary_role',
                    'options'      => [
                        ['text' => 'Terran',   'value' => 1],
                        ['text' => 'Zerg',     'value' => 2],
                        ['text' => 'Protoss',  'value' => 3],
                        ['text' => 'Random',   'value' => 4],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'What is your main focus right now?',
                'answer'   => [
                    'question_key' => 'primary_goal',
                    'options'      => [
                        ['text' => 'Learn the fundamentals of my race',    'value' => 1],
                        ['text' => 'Climb out of my current league',       'value' => 2],
                        ['text' => 'Improve my macro and game sense',      'value' => 3],
                        ['text' => 'Hit Diamond or higher',                'value' => 4],
                        ['text' => 'Ladder more consistently',             'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'What do you think holds you back most on the ladder?',
                'answer'   => [
                    'question_key' => 'self_assessed_weakness',
                    'options'      => [
                        ['text' => 'Build order execution',          'value' => 1],
                        ['text' => 'Macro (worker production, bases)', 'value' => 2],
                        ['text' => 'Micro and army control',         'value' => 3],
                        ['text' => 'Decision making and game sense', 'value' => 4],
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
                'question' => "The first minute of a match. What occupies most of your attention?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Hitting every production timing and macro benchmark clean.',
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Whatever the first scout reveals — I'm already adjusting.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'reactivity' => 1]],
                        ],
                        [
                            'text'               => "Whether there's a timing I can hit before they're settled.",
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => "Running my plan. I'll deal with their opener when I know it.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'patience' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your all-in timing hits the ramp and it's better defended than expected. Your units are committed. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Commit everything — I need to deal damage to justify burning these resources.',
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'risk_tolerance' => 2]],
                        ],
                        [
                            'text'               => 'Probe for a gap before fully committing or pulling back.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => 'Pull back cleanly and transition to macro — this timing is dead.',
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => 'Open a different angle — drop or multi-prong instead of the main ramp.',
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'adaptability' => 2]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You lose your early army in a failed attack. Your economy is still intact. First instinct?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Read what they're most likely to do next and prepare for it.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => 'Place static defence and rebuild safely before committing again.',
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'patience' => 2]],
                        ],
                        [
                            'text'               => 'Launch drop harassment to slow their follow-up while I rebuild.',
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'creativity' => 2]],
                        ],
                        [
                            'text'               => 'Work out the unit composition mistake and tech into the counter.',
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'control_orientation' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Both armies are equal in the mid-game. The fight can happen anywhere on the map. What are you looking for?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'The moment their positioning slips — I go the instant I see the window.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'kill_instinct' => 2]],
                        ],
                        [
                            'text'               => 'A supply or composition advantage that makes the fight clearly mine.',
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => 'A second angle — a drop or multi-prong to split them before the main fight.',
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'independence' => 2]],
                        ],
                        [
                            'text'               => "Nothing — I'm pushing. Waiting while equal means they're building confidence.",
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'pressure_orientation' => 2]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your opponent is maxed and marching across the map. You're ahead on workers but behind on bases. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Hold at home — the worker advantage means I recover faster if they break through.',
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'patience' => 2]],
                        ],
                        [
                            'text'               => 'Engage on the map — I have more units to lose and more to gain.',
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'independence' => 2]],
                        ],
                        [
                            'text'               => 'Drop their main base while holding with enough units at home.',
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'reactivity' => 1]],
                        ],
                        [
                            'text'               => 'Let them approach and look for the counter-engage moment at home.',
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'control_orientation' => 2]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You land a drop on an undefended mineral line. Their army is far away. What are you doing with your main army right now?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Pushing with the main army too — I want them reacting on both sides at once.",
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'aggression' => 2]],
                        ],
                        [
                            'text'               => 'Splitting attention between the drop and the army — controlling both.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 2]],
                        ],
                        [
                            'text'               => 'Pulling back to defend in case the drop draws a counterattack.',
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Taking another base — the drop creates time and I'm using it to expand.",
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'independence' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You're losing on the economy graph but your current army is ahead in strength. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Push constantly — army strength is temporary, an economic deficit is permanent.',
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'kill_instinct' => 2]],
                        ],
                        [
                            'text'               => 'Find the moment their economy converts to army supply and attack before then.',
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'independence' => 1]],
                        ],
                        [
                            'text'               => 'Drop and harass to hold their economy back while my army lead holds.',
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'aggression' => 1]],
                        ],
                        [
                            'text'               => 'Be selective — only commit when I can guarantee enough economic damage to even it.',
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 2]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You scout an unusual tech choice mid-game — something you haven't practised against. First reaction?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Adjust my build immediately based on what that tech implies.',
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'reactivity' => 2]],
                        ],
                        [
                            'text'               => 'Hit them before it finishes — their investment means their economy is weaker right now.',
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'independence' => 2]],
                        ],
                        [
                            'text'               => 'Send more scouts to confirm before changing anything.',
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => 'Identify which units hard-counter it and pivot tech.',
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'creativity' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your macro is clean and you're hitting all your benchmarks. The wins aren't coming. What's most likely wrong?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "I'm attacking one-dimensionally — I need to split the map or multi-prong.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'creativity' => 2]],
                        ],
                        [
                            'text'               => "I'm taking fights at the wrong moment — army strength doesn't guarantee a win.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => "I'm not being aggressive enough with early timing windows.",
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => 'My build order needs refining for this specific match-up.',
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "When you're playing at your sharpest, which of these best describes it?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Every cooldown and timing is deliberate — nothing is wasted.',
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => "I'm reading the game faster than conscious thought — I just react and it works.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => "I never let up — I'm applying pressure and they're always reacting to me.",
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'aggression' => 1]],
                        ],
                        [
                            'text'               => "I'm playing my own game — the plan is running and I don't second-guess it.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'structure_preference' => 1]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
