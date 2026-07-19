<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Concept;
use App\Models\Module;
use App\Models\ModulePage;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class PokerFundamentalsSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'Poker')->first();
        $gamesCategory = Category::where('name', 'Games')->first();

        if (! $subject || ! $gamesCategory) {
            $this->command->error('❌ Poker subject or Games category not found. Run CategorySeeder and SubjectSeeder first.');
            return;
        }

        $axesByName = $gamesCategory->axes()->pluck('id', 'name');

        $this->seedConcepts($subject, $axesByName);
        $this->seedModules($subject);
        $this->seedModulePages();
        $this->seedQuestions($subject);
    }

    private function seedConcepts(Subject $subject, $axesByName): void
    {
        // Keep Poker concepts broad for launch.
        // Detailed topics like blockers, implied odds, and table image remain inside
        // module pages/questions, but questions are tagged to these larger mastery buckets.
        // Bankroll & Variance Management is defined here for future AI-generated content
        // and diagnostic tagging even though no seeded question is tagged to it yet —
        // mirrors how WoW's "Target Switching"/"Awareness & Tracking" concepts work.
        $conceptsWithAxes = [
            'Hand Value & Equity'              => ['Decision', 'Information', 'Resources'],
            'Position & Initiative'            => ['Decision', 'Control', 'Information'],
            'Bet Sizing & Pot Odds'            => ['Resources', 'Decision'],
            'Board Texture & Postflop Reading' => ['Information', 'Decision', 'Adaptation'],
            'Range Construction & Opponent Reading' => ['Information', 'Adaptation', 'Decision'],
            'Bluffing & Balanced Play'         => ['Decision', 'Control', 'Adaptation'],
            'Bankroll & Variance Management'   => ['Resources', 'Adaptation'],
        ];

        $descriptions = [
            'Hand Value & Equity'              => "Understanding starting hand strength, equity, outs, and how a hand's chances of winning change as community cards are revealed.",
            'Position & Initiative'            => 'Using seat position and acting order to gain information advantages, and adjusting strategy based on who acts before or after you.',
            'Bet Sizing & Pot Odds'            => 'Sizing bets and raises for value, protection, or bluffing purposes, and using pot odds and implied odds to make profitable continue-or-fold decisions.',
            'Board Texture & Postflop Reading' => 'Reading how connected or coordinated community cards are, and tracking betting patterns across multiple streets to judge hand strength.',
            'Range Construction & Opponent Reading' => "Thinking in terms of an opponent's full range of possible hands rather than a single guess, and using table dynamics and player tendencies to narrow that range.",
            'Bluffing & Balanced Play'         => 'Constructing a mix of value bets and bluffs that resists exploitation, and choosing bluffs with genuine fold equity, blockers, or backup equity.',
            'Bankroll & Variance Management'   => 'Managing the financial and psychological swings inherent to poker, including bankroll discipline, tilt awareness, and long-run decision-making under variance.',
        ];

        $upserted = 0;

        foreach ($conceptsWithAxes as $name => $axisNames) {
            $concept = Concept::firstOrCreate(
                ['name' => $name, 'subject_id' => $subject->id],
                ['description' => $descriptions[$name] ?? null]
            );

            if (! $concept->description && isset($descriptions[$name])) {
                $concept->update(['description' => $descriptions[$name]]);
            }

            $axisIds = collect($axisNames)
                ->map(fn ($n) => $axesByName[$n] ?? null)
                ->filter()
                ->values()
                ->toArray();

            $concept->axes()->syncWithoutDetaching($axisIds);
            $upserted++;
        }

        $this->command->info("✅ Broad Poker concepts verified/linked. Count: {$upserted}");
    }

    private function seedModules(Subject $subject): void
    {
        $modules = [
            [
                'name'        => 'Poker Fundamentals',
                'description' => 'Understand the core language and concepts of No-Limit Hold\'em — how pots are won, what equity means, why position matters, and how a hand is structured.',
                'proficiency' => 'Beginner',
            ],
            [
                'name'        => 'Preflop Hand Selection',
                'description' => 'Learn how starting hand strength, position, and range thinking combine to decide which hands to open, call, or 3-bet before the flop.',
                'proficiency' => 'Beginner',
            ],
            [
                'name'        => 'Bet Sizing & Pot Odds',
                'description' => 'Understand the resource economy of poker — sizing bets for value, protection, and bluffing, and using pot odds and implied odds to make profitable decisions.',
                'proficiency' => 'Casual',
            ],
            [
                'name'        => 'Postflop Decision-Making',
                'description' => 'Develop postflop judgement — reading board texture, continuation betting, planning across multiple streets, and re-evaluating hand strength as cards are revealed.',
                'proficiency' => 'Casual',
            ],
            [
                'name'        => 'Reading Opponents & Ranges',
                'description' => 'Learn to think in ranges instead of single hands, build a balanced betting strategy, and use table image and player tendencies to make better decisions.',
                'proficiency' => 'Casual',
            ],
        ];

        foreach ($modules as $data) {
            $proficiency = Proficiency::where('name', $data['proficiency'])
                ->where('subject_id', $subject->id)
                ->first();

            if (! $proficiency) {
                $this->command->warn("⚠️ Proficiency '{$data['proficiency']}' not found for Poker — skipping '{$data['name']}'.");
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

        $this->command->info('✅ Poker Fundamentals modules seeded.');
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

        $this->command->info("✅ Poker module pages: {$created} upserted, {$skipped} skipped.");
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
                $question = Question::updateOrCreate(
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

        $this->command->info("✅ Poker questions: {$created} created, {$linked} linked to modules.");
    }

    // =========================================================================
    // PAGE CONTENT
    // =========================================================================

    private function pages(): array
    {
        return [

            // -----------------------------------------------------------------
            // MODULE 1: Poker Fundamentals
            // -----------------------------------------------------------------
            [
                'module_name' => 'Poker Fundamentals',
                'page_number' => 1,
                'title'       => 'Poker Fundamentals — Core Concepts',
                'content'     => <<<'MD'
# Poker Fundamentals

## What Poker Is Trying to Accomplish

No-Limit Texas Hold'em is a game of incomplete information played over many decisions, not a single one. Each player is dealt two private cards (hole cards) and shares five community cards with the table. Unlike games with a single fixed correct line, every decision in poker is made under uncertainty — you never know your opponent's exact hand, only a range of hands they could reasonably hold given how they've played so far.

Understanding poker at a conceptual level — before memorising specific hand charts or advanced plays — is the foundation every later skill builds on.

## The Win Condition

Poker is not won hand by hand. It is won over the long run, across hundreds or thousands of decisions, by making choices that are profitable on average even when any individual hand loses. A player can play a hand perfectly and still lose it — and play a hand poorly and still win it. Judging your play by the outcome of a single hand is one of the most common beginner mistakes.

There are exactly two ways to win a pot:
1. **Showdown**: You hold the best five-card hand when all betting is complete.
2. **Fold equity**: Every other player folds before showdown, regardless of what you actually hold.

This second win condition is why poker is not simply "whoever has the better cards." A player with a weak hand who convinces everyone else to fold wins the pot exactly as completely as a player who wins at showdown with the best hand.

## Equity: Your Hand's Share of the Pot

**Equity** is the probability your hand will win if no more betting occurred and the remaining cards were simply dealt out. A hand with 100% equity always wins; a hand with 0% equity always loses; most hands sit somewhere between.

Equity is not fixed — it changes as community cards are revealed. A hand that is a slight favourite before the flop can become a huge underdog after three community cards fall, and vice versa. **Outs** are the specific cards remaining in the deck that would improve your hand to (likely) the best hand — counting outs is the practical way beginners estimate their equity during a hand.

## Position: Acting With More Information

**Position** describes where you act relative to other players in the betting order for a given hand. Acting **later** in a betting round is an advantage: you get to see what everyone before you does before you have to decide anything yourself. Acting **earlier** is a disadvantage: you must commit to a decision with the least information available.

The **button** (dealer position) is the single best seat at the table — it acts last on every post-flop betting round for the entire hand. Positions further from the button are progressively less favourable, with the seats immediately after the blinds (early position) acting with the least information.

Position affects everything: which hands are profitable to play, how confidently you can bluff, and how much you can extract when you have a strong hand.

## Betting Rounds and Structure

A hand of Hold'em is played across four betting rounds:
1. **Preflop**: Each player has two hole cards only. Betting starts after the blinds (forced bets) are posted.
2. **Flop**: Three community cards are revealed.
3. **Turn**: A fourth community card is revealed.
4. **River**: The fifth and final community card is revealed.

A betting round ends when every player remaining in the hand has either matched the current bet or folded. If more than one player remains after the river betting round, the hand goes to **showdown**.

## Pot Odds: A First Look

**Pot odds** compare the cost of a contested decision — usually calling a bet — to the size of the pot you'd be winning. If a call costs you a small fraction of the pot, you need much less equity to make that call profitable than if the call costs you a large fraction of the pot. This single idea — comparing what you risk to what you could win, relative to your chance of winning it — underlies almost every continuing-or-folding decision in the game. Module 3 covers pot odds calculations in depth.

## How Hands Are Won

Most winning outcomes fall into a few recognisable patterns:
1. **Value**: You have a strong hand and bet to get worse hands to pay you off.
2. **Bluff**: You have a weak hand but bet in a way that convinces stronger hands to fold.
3. **Semi-bluff**: You have a weak hand right now but strong potential to improve, so you bet for two reasons at once — folding out better hands now, or improving into the best hand later.
4. **Showdown value capture**: Your hand is mediocre but likely better than what you'll be shown at showdown, so you check or call rather than bet, letting the hand reach showdown cheaply.

Every decision you make in a hand should be traceable back to one of these patterns. If you can't explain which one you're pursuing, that's usually a sign to slow down and think the decision through.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 2: Preflop Hand Selection
            // -----------------------------------------------------------------
            [
                'module_name' => 'Preflop Hand Selection',
                'page_number' => 1,
                'title'       => 'Preflop Hand Selection',
                'content'     => <<<'MD'
# Preflop Hand Selection

## Why Preflop Decisions Matter Most

Every hand of poker begins preflop, and the decision to play or fold a starting hand shapes everything that follows. A player who enters pots with a wide, undisciplined range of hands will constantly find themselves out of position, unsure of where they stand, and bleeding chips in small but frequent ways. Preflop discipline is the single highest-leverage skill a beginner can build — it prevents the vast majority of difficult, low-equity situations before they ever happen.

## Starting Hand Strength

Not all two-card hands are created equal. Premium hands (like a pair of Aces or Kings, or Ace-King) are strong regardless of position. Marginal hands depend heavily on context — the same hand can be a clear raise from the button and a clear fold from early position. Beginners often overvalue hands that "look" strong (any two big cards, any pair) without accounting for how those hands perform against a full range of opponents' likely holdings.

Two properties define a strong starting hand:
- **High card value**: Cards that make strong pairs or high-card hands.
- **Connectivity and suitedness**: Cards that are close in rank (connected) or share a suit — these make it easier to complete straights and flushes, which matters most when a hand is played all the way to showdown.

## Range: Thinking in Groups of Hands, Not One Hand

A **range** is the full set of hands a player would plausibly hold in a given situation, based on their actions so far. Strong preflop play means thinking in ranges from the very first decision: instead of asking "should I play this exact hand," disciplined players ask "what range of hands should I be playing from this position, against this many opponents, given the action in front of me."

Playing a well-constructed range preflop makes every later decision easier, because your own hand strength stays coherent with how you've represented yourself.

## Position-Based Opening Ranges

Because position determines how much information you'll have on every future betting round, it should also determine how wide a range of hands you open with:
- **Early position**: The tightest range. More players remain to act behind you, so any hand you open with should hold up against a wider field.
- **Middle position**: A moderately wider range — fewer players left to act, slightly more freedom.
- **Late position / button**: The widest range. Fewer players behind you, and if you do get called, you'll have position for the rest of the hand.

This is why the exact same hand can be a raise from the button and a fold from early position — it isn't inconsistency, it's the range correctly adjusting to how much risk the position carries.

## Opening, Calling, and 3-Betting

Three basic preflop actions define almost every hand's opening decision:
1. **Opening (raising first)**: Entering the pot with a raise when no one else has entered yet. This is almost always preferable to just calling ("limping") with a playable hand, since raising builds the pot when you're likely ahead and applies pressure immediately.
2. **Calling an open**: Entering a pot after someone else has already raised, without re-raising. Usually reserved for hands that play well multi-way or that flop well but aren't strong enough to re-raise profitably.
3. **3-betting (re-raising)**: Raising again after someone else has already opened. A 3-bet range is typically narrower and more polarised than an opening range — built from very strong hands (for value) and a small number of weaker hands played aggressively (as a bluff).

## Why Loose Preflop Play Is Costly

Playing too many starting hands doesn't just lose value on the hands themselves — it creates compounding problems for every decision afterward. A player with a wide, undisciplined range will frequently:
- End up out of position with a hand too weak to comfortably continue.
- Face difficult decisions on later streets with unclear equity.
- Leak chips gradually across many small, marginal decisions rather than through any single obvious mistake.

Tightening preflop requirements — playing fewer, stronger, better-positioned hands — is consistently one of the fastest ways a beginner can improve their overall results.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 3: Bet Sizing & Pot Odds
            // -----------------------------------------------------------------
            [
                'module_name' => 'Bet Sizing & Pot Odds',
                'page_number' => 1,
                'title'       => 'Bet Sizing & Pot Odds — Resource Economy in Poker',
                'content'     => <<<'MD'
# Bet Sizing & Pot Odds — Resource Economy in Poker

## Bets as Questions, Not Just Actions

Every bet in poker asks the other player a question: "Do you have enough of a hand, and the right price, to continue?" How you size that bet — how big or small relative to the pot — changes both the question being asked and how expensive a wrong answer is. Beginners often think of bet sizing as a single "correct" amount; in reality, sizing is a tool that should shift based on what you're trying to accomplish with a particular bet.

## Value Bets vs Bluff Bets

A **value bet** is made with a hand you believe is currently best, sized to get worse hands to call. A **bluff bet** is made with a hand you don't believe is best, sized to make better hands fold. In principle, sizing for both should look similar — if bluffs are always small and value bets are always big, observant opponents can simply fold to big bets and call small ones, making both plays far less effective. Balancing bet sizes across both value and bluffs is what keeps a betting range difficult to play against.

## Sizing for Value

A value bet should be sized based on what a plausible worse hand is willing to call. Betting too small leaves value on the table — a hand that would have called a bigger bet still only pays the smaller amount. Betting too large risks folding out the exact hands you wanted to get value from, leaving you only called by hands that beat you.

## Sizing for Protection and Denial

Some bets exist to make it too expensive for hands with drawing potential (hands not yet complete but with outs to improve) to continue profitably. This is called **denying equity** — even if a bet doesn't fold out every worse hand, it can make continuing a mathematically losing decision for hands that need to improve to win.

## Pot Odds: The Core Calculation

**Pot odds** compare the cost of calling to the total size of the pot after the call. If a pot contains 100 chips and your opponent bets 50, you must call 50 to potentially win a pot of 200 (the original 100, their 50 bet, plus your 50 call) — a cost of 50 to win 150 additional chips, or roughly 1-in-4 odds. If your hand's equity to win is better than that ratio, calling is profitable over time, even though you'll still lose that specific hand more often than not.

The core rule: **compare your equity to the price you're being offered.** You do not need to be ahead right now to make a profitable call — you need enough equity relative to the odds the pot is giving you.

## Implied Odds

**Implied odds** extend this idea forward: they account for the additional chips you expect to win on later streets if you complete your hand, not just the chips already in the pot right now. A drawing hand that doesn't quite have the direct pot odds to continue can still be a profitable call if completing the draw is likely to win a much bigger pot later — for example, against an opponent who tends to pay off big hands. Implied odds are an estimate, not an exact calculation, and beginners should be cautious about overestimating how much more they'll actually win.

## Why Sizing Discipline Matters

A player whose bet sizes don't correlate with hand strength — betting big with everything, or always betting the same "standard" amount regardless of the situation — becomes both easy to read and easy to exploit. Thoughtful sizing does two things at once: it maximises value from hands that are ahead, and it minimises losses (or maximises fold equity) from hands that are behind. Every bet should have a clear purpose behind its size, not just its direction.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 4: Postflop Decision-Making
            // -----------------------------------------------------------------
            [
                'module_name' => 'Postflop Decision-Making',
                'page_number' => 1,
                'title'       => 'Postflop Decision-Making',
                'content'     => <<<'MD'
# Postflop Decision-Making

## Why Postflop Is Where Skill Compounds

Preflop decisions narrow the range of hands you're playing; postflop decisions determine how well you play that range across three more betting rounds and an enormous number of possible board runouts. Postflop is where the largest skill gaps in poker actually show up — two players with identical preflop ranges can produce very different long-term results based purely on how they navigate the flop, turn, and river.

## Board Texture

**Board texture** describes how connected, coordinated, and draw-heavy the community cards are. A **dry board** (for example, cards of different suits and far apart in rank) offers few ways for hands to improve, favouring whoever already has the best hand. A **wet board** (for example, connected or same-suited cards) offers many possible straights and flushes, meaning hand values are far less settled and will keep shifting as more cards are revealed.

Reading board texture correctly changes almost every decision that follows: how much to bet, how often to bluff, and how much a made hand is actually worth.

## Continuation Betting

A **continuation bet** (c-bet) is a bet made by the preflop aggressor on the flop, continuing the pressure they applied before the flop was dealt. C-betting works because the preflop raiser's range is, on average, stronger than a caller's range, and many boards will not have improved either range much. A good c-bet strategy considers the board texture: dry boards favour the preflop raiser more heavily and support a wider c-betting range, while wet boards favour whoever's range connects better with that specific texture, which is not always the preflop raiser.

## Reading the Hand Through Multiple Streets

A single street rarely tells the whole story. Strong postflop play means tracking how a hand's story develops across the flop, turn, and river together — does the betting pattern make sense for a strong hand, or does it look inconsistent with one? Beginners often evaluate each street in isolation; stronger players track the entire sequence of actions as one continuous story that either holds together or starts to look suspicious.

## Multi-Street Planning

Rather than deciding one bet at a time with no plan, strong players think ahead: if I bet the flop and get called, what do I do on the turn if a scare card falls? What do I do if it doesn't? Planning across multiple streets — sometimes called playing "with a plan" rather than "street by street" — prevents inconsistent lines that give away information and helps ensure a bluff that starts on the flop can be followed through credibly, or abandoned early if it clearly isn't working.

## Checking and Its Purposes

Not betting is itself a decision with purpose, not simply the absence of one. Checking can:
- **Control the size of the pot** with a hand that has some value but isn't strong enough to build a big pot.
- **Induce a bluff** from an opponent who might bet if given the chance.
- **Trap** with a very strong hand, letting an opponent bet into you rather than folding to your own bet.

A check should be chosen deliberately for one of these reasons, not simply because a player is unsure what else to do.

## Adjusting Across Streets

As each new card is revealed, ranges narrow and hand values shift. A hand that was ahead on the flop can become behind by the river if a card completes a likely draw; a hand that looked weak on the flop can improve into the best hand by the turn. Postflop skill means re-evaluating your hand's actual standing after every new card, rather than anchoring to how strong it felt on an earlier street.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 5: Reading Opponents & Ranges
            // -----------------------------------------------------------------
            [
                'module_name' => 'Reading Opponents & Ranges',
                'page_number' => 1,
                'title'       => 'Reading Opponents & Ranges',
                'content'     => <<<'MD'
# Reading Opponents & Ranges

## Thinking in Ranges, Not Hands

The single biggest jump in postflop skill comes from replacing the question "what hand do they have?" with "what range of hands could they have, and how does this specific board interact with that range?" No player can read a single exact hand reliably, but every player can narrow a plausible range based on position, preflop action, and betting patterns across each street — and that range, not a guessed exact hand, is what should drive every decision.

## Building a Range From Actions

A range narrows with every decision an opponent makes. Their preflop action (raising, calling, 3-betting) already excludes many hands. Each subsequent bet, check, call, raise, or fold excludes more. By the river, a well-read opponent's range can sometimes be narrowed to a small handful of plausible holdings — but only if each street's action was tracked and interpreted along the way, not reconstructed from scratch at the end.

## Table Dynamics and Player Tendencies

Beyond any single hand, players develop observable tendencies over time: how often they bet, how often they bluff, how they react to aggression, how their bet sizing changes with hand strength. These tendencies form a player's overall image and inform how much weight to put on any specific action they take. A tight, cautious player betting big on the river is a very different signal than a loose, aggressive player doing the same thing — the same action can mean very different things depending on who's taking it.

## Balancing Your Own Range

Reading opponents is only half the skill — the other half is preventing opponents from reading you. A **balanced range** contains a mix of value hands and bluffs at a given bet size and situation, so that an opponent cannot simply call or fold correctly based on the bet alone. If every bluff you make looks identical to every value bet you make, an observant opponent cannot exploit your patterns — but if your bluffs and value bets look or feel different, they eventually will.

## Bluffing With Purpose

Not every bluff is equally justified. A well-chosen bluff typically has some combination of:
- **Fold equity**: A real chance the opponent's range folds to the bet.
- **Blockers**: Holding cards that make it less likely the opponent has the hands that would call.
- **Backup equity**: Some chance to improve to a genuinely strong hand even if called.

Bluffing without any of these factors present is closer to gambling than to a calculated decision — it may occasionally work, but it isn't repeatable, profitable strategy.

## Adjusting to Exploitable Tendencies

While balance matters against strong, observant opponents, many opponents are not balanced themselves — they fold too much, call too much, bluff too rarely, or bluff too often in predictable spots. Recognising these tendencies and deviating from a purely balanced approach to directly exploit them is often more profitable than playing a theoretically balanced strategy against an opponent who isn't paying attention to your patterns in the first place. The skill is recognising which situation you're actually in.

## Table Image and How It's Used

Your own recent actions shape how opponents perceive you — your **table image**. A player who has been caught bluffing recently will often get called more on future bluffs; a player who has shown only strong hands may get more credit (and more folds) on a bluff. Strong players actively track their own image and use it: leaning into a tight image to make a well-timed bluff, or leaning into a loose image to extract extra value from a strong hand that would otherwise get respect and folds.
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
            // MODULE 1: Poker Fundamentals
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Poker Fundamentals',
                'questions'   => [
                    [
                        'question'   => 'What are the two ways to win a pot in poker?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Having the best hand at showdown, or getting all other players to fold',
                            'options' => [
                                'Having the best hand at showdown, or getting all other players to fold',
                                'Having the most chips at the start of the hand',
                                'Being the first player to act each round',
                                'Making the largest single bet during the hand',
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'What does "equity" mean in poker?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'The probability your hand will win if the remaining cards were simply dealt out with no more betting',
                            'options' => [
                                'The probability your hand will win if the remaining cards were simply dealt out with no more betting',
                                'The total amount of chips you have committed to the pot',
                                'The number of players still active in the hand',
                                "The rank of your hand compared to a fixed hand chart",
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'True or False: Acting later in a betting round is an advantage because you get to see what other players do before deciding.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Position & Initiative'],
                    ],
                    [
                        'question'   => 'Which position acts last on every post-flop betting round?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'The button (dealer position)',
                            'options' => [
                                'The button (dealer position)',
                                'The small blind',
                                'The big blind',
                                'Under the gun (first to act preflop)',
                            ],
                        ],
                        'concepts' => ['Position & Initiative'],
                    ],
                    [
                        'question'   => 'True or False: A player can win a hand of poker without having the best cards, simply by getting every other player to fold.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'True or False: The outcome of a single hand is a reliable way to judge whether a decision was correct.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'Match each poker term to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Equity'      => 'The probability a hand wins if the remaining cards were simply dealt out',
                                'Outs'        => 'The remaining cards in the deck that would improve a hand to likely the best hand',
                                'Position'    => 'Where a player acts relative to others in the betting order',
                                'Fold Equity' => 'The chance of winning a pot by making every other player fold',
                            ],
                            'pairs' => [
                                'keys'   => ['Equity', 'Outs', 'Position', 'Fold Equity'],
                                'values' => [
                                    'The remaining cards in the deck that would improve a hand to likely the best hand',
                                    'Where a player acts relative to others in the betting order',
                                    'The chance of winning a pot by making every other player fold',
                                    'The probability a hand wins if the remaining cards were simply dealt out',
                                ],
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => "You're dealt a strong hand in early position at a full table. Why does your position make this hand harder to play well than if you held it on the button?",
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'You must act before seeing how the rest of the table responds, with the least information available',
                            'options' => [
                                'You must act before seeing how the rest of the table responds, with the least information available',
                                'Early position hands are always weaker than late position hands',
                                'The pot is smaller when acting in early position',
                                'Early position players are not allowed to raise preflop',
                            ],
                        ],
                        'concepts' => ['Position & Initiative'],
                    ],
                    [
                        'question'   => 'Why is judging a decision by the result of one hand considered a mistake?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'A correct decision can still lose, and an incorrect decision can still win, due to the role of chance in any single hand',
                            'options' => [
                                'A correct decision can still lose, and an incorrect decision can still win, due to the role of chance in any single hand',
                                'Because the rules of poker change between hands',
                                'Because equity is only relevant at showdown',
                                'Because only professional players are allowed to review their hands',
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'Place the following stages of a hand of Hold\'em in the correct order.',
                        'type'       => 'ordering',
                        'difficulty' => 'medium',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Blinds are posted and each player receives two hole cards (preflop)',
                                'Three community cards are revealed (the flop)',
                                'A fourth community card is revealed (the turn)',
                                'A fifth and final community card is revealed (the river)',
                                'Remaining players reveal their hands at showdown, if more than one player remains',
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'You hold a hand with strong potential to improve but is currently behind. You bet, hoping to either fold out better hands now or improve into the best hand later if called. What is this play called?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'A semi-bluff',
                            'options' => [
                                'A semi-bluff',
                                'A value bet',
                                'A showdown value capture',
                                'A pure bluff',
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => "You are in early position with a hand you'd happily play from the button, but you're unsure how many players behind you will enter the pot and from where. What does correct position-aware thinking suggest?",
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Play more cautiously than you would in late position, since more players could act behind you with more information than you have',
                            'options' => [
                                'Play more cautiously than you would in late position, since more players could act behind you with more information than you have',
                                'Position has no effect once the hand has been dealt — play the same way regardless of seat',
                                'Always raise the maximum amount regardless of position to compensate',
                                'Always fold in early position regardless of hand strength',
                            ],
                        ],
                        'concepts' => ['Position & Initiative'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 2: Preflop Hand Selection
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Preflop Hand Selection',
                'questions'   => [
                    [
                        'question'   => 'What is a "range" in poker?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'The full set of hands a player could plausibly hold in a given situation based on their actions',
                            'options' => [
                                'The full set of hands a player could plausibly hold in a given situation based on their actions',
                                "The total number of chips a player has remaining",
                                "The distance between the highest and lowest card in a player's hand",
                                'The number of betting rounds remaining in the hand',
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'True or False: The same starting hand can be correct to raise from one position and correct to fold from another.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Position & Initiative'],
                    ],
                    [
                        'question'   => 'What is "3-betting"?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Re-raising after another player has already opened the pot with a raise',
                            'options' => [
                                'Re-raising after another player has already opened the pot with a raise',
                                'Betting three times the size of the pot',
                                'Calling a raise for the third time in a hand',
                                'Opening the pot with the third-best possible hand',
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'True or False: Early position should generally play a wider range of hands than late position, since fewer players remain to act.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Position & Initiative'],
                    ],
                    [
                        'question'   => 'Why do connected or suited cards (like 9-10 suited) have extra value beyond their high-card strength?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'They make it easier to complete straights and flushes',
                            'options' => [
                                'They make it easier to complete straights and flushes',
                                'They automatically win at showdown against any unsuited hand',
                                'They allow a player to see the flop for free',
                                'They increase the size of the blinds for that hand',
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'True or False: "Limping" (calling instead of raising) with a playable hand is generally considered stronger than opening with a raise.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'Match each preflop action to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Opening'         => 'Entering the pot with the first raise when no one else has entered',
                                'Calling an Open' => 'Entering the pot after someone else has raised, without re-raising',
                                '3-Betting'       => 'Re-raising after another player has already opened',
                                'Range'           => 'The full set of hands a player could plausibly hold given their actions',
                            ],
                            'pairs' => [
                                'keys'   => ['Opening', 'Calling an Open', '3-Betting', 'Range'],
                                'values' => [
                                    'Entering the pot after someone else has raised, without re-raising',
                                    'Re-raising after another player has already opened',
                                    'The full set of hands a player could plausibly hold given their actions',
                                    'Entering the pot with the first raise when no one else has entered',
                                ],
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'Why does a well-constructed 3-bet range typically include some weaker hands alongside premium hands, rather than only premium hands?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => "So opponents can't simply fold every time you 3-bet, since your range includes both genuine value hands and bluffs",
                            'options' => [
                                "So opponents can't simply fold every time you 3-bet, since your range includes both genuine value hands and bluffs",
                                'Because weaker hands are statistically stronger than premium hands after a 3-bet',
                                'Because the rules require a minimum number of hands in every range',
                                '3-betting with only strong hands is against standard poker etiquette',
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'A player in early position opens with a hand that would clearly be correct from the button. What is the likely consequence of applying a late-position range too early?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'More players remain to act behind them, increasing the chance of running into a stronger hand or facing a difficult multi-way pot',
                            'options' => [
                                'More players remain to act behind them, increasing the chance of running into a stronger hand or facing a difficult multi-way pot',
                                'There is no consequence — position only matters after the flop',
                                'The hand automatically becomes stronger because it was opened first',
                                'The blinds are forced to fold immediately',
                            ],
                        ],
                        'concepts' => ['Position & Initiative'],
                    ],
                    [
                        'question'   => 'Why is thinking in terms of a "range" considered stronger preflop discipline than evaluating each hand individually?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'It keeps decisions consistent with how a player has represented themselves, making later streets easier to play correctly',
                            'options' => [
                                'It keeps decisions consistent with how a player has represented themselves, making later streets easier to play correctly',
                                'It guarantees the player will win more hands at showdown',
                                'It removes the need to ever fold preflop',
                                "It allows a player to see their opponents' hole cards",
                            ],
                        ],
                        'concepts' => ['Hand Value & Equity'],
                    ],
                    [
                        'question'   => 'Place the following in the correct order of decision-making when constructing a sound preflop strategy.',
                        'type'       => 'ordering',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Identify your position and how many players could still act behind you',
                                'Determine the appropriate opening range width for that position',
                                'Decide whether your specific hand falls inside that range',
                                'Choose whether to open, call, or fold based on the action already in front of you',
                                'Adjust your decision if a 3-bet range needs to be considered against aggressive opponents',
                            ],
                        ],
                        'concepts' => ['Position & Initiative'],
                    ],
                    [
                        'question'   => "You're in middle position and would normally fold a hand like King-Jack offsuit, but everyone ahead of you has already folded and only the blinds remain to act. What does sound range thinking suggest?",
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'The effective range can widen slightly, since fewer players remain to act behind you than the position would normally imply',
                            'options' => [
                                'The effective range can widen slightly, since fewer players remain to act behind you than the position would normally imply',
                                'The hand should always be folded regardless of how many players have already acted',
                                'The hand should be played as if from early position against a full table',
                                'The number of players who have folded has no effect on how wide a range should be',
                            ],
                        ],
                        'concepts' => ['Position & Initiative'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 3: Bet Sizing & Pot Odds
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Bet Sizing & Pot Odds',
                'questions'   => [
                    [
                        'question'   => 'What is a "value bet"?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A bet made with a hand believed to be currently best, sized to get worse hands to call',
                            'options' => [
                                'A bet made with a hand believed to be currently best, sized to get worse hands to call',
                                'A bet made purely to bluff an opponent out of the pot',
                                'The minimum legal bet allowed in a betting round',
                                'A bet made only when a player is certain of winning',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'True or False: If bluffs are always sized small and value bets are always sized big, an observant opponent can exploit this by folding to big bets and calling small ones.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'What do "pot odds" compare?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'The cost of calling a bet against the total size of the pot you could win',
                            'options' => [
                                'The cost of calling a bet against the total size of the pot you could win',
                                'The number of players remaining in the hand against the number who have folded',
                                "The size of your stack against your opponent's stack",
                                'The number of outs you have against the number of community cards remaining',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'True or False: To make a profitable call, your equity to win the hand must always be higher than 50%.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'What are "implied odds"?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => "An estimate of the additional chips you expect to win on later streets if your hand completes, beyond what's already in the pot",
                            'options' => [
                                "An estimate of the additional chips you expect to win on later streets if your hand completes, beyond what's already in the pot",
                                'The exact odds printed on a hand-strength chart',
                                'The odds of being dealt a specific starting hand',
                                'The odds that an opponent is bluffing based on their bet size',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'True or False: Betting a size that makes it mathematically unprofitable for a drawing hand to continue is called "denying equity."',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'Match each term to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Value Bet'    => 'Sized to get a worse hand to call',
                                'Bluff Bet'    => 'Sized to make a better hand fold',
                                'Pot Odds'     => 'The cost of calling compared to the size of the pot',
                                'Implied Odds' => 'Expected future winnings if a drawing hand completes',
                            ],
                            'pairs' => [
                                'keys'   => ['Value Bet', 'Bluff Bet', 'Pot Odds', 'Implied Odds'],
                                'values' => [
                                    'Sized to make a better hand fold',
                                    'The cost of calling compared to the size of the pot',
                                    'Expected future winnings if a drawing hand completes',
                                    'Sized to get a worse hand to call',
                                ],
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'A player bets very small with strong hands and very large with bluffs. What is the main problem with this pattern?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Observant opponents can call the small bets and fold to the large ones, minimising losses to value and avoiding paying off bluffs',
                            'options' => [
                                'Observant opponents can call the small bets and fold to the large ones, minimising losses to value and avoiding paying off bluffs',
                                'Small bets are illegal in most poker formats',
                                'It guarantees the player will win the pot regardless of their hand',
                                'Bet sizing has no effect on opponent decisions',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'The pot is 100 chips and your opponent bets 100, making the pot 300 if you call. Your hand needs to complete a draw to win, and you calculate roughly 25% equity to do so. Is calling profitable based on pot odds alone?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => "Yes — you're being asked to pay 100 to win a total pot of 300 after calling, which requires only 25% equity to break even, matching your estimated equity",
                            'options' => [
                                "Yes — you're being asked to pay 100 to win a total pot of 300 after calling, which requires only 25% equity to break even, matching your estimated equity",
                                'No — you need at least 50% equity to ever make a profitable call',
                                'No — pot odds only apply once you\'re already ahead in the hand',
                                'Yes, but only if the opponent is known to be bluffing',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'Why should implied odds be estimated cautiously rather than relied on heavily?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'They depend on assumptions about future betting and opponent behaviour that may not hold, unlike pot odds which are based on chips already committed',
                            'options' => [
                                'They depend on assumptions about future betting and opponent behaviour that may not hold, unlike pot odds which are based on chips already committed',
                                'Implied odds are always smaller than pot odds and therefore irrelevant',
                                'Implied odds can only be used on the river',
                                'Implied odds apply only to premium starting hands',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order for deciding whether to call a bet using pot odds.',
                        'type'       => 'ordering',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Determine the total size of the pot after the bet and your potential call',
                                'Calculate the ratio of the call amount to the resulting pot size',
                                "Estimate your hand's equity to win at showdown",
                                'Compare your equity estimate to the pot odds ratio',
                                'Call if your equity meets or exceeds what the pot odds require, fold if it does not',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => "You have a strong made hand on a board where a draw could easily be completed by your opponent's likely range. What sizing best balances value extraction with protection against that draw?",
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'A larger bet that still gets called by worse made hands, while making it too expensive for the draw to continue profitably',
                            'options' => [
                                'A larger bet that still gets called by worse made hands, while making it too expensive for the draw to continue profitably',
                                'The smallest possible bet, to guarantee a call regardless of hand strength',
                                'Checking, to avoid giving any information about your hand',
                                'An all-in bet regardless of stack size, since maximum pressure is always correct',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 4: Postflop Decision-Making
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Postflop Decision-Making',
                'questions'   => [
                    [
                        'question'   => 'What is a "dry" board?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A board with few ways for hands to improve, such as cards of different suits and far apart in rank',
                            'options' => [
                                'A board with few ways for hands to improve, such as cards of different suits and far apart in rank',
                                'A board where every player has folded',
                                'A board with three or more cards of the same suit',
                                "A board where the pot has grown larger than both players' stacks",
                            ],
                        ],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => 'What is a "continuation bet" (c-bet)?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A bet made by the preflop aggressor on the flop, continuing the pressure applied before the flop',
                            'options' => [
                                'A bet made by the preflop aggressor on the flop, continuing the pressure applied before the flop',
                                'Any bet made on the turn or river',
                                'A bet that must be the same size as the previous betting round',
                                'A bet made specifically to induce a fold from a stronger hand',
                            ],
                        ],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => 'True or False: A "wet" board has many possible straights and flushes, meaning hand values are less settled.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => 'True or False: Checking is only ever a sign of a weak hand with no better options.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => 'Why does c-betting tend to work well on dry boards?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => "The preflop raiser's range is, on average, stronger, and a dry board is unlikely to have improved the caller's range much",
                            'options' => [
                                "The preflop raiser's range is, on average, stronger, and a dry board is unlikely to have improved the caller's range much",
                                'Dry boards are required by the rules to be checked by the caller',
                                'C-bets are always larger in size on dry boards',
                                'Wet boards do not allow continuation bets at all',
                            ],
                        ],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => "True or False: A hand's standing should be re-evaluated after every new community card is revealed, rather than anchored to how strong it felt earlier.",
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => 'Match each postflop term to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Dry Board'          => 'Few ways for hands to improve; favours whoever already has the best hand',
                                'Wet Board'           => 'Many possible straights and flushes; hand values remain unsettled',
                                'Continuation Bet'    => 'A bet by the preflop aggressor continuing pressure onto the flop',
                                'Trap'                => 'Checking a strong hand to let an opponent bet into it',
                            ],
                            'pairs' => [
                                'keys'   => ['Dry Board', 'Wet Board', 'Continuation Bet', 'Trap'],
                                'values' => [
                                    'Many possible straights and flushes; hand values remain unsettled',
                                    'A bet by the preflop aggressor continuing pressure onto the flop',
                                    'Checking a strong hand to let an opponent bet into it',
                                    'Few ways for hands to improve; favours whoever already has the best hand',
                                ],
                            ],
                        ],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => "Why might a preflop caller's range connect better with a wet board than the preflop raiser's range?",
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => "Calling ranges can include more connected, drawing-heavy hands that improve well on coordinated boards, unlike a raiser's range which may lean on high cards that don't need coordination",
                            'options' => [
                                "Calling ranges can include more connected, drawing-heavy hands that improve well on coordinated boards, unlike a raiser's range which may lean on high cards that don't need coordination",
                                'Callers always hold stronger hands than raisers by definition',
                                'Wet boards always favour whoever acts first',
                                'Preflop raisers are not allowed to continue betting on wet boards',
                            ],
                        ],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => 'You bet the flop, get called, and a scare card falls on the turn that could have completed several draws. Why is it important to have planned your turn action before betting the flop?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => "Without a plan, you're more likely to make an inconsistent decision that gives away information, rather than following through on or abandoning the line credibly",
                            'options' => [
                                "Without a plan, you're more likely to make an inconsistent decision that gives away information, rather than following through on or abandoning the line credibly",
                                'Turn decisions are always automatic once the flop bet is made',
                                'Planning ahead guarantees the opponent will fold on the turn',
                                'The turn card has no effect on a hand that was strong on the flop',
                            ],
                        ],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => 'What is the main purpose of checking to "induce a bluff"?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => "To give an opponent the opportunity to bet a hand they wouldn't have if you had bet first, so you can call or raise profitably",
                            'options' => [
                                "To give an opponent the opportunity to bet a hand they wouldn't have if you had bet first, so you can call or raise profitably",
                                'To guarantee the pot stays small for the rest of the hand',
                                'To avoid ever having to make a decision on a later street',
                                'To signal to the opponent that your hand is weak',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order for reading a hand through multiple streets.',
                        'type'       => 'ordering',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Note the preflop action and what range it likely represents',
                                "Observe the flop action and whether it's consistent with that range",
                                'Track how the turn action either supports or contradicts the story being told so far',
                                'Evaluate whether the river action completes a coherent, believable hand',
                                'Decide whether the overall sequence looks like a strong hand or an inconsistent bluff attempt',
                            ],
                        ],
                        'concepts' => ['Board Texture & Postflop Reading'],
                    ],
                    [
                        'question'   => 'You hold a strong made hand on a wet board likely to complete draws by the river. Your opponent has been calling each street without raising. What does sound postflop thinking suggest about your river sizing?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Size the river bet based on what a range of hands your opponent could still hold — including completed draws — would be willing to call, rather than simply betting the same amount as previous streets',
                            'options' => [
                                'Size the river bet based on what a range of hands your opponent could still hold — including completed draws — would be willing to call, rather than simply betting the same amount as previous streets',
                                'Always check the river with a strong hand to avoid unnecessary risk',
                                'Bet the exact same size on every street regardless of how the board or action develops',
                                'Since the opponent has only called, assume they cannot have a strong hand and bet the minimum',
                            ],
                        ],
                        'concepts' => ['Bet Sizing & Pot Odds'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 5: Reading Opponents & Ranges
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Reading Opponents & Ranges',
                'questions'   => [
                    [
                        'question'   => 'What is the main advantage of thinking in terms of an opponent\'s "range" rather than guessing a single exact hand?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'No player can reliably read one exact hand, but a plausible range can be narrowed from position and betting actions across the hand',
                            'options' => [
                                'No player can reliably read one exact hand, but a plausible range can be narrowed from position and betting actions across the hand',
                                'Ranges are always narrower than a single guessed hand',
                                'Ranges eliminate the need to consider board texture',
                                'Ranges only apply to preflop decisions',
                            ],
                        ],
                        'concepts' => ['Range Construction & Opponent Reading'],
                    ],
                    [
                        'question'   => "True or False: An opponent's range narrows with each additional betting decision they make throughout a hand.",
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Range Construction & Opponent Reading'],
                    ],
                    [
                        'question'   => 'What is a "balanced range"?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => "A mix of value hands and bluffs at a given bet size and situation, so opponents can't simply call or fold correctly based on the bet alone",
                            'options' => [
                                "A mix of value hands and bluffs at a given bet size and situation, so opponents can't simply call or fold correctly based on the bet alone",
                                'A range that contains only premium starting hands',
                                'A range with an equal number of hands in every suit',
                                'A range that never changes regardless of position',
                            ],
                        ],
                        'concepts' => ['Bluffing & Balanced Play'],
                    ],
                    [
                        'question'   => 'True or False: The same river bet size means the same thing regardless of which player is making it.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Range Construction & Opponent Reading'],
                    ],
                    [
                        'question'   => 'What is "table image"?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'How other players perceive you based on your recent actions at the table',
                            'options' => [
                                'How other players perceive you based on your recent actions at the table',
                                'The physical seat position at the poker table',
                                "A player's total chip count relative to the table average",
                                'The specific cards a player has shown at previous showdowns only',
                            ],
                        ],
                        'concepts' => ['Range Construction & Opponent Reading'],
                    ],
                    [
                        'question'   => 'True or False: A bluff with real fold equity, useful blockers, and some backup equity is generally better justified than a bluff with none of these.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Bluffing & Balanced Play'],
                    ],
                    [
                        'question'   => 'Match each term to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Range'          => 'The full set of hands a player could plausibly hold given their actions',
                                'Balanced Range' => 'A mix of value hands and bluffs that prevents easy exploitation',
                                'Blocker'        => 'A card you hold that makes certain opponent hands less likely',
                                'Table Image'    => 'How other players perceive you based on recent actions',
                            ],
                            'pairs' => [
                                'keys'   => ['Range', 'Balanced Range', 'Blocker', 'Table Image'],
                                'values' => [
                                    'A mix of value hands and bluffs that prevents easy exploitation',
                                    'A card you hold that makes certain opponent hands less likely',
                                    'How other players perceive you based on recent actions',
                                    'The full set of hands a player could plausibly hold given their actions',
                                ],
                            ],
                        ],
                        'concepts' => ['Range Construction & Opponent Reading'],
                    ],
                    [
                        'question'   => 'A tight, cautious player suddenly bets very large on the river. Why should this be read differently than the same bet from a loose, aggressive player?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'The same action carries different information depending on a player\'s established tendencies — an unusual action from a predictable player is a stronger signal',
                            'options' => [
                                'The same action carries different information depending on a player\'s established tendencies — an unusual action from a predictable player is a stronger signal',
                                'Bet sizing always means the exact same thing regardless of who makes it',
                                'Tight players are incapable of bluffing under any circumstances',
                                'Loose players always have the strongest possible hand when betting large',
                            ],
                        ],
                        'concepts' => ['Range Construction & Opponent Reading'],
                    ],
                    [
                        'question'   => 'Why might playing an unbalanced, exploitative strategy be more profitable against a specific weak opponent than playing a theoretically balanced strategy?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => "An opponent who isn't paying attention to your patterns won't punish the imbalance, so directly exploiting their specific tendencies captures more value",
                            'options' => [
                                "An opponent who isn't paying attention to your patterns won't punish the imbalance, so directly exploiting their specific tendencies captures more value",
                                'Balanced strategies are always less profitable in every situation',
                                'Weak opponents are not affected by any strategic adjustments',
                                'Exploitative play removes the need to consider board texture entirely',
                            ],
                        ],
                        'concepts' => ['Bluffing & Balanced Play'],
                    ],
                    [
                        "question"   => "You've been caught bluffing twice in the last hour at the table. What does sound table-image thinking suggest for your next big river bet with a genuinely strong hand?",
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Opponents are more likely to call you now, so this is a good spot to bet for value rather than expecting folds',
                            'options' => [
                                'Opponents are more likely to call you now, so this is a good spot to bet for value rather than expecting folds',
                                'You should always check strong hands after being caught bluffing',
                                'Your table image has no effect on how opponents respond to future bets',
                                'You should stop betting entirely for the rest of the session',
                            ],
                        ],
                        'concepts' => ['Range Construction & Opponent Reading'],
                    ],
                    [
                        'question'   => "Place the following steps in the correct order for constructing and narrowing an opponent's range across a hand.",
                        'type'       => 'ordering',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Start with the full range of hands consistent with their preflop action',
                                'Narrow the range based on their flop betting decision',
                                'Narrow the range further based on their turn action relative to the flop story',
                                'Narrow the range again based on their river action',
                                "Compare your own hand's strength to the final narrowed range to decide your action",
                            ],
                        ],
                        'concepts' => ['Range Construction & Opponent Reading'],
                    ],
                    [
                        'question'   => "You're considering a river bluff. You hold a card that would be part of your opponent's most likely strong hands, meaning they're less likely to hold that exact hand. What is this factor called, and how should it affect your decision?",
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => "It's a blocker — it increases the odds your opponent's range is missing their strongest hands, making the bluff more justified",
                            'options' => [
                                "It's a blocker — it increases the odds your opponent's range is missing their strongest hands, making the bluff more justified",
                                "It's a blocker — but blockers only matter for value bets, never for bluffs",
                                'This has no name in poker strategy and does not affect the decision',
                                "It's called a 'dead card' and always makes bluffing incorrect regardless of range",
                            ],
                        ],
                        'concepts' => ['Bluffing & Balanced Play'],
                    ],
                ],
            ],
        ];
    }
}
