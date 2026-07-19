<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class PokerDiagnosticModuleSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'Poker')->first();

        if (! $subject) {
            $this->command->error('❌ Poker subject not found. Run SubjectSeeder first.');
            return;
        }

        $proficiency = Proficiency::where('name', 'Beginner')
            ->where('subject_id', $subject->id)
            ->first();

        if (! $proficiency) {
            $this->command->error('❌ Beginner proficiency not found for Poker subject. Run ProficiencySeeder first.');
            return;
        }

        $module = $this->seedModule($subject, $proficiency);
        $this->seedQuestions($module);
    }

    private function seedModule(Subject $subject, Proficiency $proficiency): Module
    {
        $module = Module::firstOrCreate(
            ['name' => 'Poker Playstyle Assessment', 'subject_id' => $subject->id],
            [
                'description' => 'Discover how you naturally think and make decisions at the poker table. There are no right or wrong answers.',
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

        $this->command->info('✅ Poker Playstyle Assessment module seeded.');

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

        $this->command->info("✅ Poker diagnostic questions: {$created} created, {$linked} linked to module.");
    }

    private function surveyQuestions(): array
    {
        return [
            [
                'type'     => 'survey_mcq',
                'question' => 'What stakes do you currently play?',
                'answer'   => [
                    'question_key' => 'current_stakes',
                    'options'      => [
                        ['text' => 'Micro stakes (up to $0.25/$0.50)', 'value' => 1],
                        ['text' => 'Low stakes ($0.50/$1 – $1/$2)',    'value' => 2],
                        ['text' => 'Mid stakes ($2/$5 – $5/$10)',      'value' => 3],
                        ['text' => 'High stakes ($10/$25+)',            'value' => 4],
                        ['text' => "Not sure / new to poker",           'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'Which format do you play most often?',
                'answer'   => [
                    'question_key' => 'primary_format',
                    'options'      => [
                        ['text' => 'Cash games',                'value' => 1],
                        ['text' => 'Tournaments (MTTs)',         'value' => 2],
                        ['text' => 'Sit & Gos / Spin-ups',       'value' => 3],
                        ['text' => 'I play a mix',               'value' => 4],
                        ['text' => "Not sure / new to poker",    'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'What is your main goal at the table?',
                'answer'   => [
                    'question_key' => 'primary_goal',
                    'options'      => [
                        ['text' => 'Learn poker fundamentals',      'value' => 1],
                        ['text' => 'Move up in stakes',              'value' => 2],
                        ['text' => 'Reduce variance / swings',       'value' => 3],
                        ['text' => 'Become a consistently winning player', 'value' => 4],
                        ['text' => 'Compete at a high level',        'value' => 5],
                    ],
                ],
            ],
            [
                'type'     => 'survey_mcq',
                'question' => 'What do you think is holding your results back the most?',
                'answer'   => [
                    'question_key' => 'self_assessed_weakness',
                    'options'      => [
                        ['text' => 'Hand reading',        'value' => 1],
                        ['text' => 'Bet sizing',           'value' => 2],
                        ['text' => 'Mental game / tilt',   'value' => 3],
                        ['text' => 'Preflop strategy',     'value' => 4],
                        ['text' => "I'm not sure",         'value' => 5],
                    ],
                ],
            ],
        ];
    }

    private function questions(): array
    {
        return [
            [
                'question' => "You're first to act preflop in a fresh hand with no reads yet. What's going through your mind?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "I'm already sizing up which opponents look exploitable this hand.",
                            'diagnostic_payload' => ['traits' => ['kill_instinct' => 2, 'aggression' => 1]],
                        ],
                        [
                            'text'               => "I want to see the action in front of me before deciding how to play my hand.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "I open with whatever range I've mapped out for this position.",
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "I react to whatever the table gives me and adjust from there.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You're mid-bluff on the turn, fully committed to your line, when a scare card hits the river that could easily have hit your opponent's range too. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Give up the bluff — protecting my stack matters more than finishing the story.",
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Fire the river anyway — backing off mid-bluff usually just burns chips for nothing.",
                            'diagnostic_payload' => ['traits' => ['kill_instinct' => 2, 'independence' => 1]],
                        ],
                        [
                            'text'               => "Quickly re-read their range before deciding whether the story still holds.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Go with my gut — if firing feels right in the moment, I fire.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'kill_instinct' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You're running a planned three-street bluff. On the turn, your opponent checks in a way that looks like real weakness — a bigger, cleaner opportunity than your original plan. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Switch immediately to attack the weakness — a real opening beats a planned one every time.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'kill_instinct' => 2]],
                        ],
                        [
                            'text'               => "Stick to the plan — abandoning a bluff line half-built is usually worse than finishing it.",
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Note it and size up big, but only after re-checking that my read is right.",
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => "Improvise a bigger sizing on the spot — I'll make it work.",
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'risk_tolerance' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "One opponent keeps folding to your continuation bets every single time, denying you any real information or value. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Adjust my bet sizing and frequency to close off the easy folds they keep taking.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Stop targeting them — a player who folds that easily isn't where the real value is.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => "Keep firing the same way and wait for them to snap and pay one off big.",
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'pressure_orientation' => 1]],
                        ],
                        [
                            'text'               => "Mix in an unusual line or sizing to bait a different reaction out of them.",
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'aggression' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "A hand plays out where you see a profitable bluff-raise your usual strategy chart doesn't cover. Your gut says raise, but your trained default says just call. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Play my read — I'm the one at the table seeing live information the chart can't capture.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => "Stick to the trained default — it's right more often than my gut is.",
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => "Blend the two — raise smaller than my gut wants, closer to a compromise.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'independence' => 1]],
                        ],
                        [
                            'text'               => "Take the shot — worst case it's a spew, best case it's the sharpest play of the session.",
                            'diagnostic_payload' => ['traits' => ['risk_tolerance' => 2, 'aggression' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You're up big for the session but just lost a huge pot and you're now short relative to where you started. The table gets aggressive at you. What do you do?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Play tight and let the session's edge protect me — I don't need to force anything back.",
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Play back aggressively — they're probably overplaying the momentum just as much as I am.",
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'risk_tolerance' => 1]],
                        ],
                        [
                            'text'               => "Take it hand by hand — only push back when a specific spot actually looks good.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'risk_tolerance' => 1]],
                        ],
                        [
                            'text'               => "Consider stepping away — the session result doesn't disappear just because I sit out for a bit.",
                            'diagnostic_payload' => ['traits' => ['patience' => 2, 'independence' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "Of these four, which demands the most of your mental attention during a session?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Opponents' likely ranges and how their actions narrow them — my decisions build off what they probably have.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'structure_preference' => 1]],
                        ],
                        [
                            'text'               => "My own stack and table image — how I'm perceived and what that lets me get away with.",
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'independence' => 1]],
                        ],
                        [
                            'text'               => "Where the momentum of the table is shifting and who's tilting or tightening up.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'pressure_orientation' => 1]],
                        ],
                        [
                            'text'               => "Spots to apply pressure — I'm constantly scanning for a profitable opening to attack.",
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'kill_instinct' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You win a big session after your original game plan completely fell apart early on. What was the actual reason you turned it around?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "I adapted in real time and found new lines as the table evolved.",
                            'diagnostic_payload' => ['traits' => ['adaptability' => 2, 'creativity' => 1]],
                        ],
                        [
                            'text'               => "I spotted the right spot and committed to the value.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'kill_instinct' => 1]],
                        ],
                        [
                            'text'               => "I stayed disciplined under pressure and didn't spew a single unnecessary chip.",
                            'diagnostic_payload' => ['traits' => ['defensive_discipline' => 2, 'structure_preference' => 1]],
                        ],
                        [
                            'text'               => "I took over and created my own opportunities instead of waiting for good cards.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'aggression' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "You're down two buy-ins in a session against opponents you know you're better than. What changes?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Figure out exactly what's causing the losses before changing anything.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'patience' => 1]],
                        ],
                        [
                            'text'               => "Try something they haven't seen — the current approach clearly isn't landing.",
                            'diagnostic_payload' => ['traits' => ['creativity' => 2, 'risk_tolerance' => 1]],
                        ],
                        [
                            'text'               => "Ramp up the pressure and force them into tougher decisions.",
                            'diagnostic_payload' => ['traits' => ['aggression' => 2, 'pressure_orientation' => 1]],
                        ],
                        [
                            'text'               => "Lock in and tighten execution — the strategy is fine, the errors aren't.",
                            'diagnostic_payload' => ['traits' => ['structure_preference' => 2, 'patience' => 1]],
                        ],
                    ],
                ],
            ],
            [
                'question' => "When you're playing your best poker, which of these best describes what it actually looks like?",
                'answer'   => [
                    'options' => [
                        [
                            'text'               => "Controlled and deliberate — every bet is intentional and nothing is wasted.",
                            'diagnostic_payload' => ['traits' => ['control_orientation' => 2, 'defensive_discipline' => 1]],
                        ],
                        [
                            'text'               => "Relentless — I keep the pressure on and opponents are always reacting to me.",
                            'diagnostic_payload' => ['traits' => ['pressure_orientation' => 2, 'aggression' => 1]],
                        ],
                        [
                            'text'               => "Instinctive — I'm reading hands faster than I can consciously explain why.",
                            'diagnostic_payload' => ['traits' => ['reactivity' => 2, 'adaptability' => 1]],
                        ],
                        [
                            'text'               => "Self-directed — I create my own spots rather than waiting for a hand to play itself.",
                            'diagnostic_payload' => ['traits' => ['independence' => 2, 'kill_instinct' => 1]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
