<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\ModulePage;

class ModulePageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = $this->pages();

        $created = 0;
        $skipped = 0;

        foreach ($pages as $entry) {
            $module = Module::where('name', $entry['module_name'])->first();

            if (! $module) {
                $this->command->warn("⚠️ Module not found: {$entry['module_name']}");
                $skipped++;
                continue;
            }

            $exists = ModulePage::where('module_id', $module->id)
                ->where('page_number', $entry['page_number'])
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            ModulePage::create([
                'module_id'   => $module->id,
                'page_number' => $entry['page_number'],
                'title'       => $entry['title'],
                'content'     => $entry['content'],
                'created_by'  => null,
                'updated_by'  => null,
            ]);

            $created++;
        }

        $this->command->info("✅ Module pages seeded! Created: {$created}, Skipped: {$skipped}");
    }

    private function pages(): array
    {
        return [

            // =====================================================================
            // ZERG BASICS
            // =====================================================================
            [
                'module_name' => 'Zerg Basics',
                'page_number' => 1,
                'title'       => 'Zerg Basics — Introduction',
                'content'     => <<<'MD'
# Zerg Basics

## Overview of the Zerg Race

Zerg is StarCraft II's swarm-based race, built around speed, numbers, and adaptability. Unlike Terran and Protoss, Zerg produces all units from a single structure — the Hatchery — using a resource called Larva. Mastering Zerg means keeping a constant flow of Drones and units while reacting quickly to your opponent.

## The Hatchery, Larva, and Drones

The **Hatchery** is the heart of your base. It naturally produces up to 3 Larva at a time. Larva can morph into any basic Zerg unit — Drones, Zerglings, Roaches, Overlords, and more.

**Drones** are your workers. They cost 50 minerals, take 12 seconds to produce, and use 1 supply. Every Drone working a mineral patch earns income — producing Drones consistently in the early game is one of the most important habits to build. The optimal number of Drones per mineral patch is 3 (triple saturation), and 3 Drones per Extractor. A Drone consumes itself to begin constructing a building, so building too many structures too early hurts your economy.

## Queens

The **Queen** is your most important support unit early on. She has three key abilities:

- **Inject Larva** (25 energy): Injects a Hatchery to produce 4 extra Larva after a 40-second timer. Missing injections is one of the biggest mistakes a beginner Zerg player can make.
- **Creep Tumor** (25 energy): Places a Creep Tumor that slowly spreads Creep outward. Zerg ground units move 30% faster on Creep.
- **Transfuse**: Heals a friendly unit or building for 125 HP. Useful for keeping Queens or key units alive under pressure.

Produce at least one Queen per Hatchery. Queens require a Spawning Pool.

## Supply: Overlords

**Overlords** provide supply for the Zerg army — 8 supply per Overlord. Your starting Hatchery gives you 6 supply; your first Overlord brings that to 14. As your army grows you must morph additional Overlords to avoid supply blocks. Overlords also provide default vision and can be flown toward the opponent's base early for scouting.

## Core Structures

| Structure | Purpose |
|---|---|
| **Hatchery** | Produces Larva for unit production; upgrades to Lair |
| **Spawning Pool** | Required for Zerglings and Queens |
| **Extractor** | Collects Vespene gas; costs 25 minerals |
| **Evolution Chamber** | Researches ground unit attack and armour upgrades |
| **Baneling Nest** | Allows Zerglings to morph into Banelings |
| **Roach Warren** | Unlocks Roach production |
| **Spire** | Required for Mutalisk and Corruptor production |
| **Infestation Pit** | Required to upgrade Lair to Hive |

The tech progression for Zerg is: **Hatchery → Lair → Hive**. The Lair unlocks mid-game upgrades and units; the Hive unlocks end-game tech.
MD,
            ],

            [
                'module_name' => 'Zerg Basics',
                'page_number' => 2,
                'title'       => 'Units, Economy, and Map Control',
                'content'     => <<<'MD'
# Units, Economy, and Map Control

## Core Zerg Units

### Zergling
- **Cost**: 50 minerals (produces 2 per Larva)
- **Supply**: 0.5 each
- **Requires**: Spawning Pool
- **Role**: Fast, cheap attacker. Excellent for early scouting, economic harassment, and overwhelming lightly defended positions.
- **Key upgrade**: Metabolic Boost — increases movement speed significantly. Almost always researched in the early game.

### Baneling
- **Morphs from**: Zergling (costs an additional 25 minerals + 25 gas)
- **Requires**: Baneling Nest must be built before Zerglings can morph
- **Role**: Suicide unit that explodes on impact. Devastating against Terran bio clumps (Marine/Marauder groups), structures, and worker lines.

### Roach
- **Cost**: 75 minerals, 25 gas — **Supply**: 2
- **Requires**: Roach Warren
- **Role**: Durable, efficient frontline unit. With the Tunneling Claws upgrade, Roaches regenerate health rapidly while burrowed.

### Hydralisk
- **Cost**: 100 minerals, 50 gas — **Supply**: 2
- **Requires**: Hydralisk Den (which requires a Lair)
- **Role**: Versatile ranged unit effective against both ground and air. Strong in large numbers.

### Mutalisk
- **Cost**: 100 minerals, 100 gas — **Supply**: 2
- **Requires**: Spire
- **Role**: Fast flying harasser. Its attack bounces to hit up to 3 targets. Excellent for denying expansions and forcing opponents to react to multiple threats.

### Queen (again, as a combat unit)
While the Queen is primarily used for Inject and Creep, she is also a powerful defensive unit. Queens can attack both ground and air. In the early game they are the backbone of Zerg anti-air defence at home.

## Economy: Expanding and Drone Production

**Expanding** means building a new Hatchery at an unoccupied base location. The closest unoccupied base to your main is called the "natural" expansion. Expanding early gives you more mineral income and more Larva, letting you scale your army much faster.

Key economy rules:
- Never float more than 400–500 minerals without spending them
- Keep Drone production continuous until you have roughly 16 Drones per base (2 Drones per mineral patch is "saturated enough" for early stages)
- Inject every Hatchery whenever your Queen has 25+ energy — this is the single highest-impact mechanic for Zerg early-game performance

## Creep and Map Control

Creep is a purple biomass that spreads from Hatcheries and Creep Tumors. All Zerg ground units move 30% faster on Creep, giving a massive defensive and offensive advantage on your side of the map.

Key facts about Creep:
- Creep recedes when the Hatchery or Creep Tumor that generated it is destroyed
- Queens place the initial Creep Tumors (costs 25 energy); existing Tumors can plant additional Tumors, creating a spreading chain toward the opponent's base
- Spreading Creep toward the middle of the map gives you vision of incoming attacks and increases your army's effective speed in counter-attacks

## Scouting

Knowing what your opponent is doing is critical. Common Zerg scouting tools:

- **Drone scout**: Sends a Drone to the opponent's base to see their opening build. Sacrifice it once you have the information you need.
- **Overlord positioning**: Flying Overlords around the map reveals army movement and base layout. Opponent anti-air can kill them, so don't fly into defended areas carelessly.
- **Zergling run-by**: Sending Zerglings around the perimeter of the opponent's base reveals expansion timing, army size, and base layout.
- **Creep spread**: Creep Tumors near key map areas give vision of attack paths and incoming threats.

## Basic Build Order: 12 Pool

A reliable beginner build to learn core fundamentals:

1. Produce Drones to 12 supply
2. Build Spawning Pool
3. Build Overlord at 13 supply
4. When Pool finishes: build Queen, train Zerglings
5. Take natural expansion Hatchery
6. Continue Drone and unit production

This build balances economic growth with early defence. Once you're comfortable with it, start focusing on not missing Hatchery injections and keeping your mineral count low through continuous spending.
MD,
            ],

            // =====================================================================
            // TERRAN TIMING ATTACKS
            // =====================================================================
            [
                'module_name' => 'Terran Timing Attacks',
                'page_number' => 1,
                'title'       => 'Terran Bio Fundamentals',
                'content'     => <<<'MD'
# Terran Bio Fundamentals

## Overview

Terran is StarCraft II's mechanical, reactive race. This module focuses on **timing attacks** — pushes designed to hit the opponent at a specific moment before they can field a superior army. Terran timing attacks revolve around the **bio** composition: Marines, Marauders, and Medivacs, backed by the Stimpack and Combat Shield upgrades.

## Core Bio Units

### Marine
- **Cost**: 50 minerals — **Supply**: 1 — **Requires**: Barracks
- **Role**: The backbone of Terran bio. Exceptional damage-per-cost, but low HP. Marines are most effective in large numbers and with Stimpack active.
- **Key upgrades**:
  - **Stimpack**: Increases attack speed and movement speed for 10 HP per use. Researched at a Barracks Tech Lab.
  - **Combat Shield**: Adds 10 HP to every Marine permanently. Drastically improves survivability against splash damage.

### Marauder
- **Cost**: 100 minerals, 25 gas — **Supply**: 2 — **Requires**: Barracks + Tech Lab
- **Role**: Durable frontline unit. Slows armoured targets with Concussive Shells. Acts as a shield for Marines in bio pushes. Stimpack also benefits Marauders.
- **Key upgrade**: **Concussive Shells** — slows armoured units, preventing kiting and enabling easier kills.

### Medivac
- **Cost**: 100 minerals, 100 gas — **Supply**: 2 — **Requires**: Starport
- **Role**: Heals bio units in combat (27 HP/sec), dramatically extending their survivability. Also used as a transport for drops into the enemy base.
- **Ability**: **Ignite Afterburners** — a speed burst used to escape danger or close distance quickly.

## Barracks Add-ons

A Barracks can attach one of two add-ons:

| Add-on | Benefit |
|---|---|
| **Tech Lab** | Unlocks Marauder production; required to research Stimpack, Combat Shield, and Concussive Shells |
| **Reactor** | Allows the Barracks to produce two units simultaneously — doubles Marine production rate |

A standard bio setup is one Barracks with a Tech Lab (for Stimpack) and additional Barracks with Reactors (for high Marine output).

## The Orbital Command

The Orbital Command is upgraded from the Command Center and is Terran's most important economic tool.

- **Call Down: MULE** (50 energy): Drops a mining robot that harvests minerals at roughly 4× the rate of an SCV for 64 seconds. Always spend your energy on MULEs — never let energy sit at 200.
- **Scanner Sweep** (50 energy): Reveals a chosen map area, including cloaked and burrowed units. Use sparingly, as MULE income almost always takes priority.

## Supply Depot

Supply Depots provide 8 supply each. Critically, they can be **lowered underground**, allowing friendly units to walk over them. This is used to wall off your ramp — a wall of Depots and a Barracks blocks all ground units, and lowering one Depot creates a gap you can control. Terran players also use raised Depots to block enemy units from entering the base during an attack.
MD,
            ],

            [
                'module_name' => 'Terran Timing Attacks',
                'page_number' => 2,
                'title'       => 'Builds, Timings, and Execution',
                'content'     => <<<'MD'
# Builds, Timings, and Execution

## What is a Timing Attack?

A timing attack is a push designed to hit the opponent **before** they can respond with a stronger army, better tech, or more units. Every game has windows — moments where one player is temporarily more powerful than the other. Terran timing attacks exploit those windows precisely.

The timing attack does not have to kill the opponent. Forcing them to pull workers, cancel expansions, or divert units away from the front is often enough to create a decisive advantage.

## The Reaper

- **Cost**: 50 minerals, 50 gas — **Supply**: 1 — **Requires**: Barracks + Tech Lab
- **Role**: The best early harassment and scouting unit in the game. The Reaper can **jump up and down cliffs** using its jet pack, reaching locations other units cannot.
- Reaper HP regenerates passively over time — it is difficult to kill unless the opponent commits hard to chasing it.
- The Reaper is never the kill shot. Its job is to damage workers, force defensive spending, and gather information about the opponent's build.

## The Reaper Expand Build

The **Reaper Expand** is one of Terran's most common and versatile openings. It combines early harassment and scouting with safe economic development.

**Build order:**
1. Build Supply Depot (at 14 supply)
2. Build Barracks (at 15 supply)
3. Build Refinery
4. Build Tech Lab on Barracks → Produce Reaper
5. Float Reaper to opponent's base — harass workers, identify opening build
6. Build Command Center (float it to natural when ready)
7. Build Orbital Command on main, lower the natural Command Center
8. Build Reactor on Barracks → start Marine production
9. Continue SCV and unit production

The Reaper gives information that shapes your next decision: if the opponent is going for an early rush, you adjust; if they are playing greedy, you apply more pressure.

## The 2-1-1 Build

The **2-1-1** describes the production buildings: **2 Barracks, 1 Factory, 1 Starport**.

This is the backbone of most Terran bio timings. The goal is to produce Marines rapidly from two Barracks while the Factory and Starport provide the Medivac for a drop or timing push.

**Approximate production structure:**
- Barracks 1 + Tech Lab (Stimpack and Combat Shield research)
- Barracks 2 + Reactor (double Marine production)
- Factory (used primarily as a gateway to unlock the Starport — produces 1 Hellion or Reaper, then lifts off)
- Starport → produces Medivac

The first timing attack typically arrives at **6:00–7:00**, timed around when Stimpack finishes.

## Stimpack Timing Push (~7:00)

This is Terran's most iconic early-game timing. When Stimpack research completes, your bio army's combat effectiveness roughly doubles. Hitting the opponent in this window — before they have enough units or tech to respond — is the goal.

**What you bring**: 8–12 Marines, 2–4 Marauders, 1–2 Medivacs
**Target**: Natural expansion or main ramp
**Goal**: Force significant economic damage, kill the natural expansion, or close out the game

Use Stimpack proactively in the fight, not reactively. Medivac healing offsets the health cost. Spread Marines slightly to reduce splash damage.

## Marine-Medivac Drop (~5:30–6:30)

Before or alongside the ground push, a **Medivac loaded with Marines** dropped into the opponent's mineral line creates chaos and forces a response. Workers are fragile — even 4 Marines in a worker line can kill many workers before the opponent can react.

The drop is a distraction and an economy hit. It forces the opponent to choose between defending the drop and holding the main push.

**Execution tips:**
- Load 4–8 Marines into the Medivac
- Drop into the mineral line (not the middle of the base)
- If uncontested, attack workers — do not attack buildings
- Boost (Ignite Afterburners) away when anti-air arrives

## Build Order Summary

**Standard 2-1-1 into Stimpack timing:**

1. Build Supply Depot (14)
2. Build Barracks (15)
3. Build Refinery (16)
4. Build Orbital Command when Barracks finishes
5. Build Tech Lab on Barracks → start Stimpack research
6. Build second Barracks with Reactor
7. Build Factory → build Starport → build Medivac
8. Attack when Stimpack finishes (~7:00) with Marines, Marauders, and Medivac support

Keep producing Marines throughout. Never let your Barracks sit idle. Call down a MULE every time your Orbital has 50 energy.
MD,
            ],

            // =====================================================================
            // BASIC PVP TIPS (WoW)
            // =====================================================================
            [
                'module_name' => 'Basic PvP Tips',
                'page_number' => 1,
                'title'       => 'Introduction to WoW PvP',
                'content'     => <<<'MD'
# Introduction to WoW PvP

## What is PvP in World of Warcraft?

Player versus Player (PvP) combat in World of Warcraft involves fighting other real players instead of AI enemies. PvP in The War Within takes place in several formats:

- **Battlegrounds**: Large-scale team fights (10v10 or larger) with objectives like flag captures or resource control.
- **Arena**: Small team formats (2v2 or 3v3) focused on eliminating the opposing team. This is the most competitive format.
- **War Mode**: Open-world PvP where players flag themselves for combat in the open world.
- **Skirmishes**: Unrated arena matches used for practice.

This module covers the foundational skills that apply across all formats.

## The Three Roles in PvP

Every class and specialisation in WoW fits into one of three roles. Understanding your role — and your teammates' roles — is the first step to playing PvP effectively.

### Tank
Tanks absorb damage and disrupt the enemy team. In PvP, Tanks are less common in arena but extremely valuable in battlegrounds. They create pressure by forcing enemies to deal with them, and they peel threats away from teammates with crowd control and taunts.

### Healer
The Healer keeps teammates alive by restoring health and removing harmful debuffs. In arena, the Healer is almost always the primary kill target — because if the Healer dies, the team loses. Healers must also manage their mana carefully, especially in long fights, and use crowd control to create space when pressured.

### DPS (Damage Dealer)
DPS specs exist to kill the enemy as fast as possible. DPS players apply pressure, set up crowd control chains, and execute the kill when a window opens. DPS players in arena must coordinate with their healer and teammates — random individual pressure rarely wins matches.

## Crowd Control (CC)

Crowd control is one of the most important mechanics in WoW PvP. It refers to abilities that restrict what an enemy player can do. The main types:

| CC Type | Effect |
|---|---|
| **Stun** | Prevents all actions (movement, attacks, spells). The most powerful CC type. |
| **Root** | Prevents movement but the target can still attack, cast spells, and use abilities. |
| **Silence** | Prevents spellcasting only. The target can still move and use physical abilities. |
| **Slow/Snare** | Reduces movement speed. The target retains full ability use. |
| **Fear** | Causes the target to run in a random direction, losing control temporarily. |
| **Incapacitate** | Similar to a stun but breaks on damage. Examples: Polymorph, Freezing Trap. |

### Diminishing Returns (DR)

When the same type of CC is applied to the same target repeatedly in a short window, **Diminishing Returns** reduces the duration of each application:

1. First application: full duration
2. Second application: 50% duration
3. Third application: 25% duration
4. Fourth application: immune for 18 seconds

DR is why CC chains must be timed carefully. Wasting a stun on a target who is already DR'd is one of the most common mistakes in beginner arena.

## The PvP Trinket

Every character should equip their **PvP Trinket** (available from the PvP vendor). It has one purpose: **breaking one crowd control effect**, specifically loss-of-control effects like stuns. It has a 2-minute cooldown.

Knowing when to use your trinket — and when to hold it — is a fundamental skill. In arena, using your trinket on an incapacitate (which breaks on damage anyway) and then getting stunned immediately after is a critical mistake.
MD,
            ],

            [
                'module_name' => 'Basic PvP Tips',
                'page_number' => 2,
                'title'       => 'Positioning, Cooldowns, and Targeting',
                'content'     => <<<'MD'
# Positioning, Cooldowns, and Targeting

## Positioning and Line of Sight

**Line of Sight (LoS)** is one of the most important mechanics in WoW PvP. When a wall, pillar, or terrain feature is between you and an enemy, most targeted spells and abilities cannot reach you. Breaking LoS interrupts an enemy's cast.

In arena, both teams use pillars to fight on different sides and force enemies to move out of position. This is called **pillar play** or **pillar humping** (in casual terms). Healers especially rely on LoS to avoid being interrupted or burst down while casting.

### Kiting
**Kiting** is moving away from a melee attacker while still dealing damage or waiting for help. It involves:
- Using slows and roots to keep the enemy at a distance
- Moving in the direction away from the attacker
- Using terrain to break LoS and reset the enemy's position

### Peeling
**Peeling** means using CC or abilities to remove enemies who are attacking your teammate. If an enemy DPS is training your healer, peeling means stunning, rooting, or slowing that enemy to relieve pressure. This is one of the most important skills a DPS player can develop.

## Cooldown Management

Every class in WoW has **offensive** and **defensive** cooldowns — powerful abilities with long reset timers that define the flow of a fight.

**Offensive cooldowns** significantly increase damage output for a short duration. They should be used to create a kill window — a moment when the enemy healer cannot keep up with your burst.

**Defensive cooldowns** significantly increase survivability — reduced damage taken, healing received, or immunity effects. They should be held for moments of high incoming pressure, not burned as soon as they are available.

A common beginner mistake is using defensive cooldowns too early (before the enemy has committed their offensive cooldowns), leaving you with nothing when the real burst comes.

### The Trinket Decision
Your PvP Trinket breaks one CC effect. In a 3v3 arena, your opponents may try to "fake trinket" — using a less important CC on you to bait your trinket before their real setup. Recognising this and holding your trinket is a mark of a more experienced PvP player.

## Target Switching and Priority Targets

**Switching targets** means your team changes which enemy player they are attacking. This is a core mechanic in both arena and battlegrounds.

### When to Switch
- When the current target uses a major defensive cooldown (e.g., bubble, Barkskin, Iceblock)
- When the current target is out of range or behind LoS
- When a different target becomes vulnerable (low HP, no cooldowns available)
- When your team's CC setup is better suited to a different target

### Target Priority
In most arena situations, the priority order is:
1. **Healer** (highest priority — winning goal in most comps)
2. **The squishiest DPS** (if the healer is well-protected)
3. **The most dangerous DPS** (to reduce incoming damage to your team)

Communicate target switches with your teammates — "switching to the Mage" or "training the Priest" keeps your team coordinated.

## Awareness and Tracking

Good PvP players track what the enemy has used:
- Which CCs have been used (and what is on DR)
- Which defensive cooldowns are available or on cooldown
- The enemy healer's mana level (especially in longer fights)
- Who currently has the enemy team's attention

This information helps you decide when to apply pressure, when to save cooldowns, and when to go for a kill.

Over time, tracking enemy ability usage becomes instinctive. For now, focus on tracking one thing at a time: start with enemy trinket usage (did they use it already?) and build from there.

## Team Composition Basics

In arena, the combination of classes and specialisations you bring is called your **composition** (or "comp"). Different comps have different strengths:

- **Burst comps**: Stack offensive cooldowns and kill targets quickly before the enemy healer can respond.
- **Sustain/attrition comps**: Apply constant pressure and outlast the enemy over a long fight.
- **CC-heavy comps**: Chain crowd control to lock the healer out of the fight while killing a target.

As a beginner, focus less on optimising your comp and more on learning how your class works in a PvP environment. Understanding your own toolkit is the foundation for everything else.
MD,
            ],

        ];
    }
}
