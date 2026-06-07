<?php

namespace Database\Seeders;

use App\Models\Concept;
use App\Models\Module;
use App\Models\ModulePage;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
use Illuminate\Database\Seeder;

// Concepts are seeded by ConceptSeeder from data/concepts.json.
// This seeder does NOT create concepts — it only creates modules, pages, and questions
// tagged to the existing broad LoL concept taxonomy.
//
// Valid LoL concepts: Laning Phase, Macro Play, Micro Mechanics, Itemization,
// Teamfighting, Map Awareness, Champion Knowledge, Drafting & Strategy,
// Jungle Pathing & Objectives, Mindset & Improvement

class LolLaunchSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'League of Legends')->first();

        if (! $subject) {
            $this->command->error('❌ League of Legends subject not found. Run SubjectSeeder first.');
            return;
        }

        $this->seedModules($subject);
        $this->seedModulePages();
        $this->seedQuestions($subject);
    }

    private function seedModules(Subject $subject): void
    {
        $modules = [
            [
                'name'        => 'Laning Fundamentals',
                'description' => 'Master the early game by learning how to manage waves, trade effectively, and maintain lane control. This module builds a foundation for strong mid and late game performance.',
                'proficiency' => 'Casual',
            ],
        ];

        foreach ($modules as $data) {
            $proficiency = Proficiency::where('name', $data['proficiency'])
                ->where('subject_id', $subject->id)
                ->first();

            if (! $proficiency) {
                $this->command->warn("⚠️ Proficiency '{$data['proficiency']}' not found for LoL — skipping '{$data['name']}'.");
                continue;
            }

            $module = Module::firstOrCreate(
                ['name' => $data['name'], 'subject_id' => $subject->id],
                [
                    'description' => $data['description'],
                    'published'   => true,
                    'status'      => 'ready',
                ]
            );

            if (! $module->proficiencies()->where('proficiency_id', $proficiency->id)->exists()) {
                $module->proficiencies()->attach($proficiency->id);
            }
        }

        $this->command->info('✅ LoL launch modules seeded.');
    }

    private function seedModulePages(): void
    {
        $created = 0;
        $skipped = 0;

        foreach ($this->pages() as $entry) {
            $module = Module::where('name', $entry['module_name'])->first();

            if (! $module) {
                $this->command->warn("⚠️ Module not found: {$entry['module_name']}");
                $skipped++;
                continue;
            }

            ModulePage::updateOrCreate(
                ['module_id' => $module->id, 'page_number' => $entry['page_number']],
                [
                    'title'      => $entry['title'],
                    'content'    => $entry['content'],
                    'created_by' => null,
                    'updated_by' => null,
                ]
            );
            $created++;
        }

        $this->command->info("✅ LoL launch module pages: {$created} upserted, {$skipped} skipped.");
    }

    private function seedQuestions(Subject $subject): void
    {
        $created = 0;
        $linked  = 0;

        foreach ($this->questions() as $entry) {
            $module = Module::where('name', $entry['module_name'])
                ->where('subject_id', $subject->id)
                ->first();

            if (! $module) {
                $this->command->warn("⚠️ Module not found: {$entry['module_name']}");
                continue;
            }

            foreach ($entry['questions'] as $q) {
                $question = Question::firstOrCreate(
                    ['question' => $q['question']],
                    [
                        'type'       => $q['type'],
                        'difficulty' => $q['difficulty'],
                        'skill_type' => $q['skill_type'],
                        'answer'     => $q['answer'],
                    ]
                );

                if ($question->wasRecentlyCreated) {
                    $created++;
                }

                $module->questions()->syncWithoutDetaching([$question->id]);
                $linked++;

                $conceptNames = $q['concepts'] ?? [];
                if (! empty($conceptNames)) {
                    $conceptIds = Concept::where('subject_id', $subject->id)
                        ->whereIn('name', $conceptNames)
                        ->pluck('id')
                        ->toArray();
                    $question->concepts()->syncWithoutDetaching($conceptIds);
                }
            }
        }

        $this->command->info("✅ LoL launch questions: {$created} created, {$linked} linked to modules.");
    }

    // =========================================================================
    // PAGE CONTENT
    // =========================================================================

    private function pages(): array
    {
        return [

            // -----------------------------------------------------------------
            // MODULE 1: Laning Fundamentals
            // -----------------------------------------------------------------
            [
                'module_name' => 'Laning Fundamentals',
                'page_number' => 1,
                'title'       => 'Laning Fundamentals',
                'content'     => <<<'MD'
# Laning Fundamentals

## The Purpose of the Lane Phase

The laning phase sets the conditions for everything that follows. Every minion you farm, every trade you win, and every piece of vision you establish creates advantages that compound over time. A player who understands laning arrives at the first major objective fight with more gold, better experience, and cleaner information than their opponent.

## Wave Management

**Wave management** is the deliberate control of where minion waves collide in the lane. Wave position determines your safety, your ability to trade, and your macro options.

**Freezing** means maintaining the wave near your own tower without letting it push forward. You achieve this by killing enemy minions at the same rate your own minions die — the wave stays stationary. Freezing forces your opponent to walk toward your tower to farm, exposing them to jungle ganks and restricting their trading angles.

**Slow pushing** means allowing your side of the wave to grow larger than the enemy's without immediately crashing it. You kill enemy minions slightly faster than they arrive, building a large wave over several seconds. Slow pushes are used before recalling, before objectives like Dragon or Baron, or before a roam — when the wave finally crashes, it occupies the enemy tower and forces a response.

**Fast pushing** means clearing the enemy wave as quickly as possible to crash it into their tower. Fast pushes reset wave position and are used when you need to recall quickly, when you want to deny the enemy safe farming, or when you need to rotate before an objective spawns.

## Trading

**Trading** means exchanging health and ability use with the enemy laner. Effective trading requires recognising windows when your position or cooldown state gives you an advantage.

Good trading windows include:
- After your opponent uses an ability on minions, leaving them temporarily weaker
- When your opponent steps up for a last-hit and exposes themselves
- When a level advantage makes a longer exchange correct for you

Against a melee champion as a ranged player, the correct pattern is to auto-attack when the melee steps in for a last-hit, then immediately retreat before drawing minion aggro. Short, repeated poke trades accumulate into a significant health lead without committing to full exchanges.

Never trade under the enemy tower unless you have a decisive health lead — tower shots reduce the value of winning the exchange.

## Vision in Lane

The first ward of the laning phase should go in the river near your side — river bush or tri-bush depending on your lane. This provides warning of jungle ganks before the enemy jungler is close enough to commit.

After recalling, purchasing a Control Ward to deny vision in key bushes removes the enemy's information advantage in your lane. Placing wards into the enemy jungle beyond the river identifies jungler pathing and allows informed decisions about when to extend or play safe.

## Macro Decisions from Lane

After crashing a wave into the enemy tower, a window of free time opens. The best uses of this time are:
- **Recall** to restore health and purchase items
- **Ward** the enemy jungle for information on their jungler's position
- **Roam** to assist a nearby lane or contest a river objective

Never recall with a wave pushing toward your own tower. The gold and experience lost to your tower while you are base compounds over a game and hands your opponent a slow but real advantage.

Always ask: does my current wave state create or deny options for my team right now?
MD,
            ],
        ];
    }

    // =========================================================================
    // QUESTIONS
    // =========================================================================

    private function questions(): array
    {
        return [

            // =================================================================
            // MODULE 1: Laning Fundamentals
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Laning Fundamentals',
                'questions'   => [
                    [
                        'question'   => 'What does "freezing" a wave mean in League of Legends?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Maintaining the wave near your own tower so your opponent cannot farm safely',
                            'options' => [
                                'Maintaining the wave near your own tower so your opponent cannot farm safely',
                                'Pushing the wave into the enemy tower as quickly as possible',
                                'Allowing the wave to reset to the centre of the lane naturally',
                                'Standing between your minions and the enemy to block their attacks',
                            ],
                        ],
                        'concepts' => ['Laning Phase'],
                    ],
                    [
                        'question'   => 'True or False: Slow pushing a wave before an objective fight creates a timing advantage because the enemy must respond to the incoming wave.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Laning Phase', 'Macro Play'],
                    ],
                    [
                        'question'   => 'What is "last-hitting" in League of Legends?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Dealing the killing blow to a minion at the moment it would die to collect the gold reward',
                            'options' => [
                                'Dealing the killing blow to a minion at the moment it would die to collect the gold reward',
                                'Attacking an enemy champion only when they are below half health',
                                'Using an ability to clear the entire minion wave simultaneously',
                                'Auto-attacking every minion to deal as much total damage as possible',
                            ],
                        ],
                        'concepts' => ['Laning Phase', 'Micro Mechanics'],
                    ],
                    [
                        'question'   => 'True or False: Constantly pushing your wave toward the enemy tower is always the optimal laning strategy.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Laning Phase'],
                    ],
                    [
                        'question'   => 'After crashing a large wave into the enemy tower, which action creates the most value?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Recall, ward the enemy jungle, or roam — use the free time window actively',
                            'options' => [
                                'Recall, ward the enemy jungle, or roam — use the free time window actively',
                                'Stand in lane and wait for the next wave to arrive',
                                'Engage the enemy laner under their tower while the wave is crashing',
                                'Walk back to your own tower to ensure complete safety before deciding',
                            ],
                        ],
                        'concepts' => ['Laning Phase', 'Macro Play'],
                    ],
                    [
                        'question'   => 'True or False: A ward placed in the river near your lane provides early warning of an incoming enemy jungler gank.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Map Awareness'],
                    ],
                    [
                        'question'   => 'Match each wave management technique to its primary purpose.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Freezing'         => 'Keeps the wave near your tower to deny safe farming',
                                'Slow Push'        => 'Builds a large wave before a recall or objective fight',
                                'Fast Push'        => 'Crashes the wave quickly to reset position or recall',
                                'Holding the Wave' => 'Maintains wave position to control lane tempo',
                            ],
                            'pairs' => [
                                'keys'   => ['Freezing', 'Slow Push', 'Fast Push', 'Holding the Wave'],
                                'values' => [
                                    'Builds a large wave before a recall or objective fight',
                                    'Crashes the wave quickly to reset position or recall',
                                    'Maintains wave position to control lane tempo',
                                    'Keeps the wave near your tower to deny safe farming',
                                ],
                            ],
                        ],
                        'concepts' => ['Laning Phase'],
                    ],
                    [
                        'question'   => 'Your opponent has a level advantage and all abilities available. What is the correct trading approach?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Wait for them to use abilities on minions, then trade briefly with basic attacks before retreating',
                            'options' => [
                                'Wait for them to use abilities on minions, then trade briefly with basic attacks before retreating',
                                'All-in immediately to end the trade before their level advantage can be applied',
                                'Avoid all interaction and only farm under your tower until the gap closes',
                                'Use all your abilities first to force them onto the back foot',
                            ],
                        ],
                        'concepts' => ['Laning Phase', 'Micro Mechanics'],
                    ],
                    [
                        'question'   => 'The enemy jungler\'s position is unknown on the minimap. How should this affect your lane decisions?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Play more conservatively — keep the wave near your tower and avoid overextending until you have their location',
                            'options' => [
                                'Play more conservatively — keep the wave near your tower and avoid overextending until you have their location',
                                'Push aggressively to punish the enemy laner while the jungler is unavailable',
                                'Roam immediately to find the jungler before they can reach your lane',
                                'Take the enemy tower, then retreat — any time the jungler is missing is an opportunity',
                            ],
                        ],
                        'concepts' => ['Laning Phase', 'Map Awareness'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order for a safe recall from lane.',
                        'type'       => 'ordering',
                        'difficulty' => 'medium',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Push or crash the wave into the enemy tower so it cannot damage your own tower while you are away',
                                'Check the minimap to confirm no enemies are approaching your position',
                                'Move to a safe position before beginning the recall channel',
                                'Complete the recall at the fountain',
                            ],
                        ],
                        'concepts' => ['Laning Phase', 'Macro Play'],
                    ],
                    [
                        'question'   => 'You are behind in kills but have a wave advantage. The enemy jungler has been camping your lane repeatedly. What is the correct play?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Freeze the wave near your tower — deny your opponent safe farm while minimising exposure to further ganks',
                            'options' => [
                                'Freeze the wave near your tower — deny your opponent safe farm while minimising exposure to further ganks',
                                'Push the wave aggressively to punish them while you still have a wave lead',
                                'Roam to another lane to escape the jungle pressure and reset the matchup',
                                'All-in the enemy laner immediately to trade kills and equalise the score',
                            ],
                        ],
                        'concepts' => ['Laning Phase', 'Macro Play'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order to set up a slow push before a Dragon fight.',
                        'type'       => 'ordering',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Clear the current wave evenly so neither side has a large wave building yet',
                                'Begin killing enemy minions slightly faster than they arrive to start building your wave',
                                'Continue the slow push for two to three wave cycles until a large wave accumulates',
                                'Crash the large wave into the enemy tower just before the Dragon fight begins',
                                'Rotate to the Dragon objective while the enemy must choose between defending their tower and contesting the fight',
                            ],
                        ],
                        'concepts' => ['Laning Phase', 'Macro Play'],
                    ],
                ],
            ],
        ];
    }
}
