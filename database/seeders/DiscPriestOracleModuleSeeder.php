<?php

namespace Database\Seeders;

use App\Http\Services\ModuleSpellReferenceService;
use App\Models\Concept;
use App\Models\GameClass;
use App\Models\Module;
use App\Models\ModuleGameBuild;
use App\Models\ModulePage;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Specialization;
use App\Models\Subject;
use App\Models\SubjectContextOption;
use App\Models\TalentTree;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Canonical context module authored directly from a Gladiator-rated player's own
 * dictation (Discipline Priest, Oracle build) — organised/clarified, not AI-generated
 * or cross-checked against guides. First pilot of the "expertise-capture" pattern
 * described in VISION.md and the "Canonical Context Module Template" section of
 * CLAUDE.md. Recall questions (see questions()) were added as a later authoring pass,
 * tagged to the same broad, class-blind WoW concepts WowPvpFundamentalsSeeder already
 * uses (Cooldown Management, Crowd Control, etc.) rather than inventing Priest-specific
 * concepts — per the Player Model Design Principle, concepts stay universal and context
 * only flavours how they're taught. The module is published once real questions exist.
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
        $this->seedGameBuildAndSpells($module);
        $this->seedQuestions($subject, $module);
        $this->attachProficiency($subject, $module);

        // Recall questions now exist — safe to make this enrollable/visible in
        // Modules\Index. Unconditional + idempotent: re-running the seeder keeps it published.
        $module->update(['published' => true]);

        $this->command?->info('✅ Discipline Priest (Oracle) canonical module seeded.');
    }

    private function attachProficiency(Subject $subject, Module $module): void
    {
        // Gladiator-dictated content — tag to the top WoW proficiency tier, matching
        // WowPvpFundamentalsSeeder's convention of attaching a real Proficiency row
        // rather than leaving the module untiered.
        $proficiency = Proficiency::where('subject_id', $subject->id)->where('name', 'Expert')->first();

        if (! $proficiency) {
            $this->command?->warn('⚠️ Expert proficiency tier not found for WoW — skipping proficiency tag.');
            return;
        }

        if (! $module->proficiencies()->where('proficiency_id', $proficiency->id)->exists()) {
            $module->proficiencies()->attach($proficiency->id);
        }
    }

    private function seedQuestions(Subject $subject, Module $module): void
    {
        $created = 0;
        $linked  = 0;

        foreach ($this->questions() as $q) {
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

        $this->command?->info("✅ Discipline Priest (Oracle) questions: {$created} created, {$linked} linked.");
    }

    /**
     * All facts tested here are drawn directly from pages() below — including the
     * Ultimate Penitence "5 minute CD" claim, which a prior cross-check against
     * imported spell data flagged as a likely discrepancy (data says 240s/4 min) still
     * pending the original dictating player's confirmation (see CLAUDE.md). Questions
     * deliberately test what the module's own prose says, not the unconfirmed
     * correction — do not silently "fix" this number here.
     *
     * See knowledge-gaps.md (repo root) for the full, growing list of gaps found by
     * cross-referencing this module's prose against imported spell data — Ultimate
     * Penitence above is one entry among several (Weal and Woe, Inner Focus, Borrowed
     * Time, Evangelism's true 150% burst value, Inner Shadow/Focused Power vs.
     * Atonement, Penance heal-vs-damage math). None of those are reflected in
     * pages()/questions() here — same "flag, don't silently fix" rule.
     *
     * @return array<int, array{question: string, type: string, difficulty: string, skill_type: string, answer: array, concepts: array<int, string>}>
     */
    private function questions(): array
    {
        return [
            [
                'question'   => 'Which Hero Talent path does this module cover for Discipline Priest?',
                'type'       => 'mcq',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => 'Oracle',
                    'options' => ['Oracle', 'Void Weaver', 'Twins', 'Shadowy Insight'],
                ],
                'concepts' => ['Role Fundamentals'],
            ],
            [
                'question'   => 'True or False: Evangelism\'s cooldown is reduced from 90 seconds to 45 seconds by Ultimate Radiance.',
                'type'       => 'true_false',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => ['correct' => true],
                'concepts' => ['Cooldown Management'],
            ],
            [
                'question'   => 'How many charges does Pain Suppression have?',
                'type'       => 'mcq',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => '2',
                    'options' => ['1', '2', '3', 'It has no charges, just a single cooldown'],
                ],
                'concepts' => ['Cooldown Management'],
            ],
            [
                'question'   => 'What is Fade\'s cooldown and purpose in this build?',
                'type'       => 'mcq',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => '20 second cooldown, used to avoid incoming crowd control',
                    'options' => [
                        '20 second cooldown, used to avoid incoming crowd control',
                        '60 second cooldown, used to increase healing output',
                        '20 second cooldown, used to purge enemy buffs',
                        '10 second cooldown, used to silence an enemy caster',
                    ],
                ],
                'concepts' => ['Cooldown Management', 'Crowd Control'],
            ],
            [
                'question'   => 'Psychic Voice reduces Psychic Scream\'s cooldown from 40 seconds down to how many seconds?',
                'type'       => 'mcq',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => '30 seconds',
                    'options' => ['30 seconds', '20 seconds', '35 seconds', '10 seconds'],
                ],
                'concepts' => ['Cooldown Management', 'Crowd Control'],
            ],
            [
                'question'   => 'True or False: Angelic Bulwark is a player-activated cooldown that you press when you drop below 30% health.',
                'type'       => 'true_false',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => ['correct' => false],
                'concepts' => ['Cooldown Management', 'Awareness & Tracking'],
            ],
            [
                'question'   => 'What does Protector of the Frail do for Pain Suppression?',
                'type'       => 'mcq',
                'difficulty' => 'medium',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => 'Reduces its cooldown by 3 seconds every time you cast Power Word: Shield',
                    'options' => [
                        'Reduces its cooldown by 3 seconds every time you cast Power Word: Shield',
                        'Grants it a third charge once talented',
                        'Increases the damage reduction it provides by 20%',
                        'Removes its cooldown entirely while Atonement is active',
                    ],
                ],
                'concepts' => ['Cooldown Management'],
            ],
            [
                'question'   => 'What does Dark Indulgence add to Mind Blast?',
                'type'       => 'mcq',
                'difficulty' => 'medium',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => '+20% damage and a guaranteed Power of the Darkside proc, empowering your next Penance by 30%',
                    'options' => [
                        '+20% damage and a guaranteed Power of the Darkside proc, empowering your next Penance by 30%',
                        'Reduces its cooldown from 30 seconds to 20 seconds',
                        'Makes it instant cast with no cooldown change',
                        'Adds a stun to the target on impact',
                    ],
                ],
                'concepts' => ['Cooldown Management'],
            ],
            [
                'question'   => 'Why does this build time Mind Blast together with Evangelism rather than using them separately?',
                'type'       => 'mcq',
                'difficulty' => 'medium',
                'skill_type' => 'analysis',
                'answer'     => [
                    'correct' => 'So the Dark Indulgence-empowered Penance lands in the same window as Evangelism\'s extra Harsh Discipline bolts, instead of the two burst effects landing separately',
                    'options' => [
                        'So the Dark Indulgence-empowered Penance lands in the same window as Evangelism\'s extra Harsh Discipline bolts, instead of the two burst effects landing separately',
                        'Because Mind Blast shares a cooldown with Evangelism and must be used at the same time',
                        'Because Evangelism requires a prior Mind Blast cast to trigger Ultimate Radiance',
                        'Because casting them apart wastes global cooldowns with no other benefit',
                    ],
                ],
                'concepts' => ['Cooldown Management'],
            ],
            [
                'question'   => 'Match each cooldown to its defining characteristic in this build.',
                'type'       => 'matching_pairs',
                'difficulty' => 'medium',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => [
                        'Evangelism'        => 'Burst-healing cooldown that also empowers Penance via extra Harsh Discipline bolts',
                        'Pain Suppression'  => 'Damage-reduction cooldown that can be used while stunned',
                        'Fade'              => 'Short cooldown used to avoid incoming crowd control',
                        'Desperate Prayer'  => 'Defensive self-heal cooldown, reduced by Angel\'s Mercy',
                    ],
                    'pairs' => [
                        'keys'   => ['Evangelism', 'Pain Suppression', 'Fade', 'Desperate Prayer'],
                        'values' => [
                            'Damage-reduction cooldown that can be used while stunned',
                            'Short cooldown used to avoid incoming crowd control',
                            'Defensive self-heal cooldown, reduced by Angel\'s Mercy',
                            'Burst-healing cooldown that also empowers Penance via extra Harsh Discipline bolts',
                        ],
                    ],
                ],
                'concepts' => ['Cooldown Management'],
            ],
            [
                'question'   => 'True or False: According to the module\'s Team-Awareness Caveat, Life Grip should only ever be used defensively, never offensively.',
                'type'       => 'true_false',
                'difficulty' => 'medium',
                'skill_type' => 'recall',
                'answer'     => ['correct' => false],
                'concepts' => ['Team Composition', 'Awareness & Tracking'],
            ],
            [
                'question'   => 'Your team is facing a Rogue composition and expects Smoke Bomb during the fight. Which Ultimate Ability swap does the module recommend?',
                'type'       => 'mcq',
                'difficulty' => 'hard',
                'skill_type' => 'application',
                'answer'     => [
                    'correct' => 'Swap Ultimate Penitence for Power Word: Barrier ("Dome") to counter Smoke Bomb',
                    'options' => [
                        'Swap Ultimate Penitence for Power Word: Barrier ("Dome") to counter Smoke Bomb',
                        'Keep Ultimate Penitence — it already counters Smoke Bomb on its own',
                        'Swap Mass Dispel for Purify to remove the Smoke Bomb effect',
                        'Swap Pain Suppression for Angelic Bulwark for the extra shield uptime',
                    ],
                ],
                'concepts' => ['Team Composition', 'Cooldown Management'],
            ],
            [
                'question'   => 'Your hunter teammate is mid-cast setting a trap on the enemy healer. You have Life Grip available and are considering pulling that healer out of position for offensive pressure. What should you do?',
                'type'       => 'mcq',
                'difficulty' => 'hard',
                'skill_type' => 'application',
                'answer'     => [
                    'correct' => 'Check what your team is doing with that target first — pulling them now would undo your hunter\'s trap setup',
                    'options' => [
                        'Check what your team is doing with that target first — pulling them now would undo your hunter\'s trap setup',
                        'Use Life Grip immediately, since more pressure is always better regardless of teammate timing',
                        'Never use Life Grip offensively under any circumstances',
                        'Wait for the hunter to confirm by voice before ever using Life Grip in a match',
                    ],
                ],
                'concepts' => ['Team Composition', 'Awareness & Tracking'],
            ],
            [
                'question'   => 'You want to line up the biggest possible burst window using Evangelism, Power Word: Radiance, Mind Blast, and Penance. Place them in the order you\'d actually cast them.',
                'type'       => 'ordering',
                'difficulty' => 'hard',
                'skill_type' => 'application',
                'answer'     => [
                    'steps' => [
                        'Evangelism first — it instantly casts a free Power Word: Radiance and makes your next 2 casts of Radiance instant too',
                        'Power Word: Radiance next — each of those now-instant casts procs Harsh Discipline, stacking up to 2 charges of extra Penance bolts',
                        'Mind Blast next — Dark Indulgence guarantees a Power of the Darkside proc, empowering your next Penance by 30%',
                        'Penance last — it consumes both buffs at once: the stacked Harsh Discipline bolts and the Power of the Darkside empower',
                    ],
                ],
                'concepts' => ['Cooldown Management'],
            ],
        ];
    }

    /**
     * Declares this module's build (Priest / Discipline / Oracle) and attaches the curated list
     * of spells actually named in the prose above — see ModuleSpellReferenceService and the
     * "Spells" section on the module show page, which resolves full detail + modifiers for each
     * of these live, at render time, from whatever's currently imported. Degrades gracefully
     * (warns, skips) if the WoW game-data import hasn't been run — this module's prose pages
     * work fine on their own either way, the Spells section just won't have anything to show.
     */
    private function seedGameBuildAndSpells(Module $module): void
    {
        $class = GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->where('name', 'Priest')
            ->first();

        if (! $class) {
            $this->command?->warn('⚠️ Priest game-data class not found — run import:spelldata first. Skipping Spells section data.');
            return;
        }

        $spec = Specialization::where('class_id', $class->id)->where('name', 'Discipline')->first();
        $heroTree = TalentTree::where('class_id', $class->id)->where('type', 'hero')->where('name', 'Oracle')->first();

        $build = ModuleGameBuild::updateOrCreate(
            ['module_id' => $module->id],
            [
                'class_id' => $class->id,
                'specialization_id' => $spec?->id,
                'hero_talent_tree_id' => $heroTree?->id,
            ]
        );

        $service = new ModuleSpellReferenceService();
        $resolvedIds = [];
        $unresolved = [];

        foreach ($this->mentionedSpellNames() as $name) {
            $spell = $service->resolveSpellByName($name, $build);

            if ($spell) {
                $resolvedIds[] = $spell->id;
            } else {
                $unresolved[] = $name;
            }
        }

        $module->spellReferences()->sync($resolvedIds);

        if ($unresolved) {
            $this->command?->warn('⚠️ Could not resolve these spell names against imported game data: '.implode(', ', $unresolved));
        }
    }

    /**
     * Every ability actually named in the module's prose pages (see pages() below) — including
     * community nicknames resolved to their real names: "Life Grip" is Leap of Faith, "Dome" is
     * Power Word: Barrier (Ultimate Penitence's talent-choice sibling — same row/col, opposite
     * select_idx). Deliberately just the named list, not the full Discipline/Oracle kit — the
     * Spells section pulls in what modifies/enhances each of these separately, at render time.
     *
     * @return array<int, string>
     */
    private function mentionedSpellNames(): array
    {
        return [
            'Evangelism',
            'Power Word: Radiance',
            'Harsh Discipline',
            'Penance',
            'Dark Indulgence',
            'Pain Suppression',
            'Protector of the Frail',
            'Power Word: Shield',
            'Fade',
            'Shadow Word: Death',
            'Desperate Prayer',
            "Angel's Mercy",
            'Angelic Bulwark',
            'Psychic Scream',
            'Psychic Voice',
            'Mind Blast',
            'Power of the Dark Side',
            'Mass Dispel',
            'Purify',
            'Mind Control',
            'Ultimate Penitence',
            'Power Word: Barrier',
            'Leap of Faith',
        ];
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
