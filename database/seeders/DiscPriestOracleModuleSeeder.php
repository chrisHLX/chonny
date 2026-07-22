<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModulePage;
use App\Models\Subject;
use App\Models\SubjectContextOption;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Canonical context module authored directly from a Gladiator-rated player's own
 * dictation (Discipline Priest, Oracle build) — organised/clarified, not AI-generated
 * or cross-checked against guides. First pilot of the "expertise-capture" pattern
 * described in VISION.md and the "Canonical Context Module Template" section of
 * CLAUDE.md. Recall questions are a separate, later authoring stage — deliberately
 * not seeded here, so the module stays unpublished until that stage happens.
 *
 * Depends on SubjectContextSeeder having created the Discipline spec option under
 * Priest — runs after it in DatabaseSeeder, but degrades gracefully (warns, creates
 * the module untagged) if run standalone before that seeder.
 */
class DiscPriestOracleModuleSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'World of Warcraft: The War Within')->first();

        if (! $subject) {
            $this->command?->warn('⚠️ WoW subject not found — skipping Disc Priest module.');
            return;
        }

        $module = Module::firstOrCreate(
            ['name' => 'Discipline Priest (Oracle): Arena Cooldown Reference', 'subject_id' => $subject->id],
            [
                'description'    => 'Primary-source cooldown/rotation reference for Discipline Priest (Oracle build) in Arena, dictated directly by a Gladiator-rated player. Organised and clarified only — not AI-generated, not verified against guides.',
                'content_source' => 'Player dictation (Gladiator-rated, Discipline Priest)',
                'status'         => 'ready',
                'published'      => false,
                'created_by'     => User::where('email', User::SYSTEM_ENGINE_EMAIL)->value('id'),
            ]
        );

        $this->seedPages($module);
        $this->tagContext($subject, $module);

        $this->command?->info('✅ Discipline Priest (Oracle) canonical module seeded.');
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
        $discipline = SubjectContextOption::where('name', 'Discipline')
            ->whereHas('dimension', fn ($q) => $q->where('subject_id', $subject->id)->where('slug', 'spec'))
            ->first();

        if (! $discipline) {
            $this->command?->warn('⚠️ Discipline context option not found — run SubjectContextSeeder first. Module created untagged.');
            return;
        }

        $module->contextOptions()->syncWithoutDetaching([$discipline->id]);
    }

    private function pages(): array
    {
        return [
            [
                'page_number' => 1,
                'title'       => 'Build/Path Identity',
                'content'     => <<<'MD'
# Build/Path Identity

Discipline has two Hero Talent paths: **Oracle** and **Void Weaver**.

This module covers the **Oracle** path only — Void Weaver isn't detailed here. Everything on the following pages assumes an Oracle build.
MD,
            ],
            [
                'page_number' => 2,
                'title'       => 'Core Cooldowns',
                'content'     => <<<'MD'
# Core Cooldowns

## Evangelism
90 second cooldown, reduced to 45 seconds by Ultimate Radiance.

Casting it instantly casts a free Power Word: Radiance and makes your next 2 casts of Radiance instant, spreading Atonement across the team in one global. Each Radiance cast also procs Harsh Discipline, giving your next Penance 3 additional bolts — stacking up to 2 charges, worth 6 extra Penance bolts total. This is the core of the burst healing window, which is why Evangelism is timed together with Mind Blast (see below): the empowered Penance from Dark Indulgence lands inside the same window as the extra Harsh Discipline bolts, instead of the two burst effects landing separately.

Purpose: quickly tops the team's health back up.

## Pain Suppression
2 charges. Protector of the Frail reduces its cooldown by 3 seconds every time you cast Power Word: Shield — the more you've been shielding beforehand, the sooner it's back up.

## Fade
20 second cooldown. Avoids incoming CC.

## Shadow Word: Death
10 second cooldown. Instant-cast. Used defensively, slotted in after Fade in the CC-avoidance sequence.

## Desperate Prayer
90 second cooldown, reduced to 70 seconds by Angel's Mercy. A defensive tool for when you're the one being focused.

## Angelic Bulwark
Not a player-activated cooldown — it's a reactive proc. When an attack brings you below 30% health, you gain a shield worth 25% of your max health for 20 seconds. Can't trigger more than once every 90 seconds, so it's a safety net you can lean on once per fight, not on demand.

## Fear
Psychic Scream's baseline cooldown is 40 seconds; Psychic Voice reduces it by 10 seconds, down to 30.

## Mind Blast
30 second cooldown. Dark Indulgence gives Mind Blast +20% damage and a guaranteed Power of the Darkside proc, which empowers your next Penance by 30%. Cast together with Evangelism so the empowered Penance and the extra Harsh Discipline bolts land in the same burst window.
MD,
            ],
            [
                'page_number' => 3,
                'title'       => 'Priority Cooldowns',
                'content'     => <<<'MD'
# Priority Cooldowns

The condensed list of the main cooldowns to track, distinct from the full inventory on the Core Cooldowns page:

**Evangelism, Pain Suppression, Fade, Fear, Trinket.**

Pain Suppression can be used while stunned.

## Rotation

1. Pain Suppression through an incoming stun.
2. Evangelism to burst-heal out of the resulting damage.
3. Trinket to break the next major CC.
4. Fade or Shadow Word: Death for the CC after that.
5. Fear held in reserve for moments where overextending is dangerous — e.g. fearing a hunter as they attempt to trap, specifically when it isn't safe to close the distance to peel for the healer instead.
MD,
            ],
            [
                'page_number' => 4,
                'title'       => 'Matchup/Situational Notes',
                'content'     => <<<'MD'
# Matchup/Situational Notes

## Dispel/Utility Talent Slot — One Shared Slot, Four Situational Picks
- **Mass Dispel** (2 minute CD) — the default pick.
- **Life Grip cooldown reduction** — taken when Mass Dispel isn't needed.
- **Purify** — taken specifically versus Death Knight compositions; removes disease and counters DK self-healing mechanics.
- **Mind Control** — taken versus compositions where a landed cast-time Mind Control is actually usable.

## Ultimate Ability Swap
"Ulti Penance" (5 minute CD) is the default pick, providing either pressure or additional healing depending on how it's used. It swaps for "Dome" specifically against Rogue teams, to counter Smoke Bomb — less commonly used than the default, but valuable in that specific matchup.

## Team-Awareness Caveat (Life Grip)
Life Grip can be used offensively too — pulling an enemy into position for damage, not just defensively. Timing matters: pulling a target away from a teammate who's mid-action on it (e.g. a hunter setting up a trap) undoes their setup, so check what your team is doing with that target before using it.
MD,
            ],
            [
                'page_number' => 5,
                'title'       => 'Weaknesses',
                'content'     => <<<'MD'
# Weaknesses

## Cast/Channel Reliance
Every core cooldown — Evangelism, Radiance, Penance, Mind Blast, Fear, both dispels — is cast- or channel-based. There's no instant, uninterruptible tool in the kit, so all of it depends on being able to cast without getting interrupted.

## Pain Suppression's Availability Is Sequence-Dependent
Pain Suppression's fast availability depends on having already been shielding beforehand (via Protector of the Frail) — if you haven't been actively shielding, it won't be back up quickly when you need it.

## Life Grip Requires Team Coordination To Use Safely
Using Life Grip offensively without checking what your team is already doing to that target is a real mistake, not just a stylistic preference — it can undo a teammate's setup.
MD,
            ],
        ];
    }
}
