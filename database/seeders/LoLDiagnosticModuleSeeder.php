<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
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
                'question' => "The enemy jungler ganks your lane while you're pushed up at 60% health. Your flash is up but the enemy laner also has a dash ability. What do you do first?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Flash immediately to safety.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2, 'reactivity' => 1, 'risk_tolerance' => -1],
                                'interpretation' => 'Prioritises immediate survival over resource conservation.',
                            ],
                        ],
                        [
                            'text'               => 'Try to burn their flash with your abilities first, then flash away.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 1, 'kill_instinct' => 1, 'risk_tolerance' => 1],
                                'interpretation' => 'Looks to trade resources before escaping — accepts short-term risk for longer-term advantage.',
                            ],
                        ],
                        [
                            'text'               => 'Walk into a minion wave for extra pathing and dodge skill shots.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'patience' => 1],
                                'interpretation' => 'Uses positioning and game mechanics to survive without burning flash.',
                            ],
                        ],
                        [
                            'text'               => 'Fight both of them — the numbers are two-for-one if I die.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'risk_tolerance' => 2, 'kill_instinct' => 1],
                                'interpretation' => 'Willing to accept death in exchange for forcing enemy resources.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You win a 2v2 skirmish in your lane and your opponent just backed. You have 30 seconds before your abilities are back up. What do you prioritise?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Shove the wave hard and take tower plates.',
                            'diagnostic_payload' => [
                                'traits'         => ['pressure_orientation' => 2, 'aggression' => 1],
                                'interpretation' => 'Converts fight win into immediate map pressure.',
                            ],
                        ],
                        [
                            'text'               => 'Rotate to help another lane while the window is open.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'control_orientation' => 1],
                                'interpretation' => 'Prioritises cross-map impact over local gains.',
                            ],
                        ],
                        [
                            'text'               => 'Back to spend your gold and recoup health.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'defensive_discipline' => 1],
                                'interpretation' => 'Values item spike and recovery over pressing the advantage.',
                            ],
                        ],
                        [
                            'text'               => 'Set up vision control around objectives for the next play.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'patience' => 1],
                                'interpretation' => 'Thinks ahead to vision and objective control rather than immediate action.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your lane opponent is farming passively under tower and rarely contests you. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Zone them off CS with aggressive trading patterns.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'pressure_orientation' => 2],
                                'interpretation' => 'Uses passive opponent to maximise lane dominance.',
                            ],
                        ],
                        [
                            'text'               => 'Freeze the wave near your tower to deny them farm safely.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'patience' => 2],
                                'interpretation' => 'Uses wave control to deny resources without risking all-ins.',
                            ],
                        ],
                        [
                            'text'               => 'Roam frequently — they\'re not going anywhere.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'pressure_orientation' => 1],
                                'interpretation' => 'Redirects lane advantage into cross-map pressure.',
                            ],
                        ],
                        [
                            'text'               => 'Match their pace, farm up, and scale into late game.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'structure_preference' => 1],
                                'interpretation' => 'Accepts a passive lane in exchange for safe scaling.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your team starts Baron without you and it's not the timing you would have chosen. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Join in — better to commit together than abandon them.',
                            'diagnostic_payload' => [
                                'traits'         => ['independence' => -1, 'structure_preference' => 1],
                                'interpretation' => 'Prioritises team cohesion over individual judgment.',
                            ],
                        ],
                        [
                            'text'               => 'Ping abort and explain why in chat.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'independence' => 1],
                                'interpretation' => 'Willing to challenge the group decision with reasoning.',
                            ],
                        ],
                        [
                            'text'               => 'Take a side objective while they distract the enemy.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'independence' => 2, 'adaptability' => 1],
                                'interpretation' => 'Converts a team mistake into an independent win condition.',
                            ],
                        ],
                        [
                            'text'               => 'Join in but position near the exit in case it goes wrong.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 1, 'defensive_discipline' => 2, 'control_orientation' => 1],
                                'interpretation' => 'Participates while preparing to minimise losses if it fails.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Late game. You spot the enemy ADC alone in the side lane with no vision nearby. You can reach them in four seconds. What's your first thought?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Go for the kill — solo picks win games.',
                            'diagnostic_payload' => [
                                'traits'         => ['kill_instinct' => 2, 'independence' => 2, 'risk_tolerance' => 1],
                                'interpretation' => 'Immediately identifies and commits to the kill opportunity.',
                            ],
                        ],
                        [
                            'text'               => 'Check the minimap for the rest of their team before moving.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'patience' => 1],
                                'interpretation' => 'Gathers more information before committing.',
                            ],
                        ],
                        [
                            'text'               => 'Alert your team to set up a 5v4 teamfight instead.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'independence' => -1],
                                'interpretation' => 'Converts the pick opportunity into a structured team play.',
                            ],
                        ],
                        [
                            'text'               => 'Take the nearest objective while they\'re out of position.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'adaptability' => 1],
                                'interpretation' => 'Uses enemy mistake to secure macro objectives rather than a kill.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You're 0/4 in the early game through no single avoidable mistake — just lost trades and pressure. What is your most likely response?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Play extremely safe and focus entirely on farming.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'defensive_discipline' => 2],
                                'interpretation' => 'Accepts the deficit and focuses on safe scaling.',
                            ],
                        ],
                        [
                            'text'               => 'Look for an unconventional play to flip the game.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'risk_tolerance' => 2, 'aggression' => 1],
                                'interpretation' => 'Seeks an unexpected play to reverse momentum.',
                            ],
                        ],
                        [
                            'text'               => 'Identify what the enemy is doing to beat me and adjust.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'control_orientation' => 1],
                                'interpretation' => 'Diagnoses the problem first and adapts the gameplan.',
                            ],
                        ],
                        [
                            'text'               => 'Continue my normal game plan — the score will even out.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'adaptability' => -1],
                                'interpretation' => 'Stays committed to their usual style regardless of the deficit.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Your team wins a clean 5v5 teamfight in the mid game with all enemies dead. You have 20 seconds before they respawn. What do you prioritise?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Rush the nearest inhibitor to force a base defence.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'pressure_orientation' => 2, 'risk_tolerance' => 1],
                                'interpretation' => 'Maximises aggression to turn a fight win into a base threat.',
                            ],
                        ],
                        [
                            'text'               => 'Secure Baron or Dragon for the objective buff.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'control_orientation' => 1],
                                'interpretation' => 'Converts fight win into a controlled objective advantage.',
                            ],
                        ],
                        [
                            'text'               => 'Recall and spend gold — the item spike matters more.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'structure_preference' => 1],
                                'interpretation' => 'Values the power spike of items over immediate map control.',
                            ],
                        ],
                        [
                            'text'               => 'Identify the highest-value thing to take and do it.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'control_orientation' => 1],
                                'interpretation' => 'Evaluates the situation dynamically rather than defaulting to a set play.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "In champion select, your team has two carries and needs a tank. You were planning to play a carry. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Pick the tank — team needs it.',
                            'diagnostic_payload' => [
                                'traits'         => ['independence' => -1, 'structure_preference' => 1, 'adaptability' => 1],
                                'interpretation' => 'Prioritises team composition over personal preference.',
                            ],
                        ],
                        [
                            'text'               => 'Play my carry anyway — I\'m more impactful there.',
                            'diagnostic_payload' => [
                                'traits'         => ['independence' => 2, 'aggression' => 1],
                                'interpretation' => 'Backs personal skill over team composition optimisation.',
                            ],
                        ],
                        [
                            'text'               => 'Ask a teammate to swap roles.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 1, 'structure_preference' => 1, 'independence' => 1],
                                'interpretation' => 'Tries to optimise the team\'s overall composition through coordination.',
                            ],
                        ],
                        [
                            'text'               => 'Pick a champion that can be played both ways.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'adaptability' => 2],
                                'interpretation' => 'Looks for a flexible pick that satisfies both needs.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You and your jungler have the enemy support cornered in a 2v1. They\'ve used flash. You\'re at 50% health with abilities on cooldown. Do you:",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Commit everything to secure the kill.',
                            'diagnostic_payload' => [
                                'traits'         => ['kill_instinct' => 2, 'aggression' => 2, 'risk_tolerance' => 1],
                                'interpretation' => 'Prioritises the kill window over safe resource management.',
                            ],
                        ],
                        [
                            'text'               => 'Wait for abilities to come back before committing.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'control_orientation' => 1],
                                'interpretation' => 'Delays to ensure the kill is clean and low-risk.',
                            ],
                        ],
                        [
                            'text'               => 'Zone them off and let your jungler secure it.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'defensive_discipline' => 1],
                                'interpretation' => 'Uses positioning to assist without overcommitting personal resources.',
                            ],
                        ],
                        [
                            'text'               => 'Back off — the kill isn\'t worth the risk at 50% health.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2, 'risk_tolerance' => -1],
                                'interpretation' => 'Prioritises self-preservation over the kill opportunity.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You review a ranked game you lost. What part of the replay do you focus on first?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'The moments I died — what I could have done differently.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2, 'adaptability' => 1],
                                'interpretation' => 'Reviews losses through survivability and individual decision errors.',
                            ],
                        ],
                        [
                            'text'               => 'Missed kill opportunities and fights I should have taken.',
                            'diagnostic_payload' => [
                                'traits'         => ['kill_instinct' => 2, 'aggression' => 1],
                                'interpretation' => 'Reviews losses through missed offensive windows.',
                            ],
                        ],
                        [
                            'text'               => 'Objective timing and macro rotations.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'structure_preference' => 1],
                                'interpretation' => 'Reviews losses through map control and objective decisions.',
                            ],
                        ],
                        [
                            'text'               => 'How the game state shifted and what I adapted to.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'creativity' => 1],
                                'interpretation' => 'Reviews losses by analysing how the game evolved and how they responded.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
