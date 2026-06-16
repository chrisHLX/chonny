<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
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
        $linked = 0;

        foreach ($this->questions() as $q) {
            $question = Question::firstOrCreate(
                ['question' => $q['question']],
                [
                    'type'       => 'diagnostic_mcq',
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

    private function questions(): array
    {
        return [
            [
                'question' => "Enemy Rogue uses Shadow Blades. Enemy Mage uses Combustion. Your healer is stunned with no trinket. You have: Trinket, Wall, Disarm. What is your first response?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Trinket immediately and run.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 1, 'reactivity' => 2, 'risk_tolerance' => -1],
                                'interpretation' => 'Quick, safety-first reaction prioritising immediate survival.',
                            ],
                        ],
                        [
                            'text'               => 'Wall first and hold trinket.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2, 'patience' => 1],
                                'interpretation' => 'Conserves resources by leaning on a defensive cooldown before committing the trinket.',
                            ],
                        ],
                        [
                            'text'               => 'Disarm Rogue and reposition.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'defensive_discipline' => 1, 'patience' => 1],
                                'interpretation' => 'Neutralises the threat directly while creating space.',
                            ],
                        ],
                        [
                            'text'               => 'Hold everything unless health drops dangerously low.',
                            'diagnostic_payload' => [
                                'traits'         => ['risk_tolerance' => 2, 'patience' => 1, 'defensive_discipline' => -1],
                                'interpretation' => 'Comfortable absorbing risk before reacting.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => 'You force enemy trinket during the opener. What is your next thought?',
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Try to end the game immediately.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 2, 'kill_instinct' => 2, 'risk_tolerance' => 1],
                                'interpretation' => 'Sees a forced trinket as a finishing opportunity.',
                            ],
                        ],
                        [
                            'text'               => 'Track remaining cooldowns before committing further.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'control_orientation' => 1, 'patience' => 1],
                                'interpretation' => 'Methodically tracks resources before acting.',
                            ],
                        ],
                        [
                            'text'               => 'Reset and prepare a cleaner setup.',
                            'diagnostic_payload' => [
                                'traits'         => ['patience' => 2, 'structure_preference' => 2],
                                'interpretation' => 'Prefers a deliberate, well-prepared follow-up over an immediate push.',
                            ],
                        ],
                        [
                            'text'               => 'Keep pressure high and see what happens.',
                            'diagnostic_payload' => [
                                'traits'         => ['pressure_orientation' => 2, 'adaptability' => 1, 'structure_preference' => -1],
                                'interpretation' => 'Maintains tempo and reacts to outcomes as they unfold.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => 'You are ahead on damage but behind on cooldowns. What concerns you most?',
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Losing momentum.',
                            'diagnostic_payload' => [
                                'traits'         => ['pressure_orientation' => 2, 'aggression' => 1],
                                'interpretation' => 'Values sustained tempo over cooldown parity.',
                            ],
                        ],
                        [
                            'text'               => 'Being unable to survive the next enemy push.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2, 'structure_preference' => 1],
                                'interpretation' => 'Concerned primarily with surviving the next exchange.',
                            ],
                        ],
                        [
                            'text'               => 'Whether the enemy still has a win condition.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'adaptability' => 1],
                                'interpretation' => 'Thinks in terms of controlling the enemy\'s remaining options.',
                            ],
                        ],
                        [
                            'text'               => 'Nothing, keep pushing.',
                            'diagnostic_payload' => [
                                'traits'         => ['risk_tolerance' => 2, 'aggression' => 2, 'defensive_discipline' => -1],
                                'interpretation' => 'Disregards cooldown deficit in favour of continued aggression.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => 'You enter a new matchup you rarely play against. What do you do first?',
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Play aggressively and learn as you go.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 1, 'adaptability' => 2, 'risk_tolerance' => 1],
                                'interpretation' => 'Learns through active engagement rather than observation.',
                            ],
                        ],
                        [
                            'text'               => 'Observe enemy cooldown usage carefully.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 1, 'patience' => 2],
                                'interpretation' => 'Gathers information before committing to a plan.',
                            ],
                        ],
                        [
                            'text'               => 'Focus on surviving until you understand the matchup.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2, 'patience' => 1],
                                'interpretation' => 'Prioritises safety while building understanding.',
                            ],
                        ],
                        [
                            'text'               => 'Follow your normal gameplan regardless.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'adaptability' => -1],
                                'interpretation' => 'Relies on established routines even in unfamiliar situations.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => 'Your target reaches low health unexpectedly. What are you most likely to do?',
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Commit additional resources to secure the kill.',
                            'diagnostic_payload' => [
                                'traits'         => ['kill_instinct' => 2, 'aggression' => 1],
                                'interpretation' => 'Recognises and commits to a genuine finishing opportunity.',
                            ],
                        ],
                        [
                            'text'               => 'Evaluate what cooldowns remain first.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'patience' => 1],
                                'interpretation' => 'Checks the broader resource state before committing.',
                            ],
                        ],
                        [
                            'text'               => 'Wait for teammates to commit before acting.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 1, 'independence' => -1],
                                'interpretation' => 'Defers initiative to teammates rather than acting independently.',
                            ],
                        ],
                        [
                            'text'               => 'Maintain pressure but avoid overcommitting.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 1, 'patience' => 1],
                                'interpretation' => 'Balances opportunity against overextension risk.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => 'Your team loses the opener. What is your mindset?',
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Look for an unexpected offensive opportunity.',
                            'diagnostic_payload' => [
                                'traits'         => ['creativity' => 2, 'aggression' => 1],
                                'interpretation' => 'Looks for unconventional ways to flip momentum.',
                            ],
                        ],
                        [
                            'text'               => 'Stabilise and recover resources.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2, 'patience' => 2],
                                'interpretation' => 'Prioritises recovery before attempting to push back.',
                            ],
                        ],
                        [
                            'text'               => 'Identify what caused the failure.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'structure_preference' => 1],
                                'interpretation' => 'Adjusts based on diagnosing the root cause.',
                            ],
                        ],
                        [
                            'text'               => 'Continue the original plan.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'adaptability' => -1],
                                'interpretation' => 'Stays committed to the original gameplan despite the setback.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => 'What is most satisfying in arena?',
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Perfect crowd control chains.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'structure_preference' => 1],
                                'interpretation' => 'Draws satisfaction from precise, controlled execution.',
                            ],
                        ],
                        [
                            'text'               => 'Securing unexpected kills.',
                            'diagnostic_payload' => [
                                'traits'         => ['kill_instinct' => 2, 'creativity' => 1],
                                'interpretation' => 'Enjoys capitalising on unexpected finishing windows.',
                            ],
                        ],
                        [
                            'text'               => 'Outlasting enemy cooldowns.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2, 'patience' => 2],
                                'interpretation' => 'Finds reward in patient, attritional play.',
                            ],
                        ],
                        [
                            'text'               => 'Constant pressure throughout the match.',
                            'diagnostic_payload' => [
                                'traits'         => ['pressure_orientation' => 2, 'aggression' => 1],
                                'interpretation' => 'Enjoys maintaining relentless tempo.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => 'Your healer calls for a reset. What is your typical reaction?',
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Reset immediately.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2, 'independence' => -1],
                                'interpretation' => 'Defers to the healer\'s call without hesitation.',
                            ],
                        ],
                        [
                            'text'               => 'Look for one last opportunity first.',
                            'diagnostic_payload' => [
                                'traits'         => ['aggression' => 1, 'kill_instinct' => 1, 'risk_tolerance' => 1],
                                'interpretation' => 'Weighs a final opportunity against the call to disengage.',
                            ],
                        ],
                        [
                            'text'               => 'Evaluate positioning before deciding.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'patience' => 1],
                                'interpretation' => 'Assesses the situation independently before committing to a reset.',
                            ],
                        ],
                        [
                            'text'               => 'Stay in and continue pressure.',
                            'diagnostic_payload' => [
                                'traits'         => ['pressure_orientation' => 2, 'risk_tolerance' => 2],
                                'interpretation' => 'Overrides the reset call to keep pressure on.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => 'What wins more games?',
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Better mechanics.',
                            'diagnostic_payload' => [
                                'traits'         => ['independence' => 1, 'aggression' => 1],
                                'interpretation' => 'Believes individual execution is the deciding factor.',
                            ],
                        ],
                        [
                            'text'               => 'Better positioning.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 1, 'patience' => 1],
                                'interpretation' => 'Believes spatial control decides outcomes.',
                            ],
                        ],
                        [
                            'text'               => 'Better cooldown management.',
                            'diagnostic_payload' => [
                                'traits'         => ['structure_preference' => 2, 'defensive_discipline' => 1],
                                'interpretation' => 'Believes resource discipline decides outcomes.',
                            ],
                        ],
                        [
                            'text'               => 'Better adaptation.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'creativity' => 1],
                                'interpretation' => 'Believes flexibility decides outcomes.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'question' => 'You review a loss. What are you most likely to focus on?',
                'answer'   => [
                    'options' => [
                        [
                            'text'               => 'Missed kill opportunities.',
                            'diagnostic_payload' => [
                                'traits'         => ['kill_instinct' => 2, 'aggression' => 1],
                                'interpretation' => 'Reviews losses through missed finishing windows.',
                            ],
                        ],
                        [
                            'text'               => 'Defensive mistakes.',
                            'diagnostic_payload' => [
                                'traits'         => ['defensive_discipline' => 2],
                                'interpretation' => 'Reviews losses through survivability errors.',
                            ],
                        ],
                        [
                            'text'               => 'Positioning and setup issues.',
                            'diagnostic_payload' => [
                                'traits'         => ['control_orientation' => 2, 'structure_preference' => 1],
                                'interpretation' => 'Reviews losses through spatial and setup discipline.',
                            ],
                        ],
                        [
                            'text'               => 'How the game changed over time.',
                            'diagnostic_payload' => [
                                'traits'         => ['adaptability' => 2, 'creativity' => 1],
                                'interpretation' => 'Reviews losses through how the matchup evolved.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
