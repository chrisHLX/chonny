<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModulePage;
use App\Models\Subject;
use App\Models\SubjectContextOption;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Canonical context module authored from a Gladiator-rated player's own dictation
 * (Feral Druid, Wildstalker build) — same "expertise-capture" pattern as
 * DiscPriestOracleModuleSeeder. Unlike that module, the cooldown numbers here were
 * not fact-checked after the fact against web guides — they were pulled directly from
 * data/spelldata/filtered/druid/{feral,hero-wildstalker,class-talents,baseline}.txt,
 * a SimulationCraft 1205-01 (WoW 12.0.7) spell dump split by data/spelldata/split-by-tree.php.
 * Several of the player's dictated numbers (Feral Frenzy, Skull Bash, Wild Charge,
 * Ursol's Vortex, Berserk) turned out to differ from the source data and were corrected
 * here — see the corresponding conversation for the full diff. The rotation logic and
 * matchup judgment calls are left exactly as dictated; only cooldown numbers were
 * sourced from the files.
 *
 * Recall questions are a separate, later authoring stage — deliberately not seeded
 * here, so the module stays unpublished until that stage happens.
 *
 * Depends on SubjectContextSeeder having created the Feral spec option under Druid —
 * runs after it in DatabaseSeeder, but degrades gracefully (warns, creates the module
 * untagged) if run standalone before that seeder.
 */
class FeralDruidWildstalkerModuleSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'World of Warcraft: The War Within')->first();

        if (! $subject) {
            $this->command?->warn('⚠️ WoW subject not found — skipping Feral Druid module.');
            return;
        }

        $module = Module::firstOrCreate(
            ['name' => 'Feral Druid (Wildstalker): Arena Cooldown Reference', 'subject_id' => $subject->id],
            [
                'description'    => 'Primary-source cooldown/rotation reference for Feral Druid (Wildstalker build) in Arena, dictated directly by a Gladiator-rated player. Cooldown numbers sourced from a SimulationCraft spell data dump; rotation and matchup judgment calls are the player\'s own.',
                'content_source' => 'Player dictation (Gladiator-rated, Feral Druid) + SimulationCraft spell data',
                'status'         => 'ready',
                'published'      => false,
                'created_by'     => User::where('email', User::SYSTEM_ENGINE_EMAIL)->value('id'),
            ]
        );

        $this->seedPages($module);
        $this->tagContext($subject, $module);

        $this->command?->info('✅ Feral Druid (Wildstalker) canonical module seeded.');
    }

    private function seedPages(Module $module): void
    {
        foreach ($this->pages() as $entry) {
            ModulePage::updateOrCreate(
                ['module_id' => $module->id, 'page_number' => $entry['page_number']],
                [
                    'title'      => $entry['title'],
                    'content'    => $entry['content'],
                    'created_by' => null,
                    'updated_by' => null,
                ]
            );
        }
    }

    private function tagContext(Subject $subject, Module $module): void
    {
        $feral = SubjectContextOption::where('name', 'Feral')
            ->whereHas('dimension', fn ($q) => $q->where('subject_id', $subject->id)->where('slug', 'spec'))
            ->first();

        if (! $feral) {
            $this->command?->warn('⚠️ Feral context option not found — run SubjectContextSeeder first. Module created untagged.');
            return;
        }

        $module->contextOptions()->syncWithoutDetaching([$feral->id]);
    }

    private function pages(): array
    {
        return [
            [
                'page_number' => 1,
                'title'       => 'Build/Path Identity',
                'content'     => <<<'MD'
# Build/Path Identity

Feral has two Hero Talent paths: **Wildstalker** and **Druid of the Claw**.

This module covers the **Wildstalker** path — a bleed-focused build centered on Bloodseeker Vines, which Rip and Rake have a chance to grow on the target. Most of the tree (Root Network, Twin Sprouts, Bursting Growth, Rampancy) spreads, scales, or detonates that one proc. Everything on the following pages assumes a Wildstalker build.
MD,
            ],
            [
                'page_number' => 2,
                'title'       => 'Core Cooldowns',
                'content'     => <<<'MD'
# Core Cooldowns

## Bear Form
No cooldown — a stance, stays active until cancelled. Gives +25% Stamina while active. Swapped into when incoming damage is heavy, paired with Frenzied Regeneration for the actual heal.

## Frenzied Regeneration
36 second cooldown (1 charge). Heals a percentage of max health over a 3 second duration. This is the actual defensive tool used from Bear Form.

## Barkskin
60 second cooldown. Reduces damage taken for 8 seconds.

## Survival Instincts
180 second cooldown (3 min), 1 charge. Reduces damage taken for its duration.

## Maim
30 second cooldown. Finishing move — damage and stun duration scale with combo points spent.

## Feral Frenzy
45 second cooldown, reduced to 30 seconds by Focused Frenzy (also increases its damage).

## Tiger's Fury
30 second cooldown. Instantly restores Energy and increases damage of all attacks for its duration.

## Skull Bash
15 second cooldown. The interrupt — silences the school it interrupts for a few seconds.

## Wild Charge
15 second cooldown. Gap closer.

## Ursol's Vortex
60 second cooldown. Roots enemies in the area, pulling them back to its center if they try to leave.

## Incapacitating Roar
30 second cooldown, 3 second duration. Incapacitates all nearby enemies; breaks on damage.

## Berserk
180 second cooldown (3 min), reduced to 120 seconds by Berserk: Heart of the Lion. 15 second duration. Increases damage and grants a Rake stun.

## Cyclone
No cooldown.
MD,
            ],
            [
                'page_number' => 3,
                'title'       => 'Rotation (Jungle)',
                'content'     => <<<'MD'
# Rotation (Jungle)

The condensed list of the main cooldowns to track:

**Tiger's Fury, Feral Frenzy, Maim, Cyclone, Skull Bash.**

## Rotation

1. Start with a stun on the kill target when the hunter traps the healer.
2. Tiger's Fury into Rake into Starfire — extra combo points, a mini burst window while the DPS target is stunned.
3. Feral Frenzy into Maim to close it out.
4. Cyclone can also be used to take the DPS target out, not just the healer.
5. From there it's countering CC, kicking CC, and lining up the next Maim with a trap or Cyclone taking the DPS off the game.
MD,
            ],
            [
                'page_number' => 4,
                'title'       => 'Source Context',
                'content'     => <<<'MD'
# Source Context

Gladiator rank achieved playing Feral in Shadowlands Season 2 (2600 rating), primarily in Jungle (3v3 with a Hunter). Current rating is around 2k, with the player reporting difficulty specifically in Solo Shuffle — the rotation and matchup notes above reflect Jungle experience and may not transfer directly to other formats.
MD,
            ],
        ];
    }
}
