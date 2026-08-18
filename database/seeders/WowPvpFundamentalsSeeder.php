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

class WowPvpFundamentalsSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'World of Warcraft: The War Within')->first();
        $gamesCategory = Category::where('name', 'Games')->first();

        if (! $subject || ! $gamesCategory) {
            $this->command->error('❌ WoW subject or Games category not found. Run CategorySeeder and SubjectSeeder first.');
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
        // Keep WoW concepts broad for launch.
        // Detailed topics like DR, LoS, pillars, tempo, and pressure remain inside
        // module pages/questions, but questions are tagged to these larger mastery buckets.
        $conceptsWithAxes = [
            'Role Fundamentals'    => ['Decision', 'Information', 'Adaptation'],
            'Crowd Control'        => ['Control', 'Decision', 'Information'],
            'Cooldown Management'  => ['Resources', 'Decision', 'Adaptation'],
            'Positioning'          => ['Control', 'Decision', 'Adaptation', 'Information'],
            'Target Switching'     => ['Decision', 'Information', 'Adaptation'],
            'Awareness & Tracking' => ['Information', 'Decision', 'Adaptation'],
            'Team Composition'     => ['Decision', 'Information', 'Adaptation'],
        ];

        $descriptions = [
            'Role Fundamentals'    => 'Understanding the basic purpose of each arena role and how damage, healing, pressure, tempo, and win conditions shape a match.',
            'Crowd Control'        => 'Using stuns, fears, silences, incapacitates, roots, and other control effects to create offensive windows or protect teammates.',
            'Cooldown Management'  => 'Managing offensive cooldowns, defensive cooldowns, trinkets, and resource trades across the flow of an arena match.',
            'Positioning'          => 'Using line of sight, pillars, spacing, kiting, and map position to create advantages and avoid unnecessary danger.',
            'Target Switching'     => 'Changing targets based on cooldowns, positioning, pressure, and enemy vulnerabilities.',
            'Awareness & Tracking' => 'Tracking enemy cooldowns, DR states, positions, pressure windows, and teammate danger in real time.',
            'Team Composition'     => 'Understanding how different class and role combinations create win conditions, pressure patterns, and defensive needs.',
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

        $this->command->info("✅ Broad WoW PvP concepts verified/linked. Count: {$upserted}");
    }

    private function seedModules(Subject $subject): void
    {
        $modules = [
            [
                'name'        => 'Arena Fundamentals',
                'description' => 'Understand the core language and concepts of arena PvP — what pressure is, how matches are won, why CC matters, and how positioning influences fight outcomes.',
                'proficiency' => 'Beginner',
            ],
            [
                'name'        => 'Crowd Control Fundamentals',
                'description' => 'Learn what crowd control does, why it creates opportunities, how Diminishing Returns shapes CC chains, and how CC is used both offensively and defensively.',
                'proficiency' => 'Beginner',
            ],
            [
                'name'        => 'Cooldown Trading',
                'description' => 'Understand the resource economy of arena PvP — why cooldowns are valuable, how trading them shapes fight outcomes, and why good players plan cooldown usage ahead of time.',
                'proficiency' => 'Casual',
            ],
            [
                'name'        => 'Arena Positioning',
                'description' => 'Develop spatial decision-making — why positioning creates advantages, how pillars and line of sight shape fights, and how positioning changes under pressure.',
                'proficiency' => 'Casual',
            ],
            [
                'name'        => 'Team Composition Fundamentals',
                'description' => 'Understand your own comp\'s synergy, how CC categories and Diminishing Returns shape crowd control chains across teammates, kill target selection, and basic peel priority.',
                'proficiency' => 'Beginner',
            ],
        ];

        foreach ($modules as $data) {
            $proficiency = Proficiency::where('name', $data['proficiency'])
                ->where('subject_id', $subject->id)
                ->first();

            if (! $proficiency) {
                $this->command->warn("⚠️ Proficiency '{$data['proficiency']}' not found for WoW — skipping '{$data['name']}'.");
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

        $this->command->info('✅ WoW PvP Fundamentals modules seeded.');
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

        $this->command->info("✅ WoW PvP module pages: {$created} upserted, {$skipped} skipped.");
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

        $this->command->info("✅ WoW PvP questions: {$created} created, {$linked} linked to modules.");
    }

    // =========================================================================
    // PAGE CONTENT
    // =========================================================================

    private function pages(): array
    {
        return [

            // -----------------------------------------------------------------
            // MODULE 1: Arena Fundamentals
            // -----------------------------------------------------------------
            [
                'module_name' => 'Arena Fundamentals',
                'page_number' => 1,
                'title'       => 'Arena Fundamentals — Core Concepts',
                'content'     => <<<'MD'
# Arena Fundamentals

## What Arena PvP Is Trying to Accomplish

Arena PvP pits small teams (2v2 or 3v3) against each other with one objective: eliminate the opposing team. Unlike PvE content there are no scripts or predictable patterns. Every decision you face is made by another human being trying to outmaneuver you.

Understanding arena at a conceptual level — before thinking about class mechanics or compositions — is the foundation of every improvement you will make.

## The Win Condition

Every arena team has a **win condition**: the specific action or series of actions that ends the match. In almost every case the primary win condition is **killing the enemy healer**. The healer is what keeps a team alive. Remove the healer and the remaining players can no longer survive sustained pressure.

Secondary win conditions exist: some compositions kill a DPS first when the healer is well-protected, and some attempt to win through mana attrition — grinding the enemy healer out of resources over a long fight. Understanding your team's win condition gives every decision a purpose.

## Pressure

**Pressure** is the act of forcing the enemy team to spend defensive resources. A team under pressure must use healing, defensive cooldowns, CC breaks, and repositioning just to stay alive — leaving fewer resources to threaten you.

Pressure is created through:
- **Damage**: Sustained damage forces healing. Burst damage threatens kills.
- **Crowd Control**: Locking a player out of the fight multiplies the effectiveness of damage.
- **Positioning**: Forcing the enemy into unfavourable positions limits their options.
- **Offensive cooldowns**: Temporarily increasing damage beyond what healing can cover.

Pressure does not need to result in a kill. A healer who burns all their mana responding to pressure is a healer who is easier to kill in the next offensive cycle.

## Why Crowd Control Matters

Crowd control prevents enemies from acting. A stunned healer cannot cast heals. A feared DPS cannot apply pressure. A silenced caster cannot use most of their toolkit.

CC is the bridge between damage and kills. Without CC a good healer can react to damage in real time and neutralise almost any pressure. With CC even one second of the healer being disabled is often enough to swing the fight.

CC has two uses:
- **Offensive CC**: Locking the healer out of the fight while your team bursts a target.
- **Defensive CC**: Stunning or rooting an enemy DPS who is threatening your healer (called peeling).

## Positioning and Its Influence

Where players stand determines what they can reach, what can reach them, and how quickly they can escape danger.

- A healer standing in the open can be interrupted, targeted, and killed. A healer behind a pillar forces enemies to reposition to reach them.
- A melee DPS out of range cannot deal damage. A ranged DPS too close to the enemy can be interrupted and CC'd.
- A team with one player overextended — too far from teammates and terrain — is easier to CC and kill than a team playing with discipline.

Good positioning changes constantly in response to pressure.

## Tempo: Offensive and Defensive Play

**Tempo** describes which team is currently dictating the pace of the fight. A team with tempo is on offense — applying damage, chaining CC, and building toward a kill. A team without tempo is on defense — responding, healing, and trying to survive until the pressure eases.

**Offensive play** means spending resources to create pressure: using offensive cooldowns, chaining CC, and targeting kill windows.

**Defensive play** means spending resources to survive pressure: using defensive cooldowns, repositioning, peeling, and healing.

Neither mode is superior. Knowing when to play offensively and when to play defensively — and recognising which the fight demands — is one of the most important skills in arena.

## How Matches Are Won

Matches typically end through one of the following patterns:

1. **Controlled kill**: CC on the healer, offensive cooldowns on the target, kill secured before the healer recovers.
2. **Cooldown punishment**: Bait out the enemy's defensive cooldowns, then commit your offensive cooldowns while they have no answer.
3. **Mana attrition**: Force the enemy to heal constantly until the healer runs out of mana.
4. **Mistake exploitation**: A positioning error, misused cooldown, or wasted CC creates a moment of vulnerability that an aware team capitalises on.

Most matches follow a consistent pattern: each team builds toward their win condition, trading resources and waiting for the right moment to commit.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 2: Crowd Control Fundamentals
            // -----------------------------------------------------------------
            [
                'module_name' => 'Crowd Control Fundamentals',
                'page_number' => 1,
                'title'       => 'Crowd Control Fundamentals',
                'content'     => <<<'MD'
# Crowd Control Fundamentals

## Why CC Exists

Crowd control converts damage potential into kills. In arena PvP a skilled healer can respond to almost any amount of damage as long as they are free to act. CC removes that freedom. It creates time — brief windows in which the enemy cannot respond — and time is the resource that kills are built from.

## CC Types and Their Characteristics

### Stuns
Stuns prevent **all actions**: movement, attacks, spells, and ability use. They are the most powerful CC type and are subject to Diminishing Returns (DR).

### Incapacitates
Incapacitates also prevent all actions but have a critical weakness: they **break immediately when the target takes damage**. This means your team must hold damage while the incapacitate is active, or it is wasted. They are used to set up extended CC chains and to pause a target safely.

### Fears
Fears cause the target to run uncontrollably in a random direction. The target loses control of movement but retains most other abilities. Fears are particularly effective at displacing a healer from a safe position, forcing them into the open or away from their team.

### Silences
Silences prevent **spellcasting only**. A silenced target can still move, use physical abilities, and use items. Silences are most effective against caster healers and ranged DPS who rely heavily on spells.

### Roots and Slows
Roots prevent movement but allow the target to act normally. Slows reduce movement speed. These are softer CC types — they limit options but do not prevent action. They are valuable for kiting, chasing, and disrupting positioning but do not create kill windows on their own.

## Diminishing Returns (DR)

DR prevents CC from being applied indefinitely. When the same **category** of CC is applied to the same target in a short window the duration is reduced:

| Application | Duration |
|-------------|----------|
| First       | 100% (Full duration) |
| Second      | 50% duration |
| Third       | 0% (Full Immunity) |

The DR window resets 20 seconds after the last CC effect of that category expires — once 20 seconds pass with no new application of that category, the next application lands at full duration again.

DR is shared within categories — stuns share DR with other stuns, fears share with fears, and so on. Silences and incapacitates each have their own categories.

**Why DR matters strategically:**
- Applying a second stun while a target is already DR'd from the first wastes value.
- Chaining two stuns back-to-back (stun → stun) gives far less disable time than spacing them with a different CC type (stun → incapacitate → stun).
- Understanding DR allows you to sequence CC for maximum total disable time.

## Offensive CC: Kill Windows and Setup Windows

A **setup window** is the preparation phase before a kill attempt. It involves getting into position, ensuring offensive cooldowns are ready, and beginning the CC chain.

A **kill window** is the moment the kill becomes possible — typically when the healer is incapacitated and the target is taking damage.

The sequence:
1. Create a setup (healer is caught out of position or CC from an unexpected angle lands)
2. Open the kill window (hard CC on the healer)
3. Stack damage on the kill target during the window
4. Extend the window with additional CC using DR-aware sequencing
5. Secure the kill

Teams that land kills consistently do so not because their burst is unusually high, but because their CC setup prevents the healer from responding long enough for damage to accumulate.

## Defensive CC: Peeling

**Peeling** means using CC to protect a teammate from incoming pressure. The most common example: a DPS using a stun or root on an enemy melee who is chasing your healer.

Peeling is one of the highest-value decisions a DPS can make. A healer being trained by an unchecked melee cannot position safely, cannot cast freely, and burns defensive cooldowns just to survive. One well-placed peel interrupts this cycle.

Effective peeling requires:
- **Awareness**: Recognising when your healer is in danger before they have to call for help.
- **Decisiveness**: Using CC promptly, not after the healer has already burned a defensive.
- **Resource efficiency**: Not wasting your stun on a peel when a root or slow would be sufficient.

## Why Overlapping CC Is Wasteful

Two players stunning the same target at the same time does not double the disable time — only one stun is active at once, both players have used a cooldown, and the second stun applies at reduced duration due to DR.

Sequential CC is almost always more efficient: first stun expires → second stun applied (DR has partially reset) → maximum total disable time.

The exception is an emergency peel where any CC immediately is better than perfect sequencing.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 3: Cooldown Trading
            // -----------------------------------------------------------------
            [
                'module_name' => 'Cooldown Trading',
                'page_number' => 1,
                'title'       => 'Cooldown Trading — Resource Economy in Arena',
                'content'     => <<<'MD'
# Cooldown Trading — Resource Economy in Arena

## Cooldowns as Resources

Arena PvP is fundamentally a resource game. The most important resources in most matches are **cooldowns** — powerful abilities with long reset timers (typically 2–5 minutes) that define the critical moments of a fight.

Using cooldowns well or poorly makes the difference between winning and losing. Treating them as precious resources — not buttons to press whenever available — is one of the largest mindset shifts beginners need to make.

## Offensive Cooldowns

**Offensive cooldowns** temporarily and significantly increase your damage output. During an offensive cooldown your team can deal damage that the enemy healer may struggle to respond to — this is the definition of a kill window.

Offensive cooldowns should be used with intention:
- **Committed when conditions are right**: Enemy healer is CC'd or caught out of position; their defensive has already been spent.
- **Not used randomly**: Random offensive cooldown usage without a coordinated kill attempt is wasted value.
- **Supported by CC**: Offensive cooldowns without CC allow the healer to respond freely. CC without offensive cooldowns misses the kill.

## Defensive Cooldowns

**Defensive cooldowns** reduce incoming damage, grant immunity to effects, or provide emergency healing. They exist to survive moments when enemy offensive cooldowns are active.

The key beginner mistake is using defensive cooldowns **too early** — spending them on normal pressure before the enemy has committed their major offensive cooldowns. This leaves nothing available when the real burst comes.

Save defensive cooldowns for:
- Moments of coordinated enemy burst (offensive cooldowns active)
- Moments when CC prevents healing from being cast
- Situations where death is likely without an immediate defensive response

## Cooldown Trading

**Cooldown trading** is the process of using major abilities in response to each other. When the enemy uses their main offensive cooldown, you respond with a defensive. When your offensive is up and their defensive is down, you attack.

The goal is to win the trade — to get more value from your cooldowns than the enemy gets from theirs. A trade is won when:
- Your offensive cooldown creates a kill
- Your defensive cooldown survives a burst phase at low or no cost
- The enemy spends two cooldowns to address one of yours

## Cooldown Overlap: A Common Mistake

**Overlap** means using two cooldowns simultaneously in a way that wastes the value of one or both.

1. **Offensive overlap**: Two players using offensive cooldowns at the same time. The burst may be high, but if the enemy uses one defensive to survive it, both offensives were answered by one defensive — a bad trade.
2. **Defensive overlap**: Two players using defensive cooldowns for the same pressure. One was probably enough; the second was wasted.

Overlap is caused by poor communication or panic. Good arena teams call cooldown usage in advance to avoid wasting resources simultaneously.

## Pressure Cycles

Arena fights follow a cyclical pattern:

1. **Pressure phase**: One team uses offensive cooldowns and CC to create a kill attempt.
2. **Recovery phase**: The defending team survives using defensive cooldowns, then waits for cooldowns to reset.
3. **Counter-pressure phase**: Either team pushes again when their cooldowns are ready.

Where you are in this cycle determines how you should play. If your offensive cooldowns are ready and the enemy's defensives are down, attack. If the enemy's defensives are all available and yours are used, playing defensively and waiting is almost always correct.

## Baiting Defensive Cooldowns

You can use moderate pressure to **bait** the enemy into using a defensive cooldown early, then commit your real offensive once their defensive has been wasted.

Example: Apply minor pressure against the enemy healer. If they panic and use a major defensive, that defensive is now on a 2–3 minute cooldown. Commit your offensive cooldowns and CC chain for a real kill attempt — against a healer with no defence available.

## The Trinket as a Cooldown Resource

The PvP Trinket breaks one crowd control effect and has a 2-minute cooldown. In the context of cooldown trading the trinket is a defensive cooldown like any other.

Enemies may attempt to **fake the trinket** — applying a non-lethal CC to bait the trinket, then following with the real CC chain after it is used. Holding the trinket for the high-value CC — the stun during the actual kill attempt — is a key skill.

## Resource Management: Thinking Ahead

Good arena players think 30–60 seconds ahead:
- "My offensive resets in 45 seconds. Be ready to attack then."
- "They just used their defensive. Our best window is the next 2 minutes."
- "My partner's CC is on DR right now. Wait for it to drop before setting up the chain."

Resource management turns reactive play into proactive play. Instead of responding to what the enemy does, you create the conditions for your team's kill before it happens.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 4: Arena Positioning
            // -----------------------------------------------------------------
            [
                'module_name' => 'Arena Positioning',
                'page_number' => 1,
                'title'       => 'Arena Positioning — Spatial Decision-Making',
                'content'     => <<<'MD'
# Arena Positioning — Spatial Decision-Making

## Why Positioning Matters

In arena PvP positioning determines what options are available to every player at every moment. A player in a good position has access to abilities, line of sight, and escape routes. A player in a bad position is vulnerable — to CC chains, to being targeted, to being killed.

Positioning is not passive. Players who stand still are giving the enemy positional advantages for free. Good positioning requires constant, active adjustment in response to enemy movement, incoming threats, and fight transitions.

## Line of Sight

**Line of Sight (LoS)** is the mechanic by which terrain blocks spells and abilities. If a wall, pillar, or obstacle is between you and your attacker, most targeted spells and abilities cannot reach you.

**Defensive LoS**: Breaking line of sight interrupts an attacker's casts, forces them to move, and buys time. A healer behind a pillar cannot be cast at — attackers must reposition to reach them, and that movement takes time and creates opportunities.

**Offensive LoS**: Baiting enemies into bad positions. Positioning on the opposite side of a pillar from the enemy healer forces them to choose between staying safe (out of healing range) or moving into the open (becoming a target).

## Pillars: The Core of Arena Positioning

Arena maps are designed with **pillars** — large central obstacles that define positional play. How teams use them:

- **Healers**: Position behind a pillar relative to the enemy DPS. Break line of sight to prevent interrupts and CC from range. Peek to heal, then retreat.
- **Melee DPS**: Chase the healer around the pillar, forcing them to keep moving and making positioning mistakes.
- **Ranged DPS**: Use the pillar to break LoS with chasing melees while maintaining angle on the enemy.

Common pillar patterns:
- **Pillar humping**: Continuously repositioning around a pillar to deny the enemy a clear angle.
- **Pillar kiting**: The targeted player circles the pillar while a teammate peels or heals.

## Kiting

**Kiting** is movement as a defensive tool. When a melee attacker is chasing you, kiting means staying out of their range while impeding their approach.

Effective kiting combines:
- **Movement**: Running away from the attacker, using terrain to create distance.
- **Roots and slows**: Keeping the attacker at range.
- **LoS breaks**: Using pillars to interrupt the attacker's abilities and force repositioning.

Kiting does not mean running from the fight — it means buying time for a teammate to peel, for a cooldown to reset, or for the attacker to overextend.

## Overextension

**Overextension** is one of the most punishable mistakes in arena. It occurs when a player moves too far from safe terrain or teammates — leaving them exposed to CC chains, focus fire, or isolation.

Forms of overextension:
- **Voluntary**: A DPS chases an enemy healer too deep into the enemy's half, getting surrounded.
- **Forced**: A poor initial position leaves a player with no safe retreat.
- **Gradual**: A player slowly drifts from the pillar during a fight without noticing.

Teams that recognise an enemy overextension should exploit it immediately — this is a free kill window.

## Healer Positioning

The healer has the most critical positioning requirements:
- **Stay within healing range of teammates** without standing in the open.
- **Use pillars** to break LoS with attackers and interrupt their casts.
- **Never overextend** — the healer's death loses the match.
- **Position relative to enemy pressure**: If the enemy is on one side, the healer should be on the opposite side of the pillar.

Healer positioning changes as the fight develops. Under light pressure a healer can stand more openly to heal freely. Under heavy pressure the healer should prioritise survival above all else — moving continuously and using LoS aggressively.

## Safe vs Aggressive Positioning

**Safe positioning** means staying near terrain and teammates, limiting risk and preserving options. It is appropriate when your team is on the back foot, when key cooldowns are unavailable, or when the enemy is building toward an offensive.

**Aggressive positioning** means moving closer to the enemy to apply pressure or enable CC. It is appropriate when your team has offensive cooldowns ready and is committed to a kill attempt.

Using aggressive positioning at the wrong time — moving up when your team has no cooldowns — only exposes you to the enemy's CC and burst. Read the cooldown state of both teams before committing.

## Map Awareness

**Map awareness** means tracking the positions and states of all players — both allies and enemies — throughout the fight. It answers:
- Where is the enemy healer? In the open or behind a pillar?
- Is the enemy DPS chasing my healer or applying pressure to me?
- Did the enemy team just regroup? Is an offensive incoming?

Map awareness informs every positioning decision. A player who does not know where the enemy team is cannot position against threats they do not see.

Developing map awareness requires looking beyond your own character — noting enemy locations, teammate positions, and processing that information in real time. This is a habit that improves with practice.
MD,
            ],

            // -----------------------------------------------------------------
            // MODULE 5: Team Composition Fundamentals
            // -----------------------------------------------------------------
            [
                'module_name' => 'Team Composition Fundamentals',
                'page_number' => 1,
                'title'       => 'Team Composition Fundamentals',
                'content'     => <<<'MD'
# Team Composition Fundamentals

## What "Team Composition" Really Means

At the beginner level, team composition isn't about drafting the strongest comp or reacting to what you queue into — it's about understanding your own team's identity: what your comp is built to do, and how each teammate's tools work together. A comp has synergy when its pieces reinforce each other — one player's crowd control setting up another's burst window, or one player's mobility covering a teammate who lacks it. Knowing your comp's synergy means knowing who creates openings, who capitalises on them, and whose job is mainly to protect the others.

## CC Categories and Diminishing Returns

Crowd control effects are grouped into categories — stuns, incapacitates, fears, silences, roots — and Diminishing Returns (DR) is tracked per category, per target. Two effects from the *same* category back-to-back on one target waste value: the second lands at reduced duration. Effects from *different* categories don't share DR at all — each tracks its own duration independently. This is why a CC chain is only as strong as its category diversity — a comp drawing on several DR categories can disable a target far longer, in total, than one reusing a single category no matter how many charges are available.

## Chaining CC Across Teammates

Because DR is tracked per category and per target, a comp's crowd control gets powerful when teammates layer *different* categories on *different* targets in sequence, rather than stacking effects on one target or duplicating each other's category. A simple pattern: one teammate opens with hard CC on the kill target while a second holds a different category in reserve — either for when the first expires, or for a second target such as a healer or peel target. The goal is maximising total uptime of "the enemy cannot act," not maximising how many times CC gets used.

## Kill Target Selection

Which target a comp commits to killing follows from what the comp is actually built to do. A comp built around a short, heavy burst window wants that window spent on whoever dies fastest under it — often whoever has the least defensive support available in that moment, not necessarily the lowest health. A comp built around sustained pressure over a longer fight wants a target whose team cannot out-heal or out-sustain that pressure indefinitely. Kill target selection is a comp-level question, not an individual preference — it follows from what your team's tools are actually good at.

## Basic Peel Priority

Peeling — using CC or positioning to protect a threatened teammate — is a team decision, not left to whoever notices danger first. A comp should have a basic, agreed priority: which teammate gets protected first when the team can't protect everyone at once. Typically this is whoever's loss ends the team's ability to keep applying pressure or staying alive. Deciding this in advance, as a comp-level agreement, prevents wasted or duplicated CC when several teammates react to the same threat independently.
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
            // MODULE 1: Arena Fundamentals
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Arena Fundamentals',
                'questions'   => [
                    [
                        'question'   => 'What is the primary win condition in most arena matches?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Killing the enemy healer to remove the team\'s source of sustain',
                            'options' => [
                                'Killing the enemy healer to remove the team\'s source of sustain',
                                'Dealing the highest total damage over the course of the match',
                                'Surviving until the arena timer expires',
                                'Using crowd control more times than the opposing team',
                            ],
                        ],
                        'concepts' => ['Role Fundamentals'],
                    ],
                    [
                        'question'   => 'What does it mean to "create pressure" in arena PvP?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Forcing the enemy team to spend defensive resources to stay alive',
                            'options' => [
                                'Forcing the enemy team to spend defensive resources to stay alive',
                                'Standing close to the enemy to reduce the range of their spells',
                                'Using the arena timer to force the enemy into a smaller area',
                                'Dealing a single large burst of damage at the start of the fight',
                            ],
                        ],
                        'concepts' => ['Role Fundamentals'],
                    ],
                    [
                        'question'   => 'True or False: Crowd control creates pressure by preventing the enemy from taking actions, which reduces their ability to respond to damage.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Role Fundamentals', 'Crowd Control'],
                    ],
                    [
                        'question'   => 'In arena, "tempo" refers to which of the following?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Which team is currently dictating the pace and applying pressure',
                            'options' => [
                                'Which team is currently dictating the pace and applying pressure',
                                'The speed at which a player casts their abilities',
                                'The number of crowd control abilities a team has available',
                                'The cooldown reset speed of offensive abilities',
                            ],
                        ],
                        'concepts' => ['Role Fundamentals'],
                    ],
                    [
                        'question'   => 'True or False: A team playing "defensively" is spending resources to survive incoming pressure while waiting for an opportunity to shift back to offense.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Role Fundamentals'],
                    ],
                    [
                        'question'   => 'True or False: Positioning is a passive element of arena — you only need to move when you are taking damage.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Positioning'],
                    ],
                    [
                        'question'   => 'Match each arena concept to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Pressure'      => 'Forcing the enemy to spend defensive resources',
                                'Win Condition' => 'The strategy that leads to winning the match',
                                'Tempo'         => 'Momentum — which team is controlling the pace',
                                'Peeling'       => 'Using CC to protect a threatened teammate',
                            ],
                            'pairs' => [
                                'keys'   => ['Pressure', 'Win Condition', 'Tempo', 'Peeling'],
                                'values' => [
                                    'The strategy that leads to winning the match',
                                    'Momentum — which team is controlling the pace',
                                    'Using CC to protect a threatened teammate',
                                    'Forcing the enemy to spend defensive resources',
                                ],
                            ],
                        ],
                        'concepts' => ['Role Fundamentals'],
                    ],
                    [
                        'question'   => 'Your team has used all offensive cooldowns but the enemy healer is still at full health. What does this indicate?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'The kill attempt failed — your team must now play defensively and wait for cooldowns to reset',
                            'options' => [
                                'The kill attempt failed — your team must now play defensively and wait for cooldowns to reset',
                                'Continue using abilities even without cooldowns; attrition will eventually work',
                                'Immediately switch targets to the enemy DPS',
                                'Use crowd control on the healer even though there is no damage to follow it up',
                            ],
                        ],
                        'concepts' => ['Role Fundamentals', 'Cooldown Management'],
                    ],
                    [
                        'question'   => 'Why does crowd control increase pressure on the enemy team even when it does not directly contribute to a kill?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Because it forces the CC\'d player to spend a break (like a trinket) or lose time acting, costing the team a resource',
                            'options' => [
                                'Because it forces the CC\'d player to spend a break (like a trinket) or lose time acting, costing the team a resource',
                                'Because CC deals bonus damage that compounds over multiple uses',
                                'Because CC increases your team\'s movement speed during the effect',
                                'Because it always triggers Diminishing Returns, weakening the enemy permanently',
                            ],
                        ],
                        'concepts' => ['Role Fundamentals', 'Crowd Control'],
                    ],
                    [
                        'question'   => 'Place the following steps of a basic arena kill attempt in the correct order.',
                        'type'       => 'ordering',
                        'difficulty' => 'medium',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Confirm offensive cooldowns are ready and the enemy healer has no major defensive available',
                                'Apply crowd control to the enemy healer to prevent them from responding',
                                'Use offensive cooldowns on the kill target to maximise damage',
                                'Chain additional CC to extend the window as the first CC expires',
                                'Secure the kill before the healer returns to full effectiveness',
                            ],
                        ],
                        'concepts' => ['Role Fundamentals', 'Crowd Control'],
                    ],
                    [
                        'question'   => 'Your team is playing defensively and your healer is under heavy pressure. You have a stun available. Which use creates the most value right now?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Stun the enemy DPS pressuring your healer so they can reposition and cast heals safely',
                            'options' => [
                                'Stun the enemy DPS pressuring your healer so they can reposition and cast heals safely',
                                'Save the stun for an offensive rotation later in the fight',
                                'Stun the enemy healer to prevent them from buffing their team',
                                'Use the stun on whatever target you are currently attacking',
                            ],
                        ],
                        'concepts' => ['Role Fundamentals', 'Crowd Control'],
                    ],
                    [
                        'question'   => 'The enemy team just committed their offensive cooldowns and your team survived using defensive cooldowns. What is the correct play now?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Shift to offense — the enemy\'s cooldowns are down and your team has a window of advantage',
                            'options' => [
                                'Shift to offense — the enemy\'s cooldowns are down and your team has a window of advantage',
                                'Continue playing defensively until both teams are fully recovered',
                                'Use all remaining abilities immediately while the enemy is regrouping',
                                'Focus only on healing up before attempting any offensive play',
                            ],
                        ],
                        'concepts' => ['Role Fundamentals', 'Cooldown Management'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 2: Crowd Control Fundamentals
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Crowd Control Fundamentals',
                'questions'   => [
                    [
                        'question'   => 'Under Diminishing Returns, how long does the second application of the same CC type last compared to the first?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => '50% of the original duration',
                            'options' => [
                                '50% of the original duration',
                                '75% of the original duration',
                                'Full immunity (0%)',
                                'The same full duration as the first',
                            ],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'Which CC type prevents all actions but breaks immediately when the target takes damage?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Incapacitate',
                            'options' => ['Incapacitate', 'Stun', 'Silence', 'Root'],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'True or False: A silence prevents the target from moving.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'What does "peeling" mean in the context of defensive crowd control?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Using CC to remove an enemy attacker who is threatening a teammate',
                            'options' => [
                                'Using CC to remove an enemy attacker who is threatening a teammate',
                                'Removing a debuff from yourself using a personal defensive cooldown',
                                'Switching targets to find a more vulnerable enemy',
                                'Breaking your own CC using the PvP Trinket',
                            ],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'True or False: Fears and stuns belong to the same Diminishing Returns category.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'True or False: An incapacitate effect breaking on damage means your team should hold all damage output while the incapacitate is active.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'Match each CC type to its defining characteristic.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Stun'         => 'Prevents all actions; does not break on damage',
                                'Incapacitate' => 'Prevents all actions; breaks on damage',
                                'Fear'         => 'Causes uncontrolled random movement',
                                'Silence'      => 'Prevents spellcasting only',
                            ],
                            'pairs' => [
                                'keys'   => ['Stun', 'Incapacitate', 'Fear', 'Silence'],
                                'values' => [
                                    'Prevents all actions; breaks on damage',
                                    'Causes uncontrolled random movement',
                                    'Prevents spellcasting only',
                                    'Prevents all actions; does not break on damage',
                                ],
                            ],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'Your partner just applied a stun to the enemy healer. You also have a stun available. What is the most efficient play?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Wait until the first stun expires, or use a different CC category now to extend the window without wasting DR',
                            'options' => [
                                'Wait until the first stun expires, or use a different CC category now to extend the window without wasting DR',
                                'Apply your stun immediately on top of the existing stun for double the effect',
                                'Save your stun permanently — never stun during a kill attempt',
                                'Use your stun on the enemy DPS instead to prevent them from dealing damage',
                            ],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'Why is chaining CC sequentially generally more effective than applying it simultaneously?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Sequential CC maximises total disable time; simultaneous CC overlaps durations and wastes cooldowns to DR',
                            'options' => [
                                'Sequential CC maximises total disable time; simultaneous CC overlaps durations and wastes cooldowns to DR',
                                'Simultaneous CC is more effective because it overwhelms DR immunity',
                                'Sequential CC is only better for stuns; simultaneous is better for all other types',
                                'There is no difference — the total disable time is the same regardless of order',
                            ],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'True or False: A fear can be used defensively to displace an enemy DPS who is pressuring your healer.',
                        'type'       => 'true_false',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'The enemy healer has their PvP Trinket available. Your team plans to open with a stun for a kill attempt. What should your team account for in the CC chain?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'The healer will likely trinket the stun — follow up immediately with a different CC type after the trinket is used',
                            'options' => [
                                'The healer will likely trinket the stun — follow up immediately with a different CC type after the trinket is used',
                                'Avoid using stuns entirely when the enemy trinket is available',
                                'Use all CC simultaneously to prevent the trinket from making a difference',
                                'The trinket does not affect stun duration, so proceed normally',
                            ],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order for a well-executed CC chain kill attempt on the enemy healer.',
                        'type'       => 'ordering',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Confirm the enemy healer has used their trinket or a setup makes it unlikely they can use it',
                                'Apply hard CC (stun) to the healer to open the kill window',
                                'Stack damage on the kill target while the healer is disabled',
                                'As the stun expires, apply a different CC category to extend the window',
                                'Secure the kill before the healer returns to full effectiveness',
                            ],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 3: Cooldown Trading
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Cooldown Trading',
                'questions'   => [
                    [
                        'question'   => 'What is an "offensive cooldown" in arena PvP?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A powerful ability with a long reset timer that significantly increases damage output',
                            'options' => [
                                'A powerful ability with a long reset timer that significantly increases damage output',
                                'Any ability that deals damage to an enemy player',
                                'A cooldown that removes debuffs from a teammate',
                                'An ability used only when the player is on low health',
                            ],
                        ],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'What is a "defensive cooldown" in arena PvP?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A powerful ability with a long reset timer that reduces damage taken or increases survivability',
                            'options' => [
                                'A powerful ability with a long reset timer that reduces damage taken or increases survivability',
                                'Any ability that restores health to yourself or a teammate',
                                'A cooldown used to interrupt the enemy\'s spellcasting',
                                'An ability that moves you to a safe location instantly',
                            ],
                        ],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'True or False: Using your defensive cooldown before the enemy has committed their offensive cooldowns leaves you more vulnerable when the real burst arrives.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'What does "cooldown overlap" mean in arena?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Using multiple cooldowns simultaneously in a way that wastes the value of one or more',
                            'options' => [
                                'Using multiple cooldowns simultaneously in a way that wastes the value of one or more',
                                'Two players having the same cooldown type available at the same time',
                                'When a cooldown resets at the same moment as the enemy\'s cooldown',
                                'Using a cooldown more than once in a single fight due to a talent',
                            ],
                        ],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'True or False: The PvP Trinket is considered a defensive cooldown resource because it has a long reset timer and breaks crowd control.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'True or False: Cooldown trading means each team uses major abilities in response to each other — spending resources to counter opposing threats.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'Match each cooldown category to its primary strategic purpose.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Offensive Cooldown' => 'Creating a kill window by exceeding the healer\'s response capacity',
                                'Defensive Cooldown' => 'Surviving a burst phase that would otherwise result in death',
                                'Trinket'            => 'Breaking a CC that would lead to a kill if left unaddressed',
                                'Pressure Cycle'     => 'The rhythm of offense and recovery that structures a fight',
                            ],
                            'pairs' => [
                                'keys'   => ['Offensive Cooldown', 'Defensive Cooldown', 'Trinket', 'Pressure Cycle'],
                                'values' => [
                                    'Surviving a burst phase that would otherwise result in death',
                                    'Breaking a CC that would lead to a kill if left unaddressed',
                                    'The rhythm of offense and recovery that structures a fight',
                                    'Creating a kill window by exceeding the healer\'s response capacity',
                                ],
                            ],
                        ],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'The enemy team has just committed their main offensive cooldown on your healer. When should your healer use their major defensive cooldown?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Immediately — the enemy offensive is already active and the defensive is needed to survive the burst now',
                            'options' => [
                                'Immediately — the enemy offensive is already active and the defensive is needed to survive the burst now',
                                'Only after dropping below 20% health to ensure it is not wasted early',
                                'After the enemy offensive expires, to prepare for the next burst',
                                'Hold the defensive until the enemy uses CC on the healer',
                            ],
                        ],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'Why is it generally better to wait for the enemy to use their defensive cooldown before committing your offensive cooldowns?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Your offensive is more likely to kill if the target has no defensive available to counter it',
                            'options' => [
                                'Your offensive is more likely to kill if the target has no defensive available to counter it',
                                'Offensive cooldowns deal bonus damage when used after an enemy defensive has expired',
                                'The enemy defensive reduces all damage you deal while it is active',
                                'You should always save offensive cooldowns and never use them proactively',
                            ],
                        ],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'Place the following steps in the correct order for a properly executed cooldown-based kill attempt.',
                        'type'       => 'ordering',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Confirm the enemy healer has no defensive cooldown available, or bait it out first with minor pressure',
                                'Apply CC to the enemy healer to prevent them from responding',
                                'Commit offensive cooldowns on the kill target',
                                'Chain additional CC using DR-aware sequencing to extend the kill window',
                                'Secure the kill before the healer recovers or a defensive comes off cooldown',
                            ],
                        ],
                        'concepts' => ['Cooldown Management', 'Crowd Control'],
                    ],
                    [
                        'question'   => 'The enemy DPS just used a major defensive cooldown making them temporarily immune to damage. Your team has offensive cooldowns ready. What is the best response?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Switch to the enemy healer or wait for the immunity to expire — committing offensive cooldowns into an immune target wastes them',
                            'options' => [
                                'Switch to the enemy healer or wait for the immunity to expire — committing offensive cooldowns into an immune target wastes them',
                                'Use all offensive cooldowns immediately to deal as much damage as possible before the immunity ends',
                                'Use all available CC on the immune target until it expires',
                                'Retreat and wait for your cooldowns to reset before attempting anything',
                            ],
                        ],
                        'concepts' => ['Cooldown Management'],
                    ],
                    [
                        'question'   => 'True or False: Two players using their offensive cooldowns simultaneously always produces the best result because it creates maximum burst.',
                        'type'       => 'true_false',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Cooldown Management'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 4: Arena Positioning
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Arena Positioning',
                'questions'   => [
                    [
                        'question'   => 'What is Line of Sight (LoS) in WoW arena?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'A mechanic where terrain blocks spells from reaching their target',
                            'options' => [
                                'A mechanic where terrain blocks spells from reaching their target',
                                'The maximum range at which a player can cast spells',
                                'A healer\'s ability to monitor all teammates on the minimap',
                                'A debuff that reduces visibility during combat',
                            ],
                        ],
                        'concepts' => ['Positioning'],
                    ],
                    [
                        'question'   => 'What is "kiting" in WoW PvP?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Moving away from a melee attacker while using slows or roots to maintain distance',
                            'options' => [
                                'Moving away from a melee attacker while using slows or roots to maintain distance',
                                'Chasing the enemy healer around a pillar to prevent them from healing',
                                'Using a mount to escape dangerous combat situations',
                                'Positioning your character to face a specific direction during combat',
                            ],
                        ],
                        'concepts' => ['Positioning'],
                    ],
                    [
                        'question'   => 'True or False: Moving behind a pillar can interrupt an enemy\'s ongoing spell cast.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Positioning'],
                    ],
                    [
                        'question'   => 'What is "overextension" in arena?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Moving too far from safe terrain or teammates, making yourself vulnerable to CC and kills',
                            'options' => [
                                'Moving too far from safe terrain or teammates, making yourself vulnerable to CC and kills',
                                'Using too many abilities in a short window, causing Diminishing Returns',
                                'Dealing more damage than the enemy healer can respond to',
                                'Running past the pillar while chasing the enemy healer',
                            ],
                        ],
                        'concepts' => ['Positioning'],
                    ],
                    [
                        'question'   => 'True or False: A healer should position aggressively near the enemy team to stay within healing range of all teammates.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Positioning'],
                    ],
                    [
                        'question'   => 'What does "map awareness" mean in arena positioning?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Tracking the positions and states of all players — allies and enemies — throughout the fight',
                            'options' => [
                                'Tracking the positions and states of all players — allies and enemies — throughout the fight',
                                'Memorising the layout of every arena map before playing',
                                'Using the minimap to find the nearest friendly healer',
                                'Identifying the best pillar to stand behind at the start of each match',
                            ],
                        ],
                        'concepts' => ['Awareness & Tracking'],
                    ],
                    [
                        'question'   => 'Match each positioning concept to its correct description.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Line of Sight'    => 'Terrain blocks spells from reaching their target',
                                'Kiting'           => 'Moving away from a melee while applying slows or roots',
                                'Overextension'    => 'Moving too far from safety, becoming vulnerable',
                                'Safe Positioning' => 'Staying near terrain and teammates to limit risk',
                            ],
                            'pairs' => [
                                'keys'   => ['Line of Sight', 'Kiting', 'Overextension', 'Safe Positioning'],
                                'values' => [
                                    'Moving away from a melee while applying slows or roots',
                                    'Moving too far from safety, becoming vulnerable',
                                    'Staying near terrain and teammates to limit risk',
                                    'Terrain blocks spells from reaching their target',
                                ],
                            ],
                        ],
                        'concepts' => ['Positioning'],
                    ],
                    [
                        'question'   => 'Why should a healer position behind a pillar when the enemy team is applying heavy pressure?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'It breaks line of sight with attackers, forcing them to reposition and buying time to heal and survive',
                            'options' => [
                                'It breaks line of sight with attackers, forcing them to reposition and buying time to heal and survive',
                                'Pillars deal damage to enemies who attempt to walk around them',
                                'Pillars provide a passive healing bonus to players standing behind them',
                                'Enemies cannot enter the area immediately behind a pillar',
                            ],
                        ],
                        'concepts' => ['Positioning'],
                    ],
                    [
                        'question'   => 'The enemy melee DPS is closing on your ranged DPS partner who has no escape available. What does good positioning allow your ranged DPS to do?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Use kiting techniques — applying slows and roots while using terrain to maintain distance',
                            'options' => [
                                'Use kiting techniques — applying slows and roots while using terrain to maintain distance',
                                'Stand still and deal maximum damage before dying',
                                'Run directly to the healer so the melee must fight through two players',
                                'Move into melee range to negate the attacker\'s charge ability',
                            ],
                        ],
                        'concepts' => ['Positioning', 'Awareness & Tracking'],
                    ],
                    [
                        'question'   => 'Place the following positioning steps in the correct order when transitioning from offense to defense mid-fight.',
                        'type'       => 'ordering',
                        'difficulty' => 'medium',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Recognise that the enemy team has gained momentum and your team is now under pressure',
                                'Move toward the nearest pillar to break line of sight with attackers',
                                'Regroup with your healer to ensure they can reach you safely',
                                'Wait for enemy pressure to ease before repositioning for the next offensive',
                            ],
                        ],
                        'concepts' => ['Positioning', 'Awareness & Tracking', 'Role Fundamentals'],
                    ],
                    [
                        'question'   => 'Your healer is caught in the open with no pillar nearby and the enemy is using offensive cooldowns. What positional mistake occurred and what should have been done differently?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'The healer overextended — they should have stayed within reach of a pillar at all times to ensure an escape route',
                            'options' => [
                                'The healer overextended — they should have stayed within reach of a pillar at all times to ensure an escape route',
                                'The healer used their defensive too early — timing was the real mistake',
                                'The DPS players should have stayed closer to share incoming damage',
                                'The healer positioned too close to enemies, which causes increased damage taken',
                            ],
                        ],
                        'concepts' => ['Positioning'],
                    ],
                    [
                        'question'   => 'The enemy DPS just overextended past a pillar, separating themselves from their healer. Your team has offensive cooldowns ready. What is the correct response?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Apply CC and commit offensive cooldowns immediately — the overextension is a free kill window with the target cut off from heals',
                            'options' => [
                                'Apply CC and commit offensive cooldowns immediately — the overextension is a free kill window with the target cut off from heals',
                                'Ignore the overextended target and focus the enemy healer instead',
                                'Wait to confirm the overextension was unintentional before committing',
                                'Use a defensive cooldown to bait them further from their healer',
                            ],
                        ],
                        'concepts' => ['Positioning', 'Crowd Control', 'Awareness & Tracking'],
                    ],
                ],
            ],

            // =================================================================
            // MODULE 5: Team Composition Fundamentals
            // 12 questions: 6 easy · 4 medium · 2 hard
            // =================================================================
            [
                'module_name' => 'Team Composition Fundamentals',
                'questions'   => [
                    [
                        'question'   => 'What does having "synergy" in a team composition mean at a fundamental level?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'Each teammate\'s tools reinforce each other — for example, one player\'s CC sets up another player\'s burst window',
                            'options' => [
                                'Each teammate\'s tools reinforce each other — for example, one player\'s CC sets up another player\'s burst window',
                                'Every teammate plays the exact same role so the team is easy to coordinate',
                                'The comp counters whatever the enemy team queues into',
                                'Every teammate has an identical set of crowd control abilities',
                            ],
                        ],
                        'concepts' => ['Team Composition'],
                    ],
                    [
                        'question'   => 'Diminishing Returns (DR) for crowd control is tracked per what?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'CC category (e.g. stuns, fears, silences), applied to a specific target',
                            'options' => [
                                'CC category (e.g. stuns, fears, silences), applied to a specific target',
                                'Each individual ability button, regardless of category',
                                'The team as a whole, shared across every target',
                                'The match timer, resetting at fixed intervals',
                            ],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'True or False: Two different CC categories applied to the same target do not share Diminishing Returns with each other.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'Why does using two CC effects from the same DR category back-to-back on one target waste value?',
                        'type'       => 'mcq',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => 'The second application lands at a reduced duration because DR is shared within that category',
                            'options' => [
                                'The second application lands at a reduced duration because DR is shared within that category',
                                'The second application fails to land entirely',
                                'The target becomes permanently immune to all crowd control',
                                'It has no downside — value is never wasted by reusing a category',
                            ],
                        ],
                        'concepts' => ['Crowd Control'],
                    ],
                    [
                        'question'   => 'True or False: Team composition, at the fundamental level covered here, is primarily about which comp to draft or queue against a specific enemy comp.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => false],
                        'concepts' => ['Team Composition'],
                    ],
                    [
                        'question'   => 'True or False: Peel priority should be decided in advance as a team-level agreement, rather than left to whichever player notices danger first.',
                        'type'       => 'true_false',
                        'difficulty' => 'easy',
                        'skill_type' => 'recall',
                        'answer'     => ['correct' => true],
                        'concepts' => ['Team Composition'],
                    ],
                    [
                        'question'   => 'Match each team composition concept to its correct definition.',
                        'type'       => 'matching_pairs',
                        'difficulty' => 'medium',
                        'skill_type' => 'recall',
                        'answer'     => [
                            'correct' => [
                                'Synergy'              => 'Teammates\' tools reinforce each other to create or capitalise on openings',
                                'DR Category'          => 'The grouping that Diminishing Returns is tracked within',
                                'Kill Target Selection' => 'Choosing who to commit damage to based on what the comp is built to punish',
                                'Peel Priority'        => 'A team-level agreement on which teammate is protected first',
                            ],
                            'pairs' => [
                                'keys'   => ['Synergy', 'DR Category', 'Kill Target Selection', 'Peel Priority'],
                                'values' => [
                                    'The grouping that Diminishing Returns is tracked within',
                                    'A team-level agreement on which teammate is protected first',
                                    'Teammates\' tools reinforce each other to create or capitalise on openings',
                                    'Choosing who to commit damage to based on what the comp is built to punish',
                                ],
                            ],
                        ],
                        'concepts' => ['Team Composition', 'Crowd Control'],
                    ],
                    [
                        'question'   => 'Two teammates each have a stun available (same DR category) heading into a kill attempt on the same target. What is the more effective way to use them?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'Use one stun to open the window, and hold the second until the first expires — or use a different CC category instead',
                            'options' => [
                                'Use one stun to open the window, and hold the second until the first expires — or use a different CC category instead',
                                'Use both stuns at the exact same moment for maximum initial disable',
                                'Save both stuns entirely and rely on damage alone for the kill window',
                                'Use one stun and never use the second one at all, regardless of the fight',
                            ],
                        ],
                        'concepts' => ['Team Composition', 'Crowd Control'],
                    ],
                    [
                        'question'   => 'A comp is built around a short, heavy burst window rather than sustained damage. What does this imply about kill target selection?',
                        'type'       => 'mcq',
                        'difficulty' => 'medium',
                        'skill_type' => 'analysis',
                        'answer'     => [
                            'correct' => 'The comp should target whoever has the least defensive support available during that window, since its strength is a short burst rather than a long grind',
                            'options' => [
                                'The comp should target whoever has the least defensive support available during that window, since its strength is a short burst rather than a long grind',
                                'The comp should always target the enemy healer regardless of the comp\'s own strengths',
                                'The comp should switch to a sustained-pressure approach instead of using its burst window',
                                'Target selection does not matter for burst-oriented compositions',
                            ],
                        ],
                        'concepts' => ['Team Composition'],
                    ],
                    [
                        'question'   => 'Place the following steps of a coordinated CC chain across two teammates in the correct order.',
                        'type'       => 'ordering',
                        'difficulty' => 'medium',
                        'skill_type' => 'application',
                        'answer'     => [
                            'steps' => [
                                'Confirm the two teammates are holding different CC categories before committing to the kill attempt',
                                'Teammate A applies a hard CC (e.g. a stun) to open the kill window on the target',
                                'Teammate B holds their different CC category in reserve rather than duplicating teammate A\'s category',
                                'As teammate A\'s CC expires, teammate B applies their CC to extend the disable without triggering DR',
                                'The team continues stacking damage on the kill target throughout the extended window',
                            ],
                        ],
                        'concepts' => ['Team Composition', 'Crowd Control'],
                    ],
                    [
                        'question'   => 'Your team is committing a kill attempt on the enemy healer with two different-category CC effects available among your teammates. You also expect the enemy DPS to try to burst your own healer moments later. What is the best plan?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'Use one CC effect to secure the current kill window, and hold the other in reserve as a peel option for your own healer',
                            'options' => [
                                'Use one CC effect to secure the current kill window, and hold the other in reserve as a peel option for your own healer',
                                'Use both CC effects immediately on the enemy healer since more CC always means a safer kill',
                                'Hold both CC effects in reserve and rely on damage alone for the kill window',
                                'Use both CC effects on your own healer preemptively before any pressure begins',
                            ],
                        ],
                        'concepts' => ['Team Composition', 'Crowd Control'],
                    ],
                    [
                        'question'   => 'A comp built around sustained pressure over a long fight is deciding which enemy to commit to killing. Which target selection best fits this comp\'s strengths?',
                        'type'       => 'mcq',
                        'difficulty' => 'hard',
                        'skill_type' => 'application',
                        'answer'     => [
                            'correct' => 'The enemy whose team cannot out-heal or out-sustain the pressure over time, even if they aren\'t the easiest target early in the fight',
                            'options' => [
                                'The enemy whose team cannot out-heal or out-sustain the pressure over time, even if they aren\'t the easiest target early in the fight',
                                'Whichever enemy currently has the lowest health, regardless of how the fight develops',
                                'The enemy who most recently used a defensive cooldown',
                                'Whichever enemy is standing in the most exposed position, regardless of comp strengths',
                            ],
                        ],
                        'concepts' => ['Team Composition'],
                    ],
                ],
            ],
        ];
    }
}
