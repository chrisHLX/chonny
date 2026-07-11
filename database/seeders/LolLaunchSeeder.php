<?php

namespace Database\Seeders;

use App\Models\Concept;
use App\Models\Module;
use App\Models\ModulePage;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
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
            [
                'name'        => 'Drafting & Champion Fundamentals',
                'description' => 'Understand what a draft is actually deciding — champion archetypes, power spikes, damage type balance, and why a comp with a clear identity beats five strong individual picks with no plan.',
                'proficiency' => 'Casual',
            ],
            [
                'name'        => 'Jungle Pathing & Objectives Fundamentals',
                'description' => 'Learn how clear order, gank timing, objective priority, and tempo fit together — and how to read the whole map, not just your own jungle path.',
                'proficiency' => 'Casual',
            ],
            [
                'name'        => 'Itemization & Teamfighting Fundamentals',
                'description' => 'Understand core vs situational items, damage type itemization, and how to read a teamfight before and during the fight itself.',
                'proficiency' => 'Casual',
            ],
            [
                'name'        => 'Mindset & Improvement Fundamentals',
                'description' => 'Learn why mindset is a skill — process over outcome, recognising tilt, reviewing your own games honestly, and improving incrementally.',
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
                    'created_by'  => User::where('email', User::SYSTEM_ENGINE_EMAIL)->value('id'),
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

            // -----------------------------------------------------------------
            // MODULE 2: Drafting & Champion Fundamentals
            // -----------------------------------------------------------------
            [
                'module_name' => 'Drafting & Champion Fundamentals',
                'page_number' => 1,
                'title'       => 'Drafting & Champion Fundamentals',
                'content'     => <<<'MD'
# Drafting & Champion Fundamentals

## What Draft Is Actually Deciding

Draft is not about picking the five individually strongest champions — it's about building a team with a clear identity: a plan for how the game gets won. A draft has direction when everyone understands the comp's win condition, whether that's forcing early skirmishes, scaling to a late-game teamfight, or splitting the map to pressure multiple objectives at once. A draft without direction — five strong picks with no shared plan — is far weaker than its individual parts suggest.

## Champion Archetypes

Rather than memorising specific champions, understanding **archetypes** gives you a framework that outlasts any single patch. Common archetypes include:
- **Engage**: Champions built to start fights on their own terms, forcing an opening.
- **Peel**: Champions built to protect a teammate under threat, buying them time to act safely.
- **Burst**: Champions built to remove a target quickly before it can respond.
- **Sustain**: Champions built to win extended fights through healing or shielding over time.
- **Poke**: Champions built to whittle down health from range before a fight fully starts.
- **Split-push**: Champions built to pressure side lanes and force a response elsewhere on the map.

Every champion leans toward one or more of these roles. Recognising the archetype — not the specific kit — tells you what a champion is trying to accomplish in a given moment.

## Power Spikes

A **power spike** is a moment where a champion's relative strength jumps — typically tied to reaching a key level or completing a key item. Champions with strong early spikes want to force action before falling behind; champions who spike later want to survive to that point safely. Recognising your own comp's spike timing — early, mid, or late — tells you when your team should be looking to create fights versus when it should be playing patiently.

## Damage Type and Engage/Peel Balance

A comp built entirely from one damage type (all physical or all magic) is easier for the enemy to itemise against — a single defensive item choice can blunt most of the team's damage at once. A comp with a mix of damage types forces harder itemisation choices from the enemy. Similarly, a comp with no reliable way to start a fight on its own terms, or no way to protect a threatened teammate, is missing a tool it will eventually need — these gaps are worth noticing during draft, not discovering mid-game.

## Why Identity Beats Individual Strength

A comp with a clear win condition — even built from moderately strong individual picks — consistently outperforms a comp of five strong picks with no shared plan, because every decision in the game (when to fight, when to farm, when to split) becomes obvious once the plan is clear. Draft fundamentals are about building that clarity, not about winning the pick screen in isolation.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 3: Jungle Pathing & Objectives Fundamentals
            // -----------------------------------------------------------------
            [
                'module_name' => 'Jungle Pathing & Objectives Fundamentals',
                'page_number' => 1,
                'title'       => 'Jungle Pathing & Objectives Fundamentals',
                'content'     => <<<'MD'
# Jungle Pathing & Objectives Fundamentals

## What Pathing Actually Decides

Jungle pathing is the order you clear jungle camps in, and it decides two things at once: how much experience and gold you accumulate, and where you are on the map at any given moment — which determines which lanes you can realistically gank and which objectives you can contest.

## Clear Order Logic

A jungle clear should generally move toward the side of the map where your team has priority (a lane that's pushed in, or a lane your teammate can hold safely), because that's where a gank is most likely to succeed and where your presence is safest. Starting on a random side without a plan wastes the informational value of pathing.

## Gank Timing Windows

A gank succeeds when the enemy laner is out of position, low on a key defensive tool, or overextended past the point their own jungler can safely help them. Ganking a lane that is level, healthy, and playing safely usually costs you tempo without a reward. Reading lane state — not just reacting to a fixed timer — is what separates a useful gank from a wasted one.

## Objective Priority

Neutral objectives (Dragon, Herald, Baron, and similar) grant advantages that scale beyond a single fight — but only if your team can actually use them. Taking an objective while giving up significant map pressure elsewhere, or while your team is too weak to use the resulting advantage, can be a net loss even though the objective itself was "free." Objective priority is a comparison, not an automatic take-it-when-available decision.

## Tempo: Invading vs Safe Clearing

**Tempo** in the jungle describes how efficiently your time is being converted into map advantage. Invading the enemy jungle can deny their resources and gather information, but it risks your own clear speed and experience if it goes wrong. A safe, efficient clear guarantees steady resource gain but forfeits the chance to disrupt the enemy's plan. Neither choice is universally correct — it depends on what your team needs at that moment: information and disruption, or a guaranteed, steady lead.

## Reading the Whole Map, Not Just Your Own Clear

A jungler who only tracks their own pathing is only half-informed. Tracking the enemy jungler's likely position — based on which of your lanes is missing a gank, or which of the enemy's lanes is pushed — lets you predict where they are even without direct vision. Combining your own clear plan with a read on the enemy jungler's plan is what turns jungle pathing from a solo farming route into a map-wide strategic tool.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 4: Itemization & Teamfighting Fundamentals
            // -----------------------------------------------------------------
            [
                'module_name' => 'Itemization & Teamfighting Fundamentals',
                'page_number' => 1,
                'title'       => 'Itemization & Teamfighting Fundamentals',
                'content'     => <<<'MD'
# Itemization & Teamfighting Fundamentals

## What Itemization Is Actually Deciding

Itemization is the process of converting gold into the specific tools your champion needs for the situation in front of you — not just building a fixed list in a fixed order. The best itemization plan responds to what the enemy team can do to you and what your team needs from you in a fight.

## Core Items vs Situational Items

A **core build** is the small set of items that make a champion function as intended, regardless of the game state — the tools central to that champion's win condition. **Situational items** are chosen in response to the specific game: extra defense against a threat that's punishing you, or a tool that answers something the enemy comp is doing that your core build doesn't already handle. Treating every item as fixed and non-negotiable ignores half of what itemization is for.

## Damage Type Itemization

Defensive stats are split by damage type. Building resistance against a damage type the enemy comp isn't using wastes the gold; building it against the damage type actually threatening you is what keeps you alive in a fight. Before finalising a defensive item, identify which damage type is actually killing you or your teammates, not just which item is popular.

## Itemizing for Your Role in the Fight

What you buy should reflect what your champion is meant to do inside the fight, not just how to survive alone. A champion meant to deal sustained damage across a fight benefits from survivability that keeps them alive long enough to deal that damage. A champion meant to burst a single target benefits from tools that let them reach and remove that target quickly. Itemizing generically, without reference to your role in the fight, produces a build that doesn't actually support your team's plan.

## Reading a Teamfight Before It Starts

Before a fight begins, identify: who the priority target is on the enemy team (usually their biggest damage threat), who on your team needs to reach or avoid that target, and which of your team's tools are available right now versus on cooldown. A fight that starts with this information already understood is being fought with a plan; a fight that starts without it is being fought reactively.

## Target Priority in the Fight Itself

Once a fight starts, damage should generally go toward whichever enemy is easiest to remove without dying in the attempt, prioritising real threats over convenient ones. Chasing a low-priority target across the map while your team fights a full battle elsewhere usually costs more than it gains. Itemization and target priority work together — the tools you bought should inform who you can safely reach and remove.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 5: Mindset & Improvement Fundamentals
            // -----------------------------------------------------------------
            [
                'module_name' => 'Mindset & Improvement Fundamentals',
                'page_number' => 1,
                'title'       => 'Mindset & Improvement Fundamentals',
                'content'     => <<<'MD'
# Mindset & Improvement Fundamentals

## Why Mindset Is a Skill, Not Just a Personality Trait

How you think during and after a game directly affects how much you actually improve from playing it. Two players can play the same number of games and end up at very different skill levels — the difference is often less about talent and more about whether their mindset lets them extract useful information from each game.

## Process Over Outcome

Focusing purely on whether you won or lost tells you very little about what to change. A player can play well and lose to unlucky circumstances, or play poorly and win because the enemy made bigger mistakes. Judging your performance by the quality of your decisions — not just the result — is what actually identifies what to work on.

## Tilt and Emotional State

**Tilt** describes a state where frustration or emotion is affecting decision-making, usually after a bad moment in a game. Tilted decisions tend to be reactive and poorly reasoned rather than deliberate. Recognising tilt as it happens — and having a plan for it, such as taking a short break or resetting your focus before the next decision — prevents one bad moment from causing several more.

## Reviewing Your Own Games Honestly

Improvement requires looking back at your own decisions specifically, not just recalling how the game felt in the moment. A useful review asks concrete questions: what information did I have at the time of a key decision? What did I actually do with it? What would a better decision have looked like given that same information? Reviewing without this specificity tends to produce vague conclusions that don't change anything.

## Incremental Improvement

Trying to fix every mistake at once is rarely effective. Identifying the single most costly recurring mistake — the one pattern that shows up across multiple games and costs the most value each time — and focusing on correcting that one thing first produces more real improvement than trying to overhaul everything simultaneously.

## Separating Effort From Results in the Short Term

Because so much of an individual game depends on factors outside your control — teammates, matchups, variance — short-term results are a noisy signal of whether you're actually improving. Consistent effort applied to genuine weaknesses pays off over a larger sample of games, even when any single game's result doesn't reflect it. Judging yourself by short-term outcomes alone leads to abandoning good habits before they've had a chance to work.
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

            // =================================================================
            // MODULE 2: Drafting & Champion Fundamentals
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Drafting & Champion Fundamentals',
                'questions'   => [
                    [
                        'question'   => 'What does it mean for a draft to have a clear "win condition"?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'The team has a shared plan for how the game gets won — e.g. scaling to teamfights, splitting the map, or forcing early skirmishes',
                            'options' => [
                                'The team has a shared plan for how the game gets won — e.g. scaling to teamfights, splitting the map, or forcing early skirmishes',
                                'Every player picked their personal favourite champion',
                                'The team picked the five statistically strongest champions individually',
                                'The team has more damage dealers than any other role',
                            ],
                        ],
                        'concepts' => ['Drafting & Strategy'],
                    ],
                    [
                        'question'   => 'What is a champion "archetype"?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A category describing what a champion\'s kit is generally built to do, such as engage, peel, or burst',
                            'options' => [
                                'A category describing what a champion\'s kit is generally built to do, such as engage, peel, or burst',
                                'A champion\'s specific win rate in the current patch',
                                'The lane a champion is typically played in',
                                'A champion\'s base stats at level 1',
                            ],
                        ],
                        'concepts' => ['Champion Knowledge'],
                    ],
                    [
                        'question'   => 'True or False: A "power spike" refers to a moment where a champion\'s relative strength increases, usually tied to a key level or item.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Champion Knowledge'],
                    ],
                    [
                        'question'   => 'Why is a comp built entirely from one damage type (all physical or all magic) generally weaker?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'It\'s easier for the enemy to itemise against with a single type of defensive item',
                            'options' => [
                                'It\'s easier for the enemy to itemise against with a single type of defensive item',
                                'It deals less total damage than a mixed comp',
                                'It has slower mana regeneration',
                                'It cannot use crowd control effectively',
                            ],
                        ],
                        'concepts' => ['Drafting & Strategy'],
                    ],
                    [
                        'question'   => 'True or False: Draft fundamentals are primarily about picking the five individually strongest champions available.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Drafting & Strategy'],
                    ],
                    [
                        'question'   => 'True or False: A "peel" champion is built primarily to protect a threatened teammate rather than to start fights.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Champion Knowledge'],
                    ],
                    [
                        'question'   => 'Match each champion archetype to its primary purpose.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Engage'      => 'Starts fights on the team\'s own terms',
                                'Peel'        => 'Protects a threatened teammate',
                                'Burst'       => 'Removes a target quickly before it can respond',
                                'Split-push'  => 'Pressures a side lane to force a response elsewhere',
                            ],
                            'pairs' => [
                                'keys'   => ['Engage', 'Peel', 'Burst', 'Split-push'],
                                'values' => [
                                    'Protects a threatened teammate',
                                    'Removes a target quickly before it can respond',
                                    'Pressures a side lane to force a response elsewhere',
                                    'Starts fights on the team\'s own terms',
                                ],
                            ],
                        ],
                        'concepts' => ['Champion Knowledge'],
                    ],
                    [
                        'question'   => 'Your comp has strong early power spikes but the enemy comp scales better into the late game. What does this suggest about your team\'s approach?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Your team should look for fights and objectives early, before the enemy\'s late-game strength comes online',
                            'options' => [
                                'Your team should look for fights and objectives early, before the enemy\'s late-game strength comes online',
                                'Your team should farm safely and avoid fights until later',
                                'Power spikes don\'t affect overall strategy, only individual duels',
                                'Your team should always prioritise split-pushing regardless of comp',
                            ],
                        ],
                        'concepts' => ['Drafting & Strategy', 'Champion Knowledge'],
                    ],
                    [
                        'question'   => 'Your comp has no reliable engage and no reliable peel. What gap does this represent, and when does it matter most?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'The comp has no way to start fights on its own terms or protect a threatened teammate — this becomes a problem in forced teamfights',
                            'options' => [
                                'The comp has no way to start fights on its own terms or protect a threatened teammate — this becomes a problem in forced teamfights',
                                'This is not a meaningful gap as long as damage output is high',
                                'The gap only matters during the laning phase',
                                'This means the comp cannot itemise for magic resist or armor',
                            ],
                        ],
                        'concepts' => ['Drafting & Strategy'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order when evaluating a draft\'s identity before the game starts.',
                        'type'       => 'ordering',
                        'difficulty' => 'medium',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Identify each champion\'s primary archetype (engage, peel, burst, sustain, poke, split-push)',
                                'Check whether the comp\'s damage types are mixed or concentrated in one type',
                                'Identify whether the comp has at least one reliable engage or peel tool',
                                'Determine whether the comp\'s power spikes are earlier or later than the opponent\'s',
                                'Use this identity to decide the team\'s overall game plan',
                            ],
                        ],
                        'concepts' => ['Drafting & Strategy', 'Champion Knowledge'],
                    ],
                    [
                        'question'   => 'Your comp has an early power spike, mixed damage types, and a reliable engage tool. The enemy comp scales into a stronger late game. What game plan follows from this draft identity?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Force fights and take objectives while the power spike advantage exists, before the enemy\'s late-game strength takes over',
                            'options' => [
                                'Force fights and take objectives while the power spike advantage exists, before the enemy\'s late-game strength takes over',
                                'Play passively and avoid all fights until items are completed',
                                'Split-push exclusively since the comp has no other option',
                                'Ignore the power spike timing and play reactively to enemy calls',
                            ],
                        ],
                        'concepts' => ['Drafting & Strategy', 'Champion Knowledge'],
                    ],
                    [
                        'question'   => 'During champion select, your team has locked in four picks with no reliable way to protect the team\'s primary damage dealer. What should the final pick prioritise?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'A champion who can peel for the team\'s damage dealer, filling the missing gap in the comp\'s identity',
                            'options' => [
                                'A champion who can peel for the team\'s damage dealer, filling the missing gap in the comp\'s identity',
                                'Whichever champion the player is most comfortable on, regardless of comp needs',
                                'Another burst damage champion to maximise total damage output',
                                'A split-push champion to pressure a different part of the map',
                            ],
                        ],
                        'concepts' => ['Drafting & Strategy', 'Champion Knowledge'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 3: Jungle Pathing & Objectives Fundamentals
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Jungle Pathing & Objectives Fundamentals',
                'questions'   => [
                    [
                        'question'   => 'What does jungle "clear order" primarily decide?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'How much experience/gold you gain and where you are on the map at a given time',
                            'options' => [
                                'How much experience/gold you gain and where you are on the map at a given time',
                                'Which champion is strongest in the jungle role',
                                'The exact respawn timer of every jungle camp',
                                'How much gold minions are worth in lane',
                            ],
                        ],
                        'concepts' => ['Jungle Pathing & Objectives'],
                    ],
                    [
                        'question'   => 'True or False: A gank is more likely to succeed against a lane opponent who is overextended or missing a key defensive tool.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Jungle Pathing & Objectives'],
                    ],
                    [
                        'question'   => 'What best describes "tempo" in the context of jungle pathing?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'How efficiently your time is being converted into map advantage',
                            'options' => [
                                'How efficiently your time is being converted into map advantage',
                                'The total number of camps cleared per game',
                                'How fast your champion moves compared to others',
                                'The amount of gold gained per minute regardless of map state',
                            ],
                        ],
                        'concepts' => ['Jungle Pathing & Objectives'],
                    ],
                    [
                        'question'   => 'True or False: Taking a neutral objective is always correct whenever it is available, regardless of your team\'s ability to use the resulting advantage.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Jungle Pathing & Objectives'],
                    ],
                    [
                        'question'   => 'Why should a jungle clear generally move toward the side of the map where your team has lane priority?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A gank is more likely to succeed there, and your presence on that side of the map is safer',
                            'options' => [
                                'A gank is more likely to succeed there, and your presence on that side of the map is safer',
                                'That side of the map always has more valuable jungle camps',
                                'It reduces the respawn timer of neutral objectives',
                                'It guarantees a numbers advantage in every fight',
                            ],
                        ],
                        'concepts' => ['Jungle Pathing & Objectives', 'Macro Play'],
                    ],
                    [
                        'question'   => 'True or False: Tracking which of your lanes is missing a gank can help you predict the enemy jungler\'s position, even without direct vision.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Jungle Pathing & Objectives', 'Macro Play'],
                    ],
                    [
                        'question'   => 'Match each jungle concept to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Clear Order'        => 'The sequence camps are taken in, shaping gold, experience, and map position',
                                'Gank Timing'        => 'Choosing to gank based on lane state, not a fixed timer',
                                'Objective Priority'  => 'Weighing an objective\'s value against what it costs elsewhere on the map',
                                'Tempo'              => 'How efficiently time is converted into map advantage',
                            ],
                            'pairs' => [
                                'keys'   => ['Clear Order', 'Gank Timing', 'Objective Priority', 'Tempo'],
                                'values' => [
                                    'Choosing to gank based on lane state, not a fixed timer',
                                    'Weighing an objective\'s value against what it costs elsewhere on the map',
                                    'How efficiently time is converted into map advantage',
                                    'The sequence camps are taken in, shaping gold, experience, and map position',
                                ],
                            ],
                        ],
                        'concepts' => ['Jungle Pathing & Objectives'],
                    ],
                    [
                        'question'   => 'A lane opponent is playing safely, is full health, and has all abilities available. What does this suggest about ganking that lane right now?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'The gank is unlikely to succeed and would likely cost tempo without a reward',
                            'options' => [
                                'The gank is unlikely to succeed and would likely cost tempo without a reward',
                                'This is always the best time to gank since the enemy won\'t expect it',
                                'Lane state doesn\'t affect gank success, only the timer does',
                                'A safe opponent means the gank is guaranteed to work',
                            ],
                        ],
                        'concepts' => ['Jungle Pathing & Objectives'],
                    ],
                    [
                        'question'   => 'Your team can take a neutral objective right now, but doing so means giving up significant pressure elsewhere on the map, and your team isn\'t strong enough yet to use the objective\'s advantage. What does this suggest?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'The objective may be a net loss right now — objective priority is a comparison, not an automatic take-it-when-available decision',
                            'options' => [
                                'The objective may be a net loss right now — objective priority is a comparison, not an automatic take-it-when-available decision',
                                'Objectives should always be taken immediately whenever available',
                                'Giving up map pressure never matters as long as an objective is secured',
                                'This situation has no real trade-off since objectives are always beneficial',
                            ],
                        ],
                        'concepts' => ['Jungle Pathing & Objectives', 'Macro Play'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order when planning a jungle path around your team\'s current lane states.',
                        'type'       => 'ordering',
                        'difficulty' => 'medium',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Check which lanes on your team have priority (pushed in or safely holdable)',
                                'Path your jungle clear toward the side of the map with that priority',
                                'Read the specific lane state (health, cooldowns, position) before committing to a gank',
                                'Gank only if the lane state shows a realistic opening',
                                'Use the resulting lead or information to inform the next objective decision',
                            ],
                        ],
                        'concepts' => ['Jungle Pathing & Objectives', 'Macro Play'],
                    ],
                    [
                        'question'   => 'You\'re deciding whether to invade the enemy jungle or take a safe clear. Your team badly needs information on the enemy jungler\'s position, and a lead isn\'t urgent yet. What is the better choice?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Invade — the information and potential disruption is worth more right now than the guaranteed steady resource gain',
                            'options' => [
                                'Invade — the information and potential disruption is worth more right now than the guaranteed steady resource gain',
                                'Always take the safe clear regardless of what the team needs',
                                'Invading is never worth the risk under any circumstance',
                                'The choice makes no difference since both options gain the same amount',
                            ],
                        ],
                        'concepts' => ['Jungle Pathing & Objectives'],
                    ],
                    [
                        'question'   => 'Two of your lanes are missing a gank at the same time, and one enemy lane looks unusually pushed. What should you conclude about the enemy jungler\'s likely position?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'The enemy jungler is likely near the pushed lane, since a missing gank on your side and a pushed enemy lane both point to the same area of the map',
                            'options' => [
                                'The enemy jungler is likely near the pushed lane, since a missing gank on your side and a pushed enemy lane both point to the same area of the map',
                                'The enemy jungler\'s position cannot be estimated without direct vision',
                                'The enemy jungler is guaranteed to be invading your own jungle',
                                'A pushed lane has no relationship to the enemy jungler\'s position',
                            ],
                        ],
                        'concepts' => ['Jungle Pathing & Objectives', 'Macro Play'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 4: Itemization & Teamfighting Fundamentals
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Itemization & Teamfighting Fundamentals',
                'questions'   => [
                    [
                        'question'   => 'What is a "core build" in the context of itemization?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'The small set of items that make a champion function as intended, regardless of the game state',
                            'options' => [
                                'The small set of items that make a champion function as intended, regardless of the game state',
                                'The most expensive items available in the shop',
                                'A fixed item order that should never change between games',
                                'The first item purchased in every game regardless of champion',
                            ],
                        ],
                        'concepts' => ['Itemization'],
                    ],
                    [
                        'question'   => 'True or False: Building resistance against a damage type the enemy team isn\'t actually using wastes gold that could go elsewhere.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Itemization'],
                    ],
                    [
                        'question'   => 'Before finalising a defensive item, what should you identify first?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Which damage type is actually threatening you or your teammates',
                            'options' => [
                                'Which damage type is actually threatening you or your teammates',
                                'Which item is currently the most popular choice',
                                'The exact gold cost of every available defensive item',
                                'Which item has the highest total stat count',
                            ],
                        ],
                        'concepts' => ['Itemization'],
                    ],
                    [
                        'question'   => 'True or False: In a teamfight, damage should generally go toward whichever real threat is easiest to remove without dying in the attempt.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Teamfighting'],
                    ],
                    [
                        'question'   => 'What is the main risk of chasing a low-priority target across the map during a fight?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'It usually costs more than it gains while your team fights a full battle elsewhere',
                            'options' => [
                                'It usually costs more than it gains while your team fights a full battle elsewhere',
                                'There is no real risk as long as the target is eventually killed',
                                'It guarantees your team wins the main fight automatically',
                                'It only matters if the chased target is the enemy\'s healer',
                            ],
                        ],
                        'concepts' => ['Teamfighting'],
                    ],
                    [
                        'question'   => 'True or False: Itemization should only ever be planned once, at the very start of the game, and never adjusted afterward.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Itemization'],
                    ],
                    [
                        'question'   => 'Match each itemization or teamfighting concept to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Core Build'               => 'Items central to a champion\'s win condition regardless of game state',
                                'Situational Item'         => 'An item chosen in direct response to a specific threat or matchup',
                                'Priority Target'          => 'The enemy who is the biggest real threat and realistically reachable',
                                'Damage Type Itemization'  => 'Building defense against whichever damage type is actually threatening you',
                            ],
                            'pairs' => [
                                'keys'   => ['Core Build', 'Situational Item', 'Priority Target', 'Damage Type Itemization'],
                                'values' => [
                                    'An item chosen in direct response to a specific threat or matchup',
                                    'The enemy who is the biggest real threat and realistically reachable',
                                    'Building defense against whichever damage type is actually threatening you',
                                    'Items central to a champion\'s win condition regardless of game state',
                                ],
                            ],
                        ],
                        'concepts' => ['Itemization', 'Teamfighting'],
                    ],
                    [
                        'question'   => 'Your team is taking most of its damage from a specific damage type, but your current build doesn\'t defend against it. What does this suggest about your next item?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'It should be a situational item that specifically answers the damage type actually threatening your team',
                            'options' => [
                                'It should be a situational item that specifically answers the damage type actually threatening your team',
                                'It should always be another core item regardless of what\'s threatening you',
                                'Damage type doesn\'t matter as long as the item has high stats',
                                'You should avoid buying defensive items entirely and focus on damage',
                            ],
                        ],
                        'concepts' => ['Itemization'],
                    ],
                    [
                        'question'   => 'Before a teamfight starts, you identify that the enemy\'s biggest damage threat is also the easiest target for your team to reach. What should this inform?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Your team\'s focus should prioritise that target once the fight begins, since it is both a real threat and realistically reachable',
                            'options' => [
                                'Your team\'s focus should prioritise that target once the fight begins, since it is both a real threat and realistically reachable',
                                'This information isn\'t useful until after the fight has already started',
                                'Your team should ignore that target and focus a random enemy instead',
                                'The easiest target to reach is always the tank, regardless of threat level',
                            ],
                        ],
                        'concepts' => ['Teamfighting'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order when preparing for and executing a teamfight.',
                        'type'       => 'ordering',
                        'difficulty' => 'medium',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Identify the enemy\'s biggest real damage threat before the fight starts',
                                'Confirm which of your team\'s tools are available versus on cooldown',
                                'Determine whether that priority target is realistically reachable by your team',
                                'Commit damage toward that target once the fight begins',
                                'Avoid chasing a lower-priority target away from the main fight',
                            ],
                        ],
                        'concepts' => ['Teamfighting'],
                    ],
                    [
                        'question'   => 'Your build so far is your core items, but the enemy team\'s damage has shifted to a type your build doesn\'t defend against, and a teamfight is likely soon. What is the correct next step?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Buy a situational item that answers the damage type actually threatening you before the fight happens',
                            'options' => [
                                'Buy a situational item that answers the damage type actually threatening you before the fight happens',
                                'Continue buying only core items regardless of the enemy\'s current damage type',
                                'Avoid the upcoming fight entirely rather than adjusting your build',
                                'Buy the most expensive available item regardless of what it defends against',
                            ],
                        ],
                        'concepts' => ['Itemization', 'Teamfighting'],
                    ],
                    [
                        'question'   => 'A teamfight breaks out. The enemy\'s biggest threat is protected behind their team, while a lower-priority target is easy to reach. What is the better decision?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Weigh whether the reachable target is worth taking, but avoid abandoning the main fight entirely to chase a target that isn\'t the real threat',
                            'options' => [
                                'Weigh whether the reachable target is worth taking, but avoid abandoning the main fight entirely to chase a target that isn\'t the real threat',
                                'Always fully commit to the reachable target regardless of its priority',
                                'Ignore the reachable target entirely even if it is a free, safe kill',
                                'Threat level never matters once a fight has already started',
                            ],
                        ],
                        'concepts' => ['Itemization', 'Teamfighting'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 5: Mindset & Improvement Fundamentals
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Mindset & Improvement Fundamentals',
                'questions'   => [
                    [
                        'question'   => 'Why is "process over outcome" an important idea when reviewing your own performance?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Win or loss alone tells you very little about the quality of your decisions in that game',
                            'options' => [
                                'Win or loss alone tells you very little about the quality of your decisions in that game',
                                'Winning always means you played correctly',
                                'Losing always means you played incorrectly',
                                'Outcome and decision quality are always the same thing',
                            ],
                        ],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'What does "tilt" describe?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A state where frustration or emotion is affecting your decision-making, usually after a bad moment in a game',
                            'options' => [
                                'A state where frustration or emotion is affecting your decision-making, usually after a bad moment in a game',
                                'A permanent decline in a player\'s overall skill level',
                                'A specific in-game mechanic related to camera angle',
                                'The natural difficulty increase at higher ranks',
                            ],
                        ],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'True or False: A useful game review asks specifically what information you had at the time of a decision and what a better decision would have looked like.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'True or False: Trying to fix every mistake identified in a review at the same time is generally the most effective way to improve.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'Why are short-term results considered a "noisy signal" of whether you\'re actually improving?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A lot of what happens in an individual game depends on factors outside your control, like teammates and matchups',
                            'options' => [
                                'A lot of what happens in an individual game depends on factors outside your control, like teammates and matchups',
                                'Results are never a meaningful signal at any timescale',
                                'Short-term results are always more accurate than long-term trends',
                                'Improvement can only be measured by win rate over a single game',
                            ],
                        ],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'True or False: Two players can play the same number of games and reach very different skill levels depending on their mindset.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'Match each mindset concept to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Tilt'                    => 'A state where emotion is affecting decision-making after a bad moment',
                                'Process Over Outcome'    => 'Judging performance by decision quality rather than win or loss',
                                'Incremental Improvement' => 'Focusing on the single most costly recurring mistake first',
                                'Honest Review'           => 'Asking what information you had and what a better decision looked like',
                            ],
                            'pairs' => [
                                'keys'   => ['Tilt', 'Process Over Outcome', 'Incremental Improvement', 'Honest Review'],
                                'values' => [
                                    'Judging performance by decision quality rather than win or loss',
                                    'Focusing on the single most costly recurring mistake first',
                                    'Asking what information you had and what a better decision looked like',
                                    'A state where emotion is affecting decision-making after a bad moment',
                                ],
                            ],
                        ],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'You played well but lost due to a teammate\'s mistake late in the game. How should this affect your review of your own performance?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Your own decisions should still be reviewed on their own merits — the loss doesn\'t automatically mean you played poorly',
                            'options' => [
                                'Your own decisions should still be reviewed on their own merits — the loss doesn\'t automatically mean you played poorly',
                                'The loss means every decision you made was incorrect',
                                'There is nothing to review since the outcome was outside your control',
                                'You should only review games that you won',
                            ],
                        ],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'After reviewing several recent games, you notice the same specific mistake costing you value in multiple games. What is the most effective next step?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Focus on correcting that one recurring mistake before trying to address everything else at once',
                            'options' => [
                                'Focus on correcting that one recurring mistake before trying to address everything else at once',
                                'Try to fix every mistake noticed across all the games simultaneously',
                                'Ignore the pattern since each game is a separate, unrelated event',
                                'Stop reviewing games since the same mistake keeps happening',
                            ],
                        ],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order for reviewing your own performance after a game.',
                        'type'       => 'ordering',
                        'difficulty' => 'medium',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Set aside the win/loss result and focus on the quality of key decisions',
                                'Identify a specific decision point and what information was available at the time',
                                'Determine what a better decision would have looked like given that same information',
                                'Check whether this same mistake pattern has appeared in other recent games',
                                'Choose the most costly recurring pattern to focus on improving next',
                            ],
                        ],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'You notice frustration building after a bad death and feel the urge to immediately force a risky play to "get it back." What is the better response?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Recognise this as tilt, take a moment to reset focus, and avoid making the next decision purely from emotion',
                            'options' => [
                                'Recognise this as tilt, take a moment to reset focus, and avoid making the next decision purely from emotion',
                                'Immediately force the risky play — reacting quickly is always correct',
                                'Mute all communication and disengage from the game entirely',
                                'Ignore the feeling since emotion never actually affects decision-making',
                            ],
                        ],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                    [
                        'question'   => 'Over the last several games you\'ve lost more than you\'ve won, but your reviews show your key decisions were consistently sound given the information you had. What should you conclude?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Short-term results are a noisy signal — consistent good decision-making is more meaningful than a small sample of outcomes',
                            'options' => [
                                'Short-term results are a noisy signal — consistent good decision-making is more meaningful than a small sample of outcomes',
                                'You should conclude your decisions are actually flawed since you keep losing',
                                'Results are the only thing that matters, so the decision quality is irrelevant',
                                'You should abandon your current approach immediately based on the losses',
                            ],
                        ],
                        'concepts' => ['Mindset & Improvement'],
                    ],
                ],
            ],
        ];
    }
}
