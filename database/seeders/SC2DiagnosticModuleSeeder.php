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
                'question' => "Your opener is standard but your early scout reveals an unusually fast expansion from your opponent. What is your first instinct?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Hit them before they stabilise with an all-in timing attack.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'kill_instinct' => 2, 'risk_tolerance' => 1],
                                'interpretation' => 'Looks to punish economic greed immediately with aggression.',
                            ],
                        ],
                        [
                            'text'               => 'Take my own expansion to match their economic pace.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'structure_preference' => 1, 'adaptability' => 1],
                                'interpretation' => 'Mirrors the economic decision, accepting a longer macro game.',
                            ],
                        ],
                        [
                            'text'               => 'Scout their army strength before deciding anything.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'patience' => 1],
                                'interpretation' => 'Gathers more information before committing to a response.',
                            ],
                        ],
                        [
                            'text'               => 'Continue my original build and see how it plays out.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'risk_tolerance' => 1, 'adaptability' => -1],
                                'interpretation' => 'Stays committed to the planned build regardless of new information.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your timing attack hits at the right window but the enemy wall is better reinforced than expected. Your units are at the ramp. Do you:",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Commit everything — I need to deal damage to justify the timing.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'risk_tolerance' => 2, 'kill_instinct' => 1],
                                'interpretation' => 'Accepts losses to force economic or structural damage on the opponent.',
                            ],
                        ],
                        [
                            'text'               => 'Probe for any gap in their defence before pulling back.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'patience' => 1],
                                'interpretation' => 'Looks for an opening before fully committing or retreating.',
                            ],
                        ],
                        [
                            'text'               => 'Pull back cleanly and transition into a macro build.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'defensive_discipline' => 1, 'adaptability' => 1],
                                'interpretation' => 'Accepts the failed timing and pivots to a long-term economic approach.',
                            ],
                        ],
                        [
                            'text'               => 'Try to attack from a different angle or drop their mineral line.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'adaptability' => 2, 'aggression' => 1],
                                'interpretation' => 'Improvises an alternative path to force damage rather than accepting retreat.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "It is mid-game and you are even in army and base count. A scan reveals their main base is undefended while their army is on the map. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Drop their main immediately and kill as many workers as possible.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'kill_instinct' => 2, 'creativity' => 1],
                                'interpretation' => 'Exploits the opportunity to deal economic damage immediately.',
                            ],
                        ],
                        [
                            'text'               => 'Sneak in a third base while they are out of position.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'control_orientation' => 1, 'structure_preference' => 1],
                                'interpretation' => 'Converts the mistake into an economic advantage rather than direct damage.',
                            ],
                        ],
                        [
                            'text'               => 'Engage their army on the map while their base is undefended.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'pressure_orientation' => 2],
                                'interpretation' => 'Commits to forcing a decisive fight while the enemy is out of position.',
                            ],
                        ],
                        [
                            'text'               => 'Sit back and build up — I\'ll engage when I have a bigger lead.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'defensive_discipline' => 1, 'structure_preference' => 1],
                                'interpretation' => 'Resists the temptation to act and accumulates a larger advantage first.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You lose your main army in a fight but your economy is still healthy and running. What is your first priority?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Rebuild immediately and counterattack before they press the advantage.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'pressure_orientation' => 2, 'risk_tolerance' => 1],
                                'interpretation' => 'Tries to reverse momentum through immediate aggressive play.',
                            ],
                        ],
                        [
                            'text'               => 'Pull back, place static defence, and rebuild safely.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'defensive_discipline' => 2],
                                'interpretation' => 'Accepts a defensive phase to stabilise before committing again.',
                            ],
                        ],
                        [
                            'text'               => 'Use a tech unit or drop to harass while the main force rebuilds.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'adaptability' => 2, 'control_orientation' => 1],
                                'interpretation' => 'Multi-tasks to deny their follow-up while rebuilding in the background.',
                            ],
                        ],
                        [
                            'text'               => 'Assess what went wrong in that fight while rebuilding.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'control_orientation' => 1, 'structure_preference' => 1],
                                'interpretation' => 'Diagnoses the cause of the loss before deciding the next move.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "How do you approach your build order at the start of a game?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'I run the same build every game until it stops working.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'adaptability' => -1],
                                'interpretation' => 'Relies on repetition and refinement over flexible in-game decisions.',
                            ],
                        ],
                        [
                            'text'               => 'I have two or three core builds and choose based on the match-up.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 1, 'adaptability' => 1, 'control_orientation' => 1],
                                'interpretation' => 'Balances prepared builds with basic adaptive decision-making.',
                            ],
                        ],
                        [
                            'text'               => 'I scout first and then decide which build fits best.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'adaptability' => 2],
                                'interpretation' => 'Prioritises information and reactive decision-making over memorised execution.',
                            ],
                        ],
                        [
                            'text'               => 'I build by feel — whatever seems right in the moment.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'adaptability' => 1, 'structure_preference' => -1],
                                'interpretation' => 'Relies on improvisation and instinct over formal build order discipline.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You have a significant worker and economic lead but your opponent has a larger army. What is your approach?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Keep expanding and trust my economy to overwhelm them long-term.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'structure_preference' => 1],
                                'interpretation' => 'Bets on the economic advantage paying off without forcing an engagement.',
                            ],
                        ],
                        [
                            'text'               => 'Attack into the superior army — I have the resources to replace losses.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'risk_tolerance' => 2, 'kill_instinct' => 1],
                                'interpretation' => 'Uses economic depth to sustain an aggressive push into a stronger force.',
                            ],
                        ],
                        [
                            'text'               => 'Drop their base while avoiding their main army.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'adaptability' => 2, 'control_orientation' => 1],
                                'interpretation' => 'Converts the army lead into an exploitation opportunity rather than a direct fight.',
                            ],
                        ],
                        [
                            'text'               => 'Tech to better units and engage when army strength is even.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'defensive_discipline' => 1, 'structure_preference' => 1],
                                'interpretation' => 'Invests the economic lead into tech and waits for a favourable engagement.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your opponent opens with an aggressive two-base build you did not scout in time. You are caught underprepared. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Take the hit, macro through it, and try to rebuild for a comeback.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'adaptability' => 2, 'defensive_discipline' => 1],
                                'interpretation' => 'Absorbs early pressure and focuses on surviving and recovering.',
                            ],
                        ],
                        [
                            'text'               => 'Counterattack immediately while their units are committed.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'risk_tolerance' => 2, 'creativity' => 1],
                                'interpretation' => 'Looks to create chaos and force them to defend rather than absorbing pressure.',
                            ],
                        ],
                        [
                            'text'               => 'Fall back to a tight defensive position and slow them down.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'defensive_discipline' => 2],
                                'interpretation' => 'Buys time and defensive space to survive and regroup.',
                            ],
                        ],
                        [
                            'text'               => 'Sacrifice some units to gather information on exactly what they are doing.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'adaptability' => 1],
                                'interpretation' => 'Prioritises intelligence gathering to make a more informed defensive decision.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You have hit maximum supply several times this game but keep losing major engagements. What is your primary concern?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'My army composition is wrong for their unit mix.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'control_orientation' => 1],
                                'interpretation' => 'Diagnoses the loss through composition and counter-unit logic.',
                            ],
                        ],
                        [
                            'text'               => 'My micro and ability usage during battles.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'kill_instinct' => 1],
                                'interpretation' => 'Diagnoses losses through individual unit control during fights.',
                            ],
                        ],
                        [
                            'text'               => 'I am not choosing the right moment to engage.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'patience' => 1, 'structure_preference' => 1],
                                'interpretation' => 'Diagnoses losses through timing and engagement selection.',
                            ],
                        ],
                        [
                            'text'               => 'I need to attack sooner before they get a stronger army.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'pressure_orientation' => 2, 'risk_tolerance' => 1],
                                'interpretation' => 'Believes the problem is failing to act aggressively enough at the right window.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You reach a point in the game where both attack options seem risky and neither path is clearly favourable. What is your instinct?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Pick one and commit — hesitation loses games.',
                            'diagnostic_payload' => [
                                'traits'         => ['risk_tolerance' => 2, 'aggression' => 1, 'kill_instinct' => 1],
                                'interpretation' => 'Accepts uncertainty and commits decisively to force a result.',
                            ],
                        ],
                        [
                            'text'               => 'Wait and gather more information before acting.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'control_orientation' => 2],
                                'interpretation' => 'Delays the decision until more certainty is available.',
                            ],
                        ],
                        [
                            'text'               => 'Use a harass unit or drop to force them to react first.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'adaptability' => 2, 'pressure_orientation' => 1],
                                'interpretation' => 'Creates pressure through a side action to shift the dynamic rather than committing directly.',
                            ],
                        ],
                        [
                            'text'               => 'Build up a larger supply lead to make any engagement safer.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'structure_preference' => 1, 'defensive_discipline' => 1],
                                'interpretation' => 'Chooses to build overwhelming force before engaging.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "After a ladder loss, what part of the replay do you watch first?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'The key battles — when and why I lost each engagement.',
                            'diagnostic_payload' => [
                                'traits'         => ['kill_instinct' => 1, 'control_orientation' => 2],
                                'interpretation' => 'Reviews losses through fight execution and army decisions.',
                            ],
                        ],
                        [
                            'text'               => 'My build order and how my economy compared to theirs.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'adaptability' => 1],
                                'interpretation' => 'Reviews losses through build execution and economic benchmarks.',
                            ],
                        ],
                        [
                            'text'               => 'My worker production and base count across the game.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'structure_preference' => 1],
                                'interpretation' => 'Reviews losses through macro fundamentals and economic discipline.',
                            ],
                        ],
                        [
                            'text'               => 'Moments where I could have made a different call.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'creativity' => 1],
                                'interpretation' => 'Reviews losses by identifying decision points and counterfactual paths.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
