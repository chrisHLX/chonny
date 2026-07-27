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
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Canonical context module authored from a second dictation by the same
 * Gladiator-rated Discipline Priest player (see DiscPriestOracleModuleSeeder) —
 * kept as its own module rather than appended to the Oracle build reference,
 * since none of this content is Oracle-specific (Fade and Shadow Word: Death are
 * baseline Discipline tools, not tied to the Oracle vs. Void Weaver hero talent
 * choice).
 *
 * First strict test of raw-data reconciliation with NO internet/AI-knowledge
 * fallback allowed (per explicit instruction) — every named ability below was
 * either already correctly named in the dictation, or resolved/left unresolved
 * using ONLY this repo's SimulationCraft data (data/spelldata/filtered/{priest,
 * hunter,mage,paladin}/). Anything the project's spell data couldn't confirm is
 * reported as unresolved in the Matchup Notes page (originally its own "Cooldowns
 * Referenced" page — merged in 2026-07-27 once the live Spells section, see
 * seedGameBuildAndSpells() below, started covering every resolvable ability from
 * both pages, leaving only the unresolved Polymorph/Snowdrift caveat still needed
 * in prose) rather than filled in from general knowledge. This run is the direct
 * input for the next step
 * (designing a repeatable "resolve pipeline" for unknown cooldowns, per CLAUDE.md's
 * "Canonical Context Module Template" section) — it exists to show, concretely,
 * what a real pipeline needs to handle: a name already given by the expert but
 * absent from the data (Polymorph), and a description that doesn't map to anything
 * in the data at all (the frost "slow stun" ability).
 *
 * One finding worth flagging: an earlier draft of this same content (since discarded)
 * identified the frost "slow stun" ability via web search as Frostbite -> Deep Freeze.
 * With Mage spell data now present in the repo, that identification does not hold up —
 * there is no "Deep Freeze" entry anywhere in data/spelldata/filtered/mage/, and
 * Frostbite's actual logged effect (Frostbolt crit applies stacks of a "Freezing"
 * debuff that enables bonus Ice Lance damage) is a Shatter-combo mechanic, not a stun.
 * Concrete demonstration of why this module is internet-free: the web-sourced answer
 * was confidently wrong for this patch.
 *
 * Second finding, added after the player was shown the "unresolved" list: the frost
 * ability is Snowdrift — confirmed directly by the player, not by project data. Checked
 * the raw pre-filter dump (data/spelldata/raw/mage.txt) as well as the filtered output;
 * Snowdrift is absent from both, same as Polymorph.
 *
 * Correction to that finding, same session: PvP talents are NOT categorically excluded
 * from this SimC dump, as first assumed. data/spelldata/raw/mage.txt has 181 entries
 * tagged "(desc=PvP Talent)" (e.g. Netherwind Armor, Kleptomania, Ice Form, Master of
 * Escape, Improved Mass Invisibility), all of which correctly survive the split script
 * into filtered/mage/baseline.txt — the script has no special-case handling for them,
 * they just fall through to baseline because they carry no "Talent Entry: tree=" line,
 * same as any other non-talent-tree spell. Improved Mass Invisibility is confirmed
 * present and is from the exact same PvP talent tree as Snowdrift. So the real finding
 * is narrower than "PvP talents are missing": this specific ability, alongside several
 * sibling talents in the same tree that ARE captured, is missing from this SimC build's
 * (1205-01, WoW 12.0.7.68453) spell database — most likely a per-ability lag in SimC's
 * own PvP data entry, not a structural gap in the raw-data approach. Worth designing
 * the resolve pipeline around: even a generally-reliable source can have holes at the
 * level of an individual ability, so per-spell verification (or a documented "not
 * found" state) is still needed even after confirming the source category is covered.
 *
 * Depends on SubjectContextSeeder having created the Discipline spec option under
 * Priest — runs after it in DatabaseSeeder, but degrades gracefully (warns, creates
 * the module untagged) if run standalone before that seeder.
 */
class DiscPriestFadeDeathTimingModuleSeeder extends Seeder
{
    public function run(): void
    {
        $subject = Subject::where('name', 'World of Warcraft: The War Within')->first();

        if (! $subject) {
            $this->command?->warn('⚠️ WoW subject not found — skipping Disc Priest Fade & Death Timing module.');
            return;
        }

        $module = Module::firstOrCreate(
            ['name' => 'Discipline Priest: Fade & Death Matchup Timing', 'subject_id' => $subject->id],
            [
                'description'    => 'Player-dictated notes on Fade / Shadow Word: Death timing decisions vs Mage, Hunter, and Paladin in Arena. Resolved strictly against this project\'s local SimulationCraft spell data — no internet lookups, no AI-filled gaps. Where project data could not confirm a name or number, it is reported as unresolved rather than guessed.',
                'content_source' => 'Player dictation (Gladiator-rated, Discipline Priest) + project SimulationCraft spell data only',
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

        $this->command?->info('✅ Discipline Priest: Fade & Death Matchup Timing module seeded.');
    }

    private function attachProficiency(Subject $subject, Module $module): void
    {
        $proficiency = Proficiency::where('subject_id', $subject->id)->where('name', 'Expert')->first();

        if (! $proficiency) {
            $this->command?->warn('⚠️ Expert proficiency tier not found for WoW — skipping proficiency tag.');
            return;
        }

        if (! $module->proficiencies()->where('proficiency_id', $proficiency->id)->exists()) {
            $module->proficiencies()->attach($proficiency->id);
        }
    }

    /**
     * Declares this module's build (Priest / Discipline — no hero tree, since none of this
     * content is Oracle- or Void Weaver-specific) and attaches the curated spells from
     * mentionedSpellNames() below — both the module's own Priest kit AND the opponent-class
     * abilities (Hunter/Mage/Paladin) named in the Cooldowns Referenced page, now that
     * ModuleSpellReferenceService (2026-07-27) resolves and scopes modifiers per spell's own
     * class rather than assuming everything belongs to the module's declared build. Polymorph
     * and Snowdrift are deliberately left out of this list — both are confirmed absent from the
     * project's Mage spell data entirely (see the Cooldowns Referenced page), so resolving them
     * here would just be a guaranteed no-op; the page's own "unresolved" framing is the honest
     * state for those two, not something this live section can improve on. Degrades gracefully
     * (warns, skips) if the WoW game-data import hasn't been run.
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

        $build = ModuleGameBuild::updateOrCreate(
            ['module_id' => $module->id],
            [
                'class_id' => $class->id,
                'specialization_id' => $spec?->id,
                'hero_talent_tree_id' => null,
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
     * The module's own (Priest) abilities plus every opponent-class ability named on the
     * Cooldowns Referenced page that's actually present in the project's spell data — see
     * seedGameBuildAndSpells()'s docblock for why Polymorph and Snowdrift are excluded.
     *
     * @return array<int, string>
     */
    private function mentionedSpellNames(): array
    {
        return [
            // Own kit — Priest
            'Fade',
            'Shadow Word: Death',
            'Psychic Scream',
            'Angelic Feather',
            // Opponent — Hunter
            'Intimidation',
            'Freezing Trap',
            // Opponent — Mage
            "Dragon's Breath",
            // Opponent — Paladin
            'Hammer of Justice',
            'Divine Steed',
        ];
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

        $this->command?->info("✅ Discipline Priest: Fade & Death Timing questions: {$created} created, {$linked} linked.");
    }

    /**
     * Every fact tested here is drawn only from pages() above, which itself only asserts
     * what the project's SimulationCraft data could confirm (see the class docblock).
     * Deliberately does NOT test Polymorph's or Snowdrift's exact numbers (cooldown,
     * duration, slow%) — those are explicitly unresolved in the source page, so a
     * question with a definite correct answer would either invent a fact or silently
     * contradict the page's own "unresolved" framing. Two meta-questions (below) instead
     * test that a player understands *which* facts on this page are verified vs.
     * expert-reported-but-unconfirmed — a real, teachable distinction this module's
     * whole design is built around. Also deliberately does NOT ask the reader to name
     * who gets Feared at the end of the Vs Paladin sequence — the page's own trailing
     * note flags "fear the hunter" vs "fear the paladin" as an unresolved possible slip
     * in the original dictation, so the ordering question below leaves that step's
     * target unnamed rather than picking a side.
     *
     * @return array<int, array{question: string, type: string, difficulty: string, skill_type: string, answer: array, concepts: array<int, string>}>
     */
    private function questions(): array
    {
        return [
            [
                'question'   => 'What is Fade\'s cooldown once Improved Fade (2/2) is talented?',
                'type'       => 'mcq',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => '20 seconds (reduced from 30)',
                    'options' => [
                        '20 seconds (reduced from 30)',
                        '30 seconds — Improved Fade only affects its duration, not cooldown',
                        '15 seconds (reduced from 30)',
                        '25 seconds (reduced from 30)',
                    ],
                ],
                'concepts' => ['Cooldown Management'],
            ],
            [
                'question'   => 'True or False: Shadow Word: Death has 1 charge and a 10 second cooldown.',
                'type'       => 'true_false',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => ['correct' => true],
                'concepts' => ['Cooldown Management'],
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
                'question'   => 'What does Angelic Feather do at baseline?',
                'type'       => 'mcq',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => 'Increases movement speed by 40%',
                    'options' => [
                        'Increases movement speed by 40%',
                        'Grants immunity to slows for its duration',
                        'Removes root effects instantly',
                        'Increases healing done by 40%',
                    ],
                ],
                'concepts' => ['Cooldown Management', 'Positioning'],
            ],
            [
                'question'   => 'Who casts Intimidation (the "pet stun") and how long does it last?',
                'type'       => 'mcq',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => 'The hunter\'s pet casts it; 5 second stun',
                    'options' => [
                        'The hunter\'s pet casts it; 5 second stun',
                        'The hunter casts it directly; 5 second stun',
                        'The hunter\'s pet casts it; 8 second stun',
                        'The hunter casts it directly; 3 second stun',
                    ],
                ],
                'concepts' => ['Crowd Control', 'Awareness & Tracking'],
            ],
            [
                'question'   => 'What are Hammer of Justice\'s range and stun duration?',
                'type'       => 'mcq',
                'difficulty' => 'easy',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => '10 yard range, 6 second stun',
                    'options' => [
                        '10 yard range, 6 second stun',
                        '20 yard range, 4 second stun',
                        '10 yard range, 4 second stun',
                        '30 yard range, 6 second stun',
                    ],
                ],
                'concepts' => ['Cooldown Management', 'Crowd Control'],
            ],
            [
                'question'   => 'The project\'s spell data lists Freezing Trap\'s cast range as 100 yards. What does the module say this figure actually represents?',
                'type'       => 'mcq',
                'difficulty' => 'medium',
                'skill_type' => 'analysis',
                'answer'     => [
                    'correct' => 'The maximum range for placing/throwing the trap — not a tactical "safe distance"; more distance from the hunter still makes it harder for them to land it',
                    'options' => [
                        'The maximum range for placing/throwing the trap — not a tactical "safe distance"; more distance from the hunter still makes it harder for them to land it',
                        'The exact safe distance to stand so the trap is never a threat',
                        'The duration the trap remains active on the ground',
                        'The radius of the freeze effect once it triggers',
                    ],
                ],
                'concepts' => ['Positioning', 'Awareness & Tracking'],
            ],
            [
                'question'   => 'What is Divine Steed, mechanically?',
                'type'       => 'mcq',
                'difficulty' => 'medium',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => 'A self-buff mount-speed effect on the caster — no target, no range, not an ability aimed at you',
                    'options' => [
                        'A self-buff mount-speed effect on the caster — no target, no range, not an ability aimed at you',
                        'A ranged snare that slows the target for 3 seconds',
                        'A defensive cooldown that grants damage reduction',
                        'An interrupt on a 45 second cooldown',
                    ],
                ],
                'concepts' => ['Awareness & Tracking', 'Positioning'],
            ],
            [
                'question'   => 'What kind of crowd control is Dragon\'s Breath, and what shape does it hit?',
                'type'       => 'mcq',
                'difficulty' => 'medium',
                'skill_type' => 'recall',
                'answer'     => [
                    'correct' => 'A Disorient effect in a 90 degree cone with a 12 yard radius',
                    'options' => [
                        'A Disorient effect in a 90 degree cone with a 12 yard radius',
                        'A single-target Incapacitate with no range limit',
                        'A root effect that only hits in a straight line',
                        'A silence affecting all targets within 40 yards',
                    ],
                ],
                'concepts' => ['Crowd Control'],
            ],
            [
                'question'   => 'Why does this module report Polymorph\'s cooldown/duration as unresolved instead of listing a number?',
                'type'       => 'mcq',
                'difficulty' => 'medium',
                'skill_type' => 'analysis',
                'answer'     => [
                    'correct' => 'Polymorph doesn\'t appear in either the filtered or raw project spell data for Mage, even though its name was already correct in the dictation — so its exact mechanics can\'t be confirmed from project data alone',
                    'options' => [
                        'Polymorph doesn\'t appear in either the filtered or raw project spell data for Mage, even though its name was already correct in the dictation — so its exact mechanics can\'t be confirmed from project data alone',
                        'Because Polymorph was intentionally excluded as a crowd control ability',
                        'Because the player wasn\'t sure of the ability\'s name',
                        'Because Polymorph has no fixed cooldown in the game',
                    ],
                ],
                'concepts' => ['Awareness & Tracking'],
            ],
            [
                'question'   => 'What is the strategic value of saving Shadow Word: Death specifically for the mage\'s Polymorph attempt, rather than using it whenever it\'s off cooldown?',
                'type'       => 'mcq',
                'difficulty' => 'medium',
                'skill_type' => 'analysis',
                'answer'     => [
                    'correct' => 'Landing a hard CC while Polymorph\'s Incapacitate category is active puts that category on diminishing returns, so a follow-up Polymorph within the DR window lands at only half duration',
                    'options' => [
                        'Landing a hard CC while Polymorph\'s Incapacitate category is active puts that category on diminishing returns, so a follow-up Polymorph within the DR window lands at only half duration',
                        'Shadow Word: Death deals bonus damage to targets currently affected by Polymorph',
                        'It removes the mage\'s Polymorph from their spellbook for the rest of the match',
                        'It silences the mage, preventing any further Polymorph casts that fight',
                    ],
                ],
                'concepts' => ['Crowd Control'],
            ],
            [
                'question'   => 'True or False: Standing further away from the hunter makes Freezing Trap harder for them to land, even though the trap\'s listed cast range is 100 yards.',
                'type'       => 'true_false',
                'difficulty' => 'medium',
                'skill_type' => 'recall',
                'answer'     => ['correct' => true],
                'concepts' => ['Positioning', 'Awareness & Tracking'],
            ],
            [
                'question'   => 'A hunter is standing close to you and has just applied a slow. According to the matchup notes, what are your two valid responses?',
                'type'       => 'mcq',
                'difficulty' => 'hard',
                'skill_type' => 'application',
                'answer'     => [
                    'correct' => 'Either wait, then use Shadow Word: Death; or Fade, then Angelic Feather, then Fear the hunter',
                    'options' => [
                        'Either wait, then use Shadow Word: Death; or Fade, then Angelic Feather, then Fear the hunter',
                        'Always Shadow Word: Death immediately, regardless of positioning',
                        'Always Fade immediately, then reposition without using Fear',
                        'Trinket the slow, then close the distance to melee range',
                    ],
                ],
                'concepts' => ['Crowd Control', 'Awareness & Tracking', 'Cooldown Management'],
            ],
            [
                'question'   => 'The paladin baits your Fade with Divine Steed, then still lands Hammer of Justice anyway. Per the matchup notes, place your follow-up response in order.',
                'type'       => 'ordering',
                'difficulty' => 'hard',
                'skill_type' => 'application',
                'answer'     => [
                    'steps' => [
                        'Fade to avoid the Hammer of Justice as the paladin closes to range',
                        'Angelic Feather for extra movement speed',
                        'Run toward the paladin rather than continuing to retreat',
                        'Fear to close out the sequence',
                    ],
                ],
                'concepts' => ['Positioning', 'Cooldown Management', 'Crowd Control'],
            ],
            [
                'question'   => 'Why does the module recommend positioning far enough away that the paladin has to visibly close the distance before Hammer of Justice is in range, rather than staying somewhere already within its range?',
                'type'       => 'mcq',
                'difficulty' => 'hard',
                'skill_type' => 'analysis',
                'answer'     => [
                    'correct' => 'Hammer of Justice only has a 10 yard range, so forcing the paladin to telegraph closing that distance gives you a warning window to react — e.g. with Fade — before the stun can land',
                    'options' => [
                        'Hammer of Justice only has a 10 yard range, so forcing the paladin to telegraph closing that distance gives you a warning window to react — e.g. with Fade — before the stun can land',
                        'Because Hammer of Justice has no cooldown and could otherwise be used repeatedly',
                        'Because standing at range removes Hammer of Justice\'s cast time entirely',
                        'Because Divine Steed cannot be used unless the paladin is already at range',
                    ],
                ],
                'concepts' => ['Positioning', 'Awareness & Tracking'],
            ],
        ];
    }

    private function seedPages(Module $module): void
    {
        // Deletes any page number no longer present in pages() below — needed now that the
        // former "Cooldowns Referenced" page (page 1) was removed once the live Spells section
        // (see seedGameBuildAndSpells()) started covering the same ground; without this,
        // updateOrCreate alone would leave that stale row in place forever.
        ModulePage::where('module_id', $module->id)
            ->whereNotIn('page_number', collect($this->pages())->pluck('page_number'))
            ->delete();

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
                'title'       => 'Matchup Notes',
                'content'     => <<<'MD'
# Matchup Notes

Every own-kit and opponent ability named below now has a live entry in this module's Spells section (see the bottom of this page) — cooldowns, ranges, and modifiers are resolved directly from the project's spell data rather than frozen into this prose, so check there for exact numbers. The one exception is Polymorph and Snowdrift (below) — both are confirmed absent from the project's Mage spell data entirely, so neither has a Spells entry to check.

## Vs Mage

Save Shadow Word: Death for the mage's Polymorph — landing a hard CC on the mage while Polymorph's Incapacitate category is active puts that whole category on diminishing returns, meaning the mage can only land a half-duration Polymorph again within the current 16 second DR reset window. (This DR-timer number is a general PvP system rule rather than a Polymorph-specific spell value, and matches what was dictated exactly.) Polymorph's own name was already correct in the dictation, but it doesn't appear anywhere in the project's Mage spell data (filtered or raw) — its exact duration/range can't be confirmed from project data, though the DR logic above doesn't depend on that number.

Fade is used against two different mage effects: Dragon's Breath (a Disorient — see the Spells section below for its exact shape/duration/cooldown), and Snowdrift, the frost school's slow/stun setup. Snowdrift's name is player-confirmed rather than project-data-verified — it doesn't appear in either the filtered or raw Mage spell data, same as Polymorph — so treat the name as settled but its exact mechanics (duration, slow%, stun trigger) as still unresolved.

## Vs Hunter

If the hunter starts looking at you or repositioning in a way that signals they're about to trap you, you may be able to Fade the pet's Intimidation stun. If you reposition, the further away you are from the hunter, the harder it is for them to land Freezing Trap on you.

If the hunter is positioned near you and applying a slow, they're either trying to bait Shadow Word: Death out before trapping you, or — if you delay Death — they can catch you on your next global. So either wait, then Death; or Fade, then Angelic Feather, then Fear the hunter.

## Vs Paladin

Position far enough away that the paladin has to telegraph Hammer of Justice before it's in its 10 yard range.

Since Hammer of Justice has that limited range, when the paladin uses Divine Steed to close the distance, Fade as they come within Hammer of Justice range. Sometimes they'll close in, bait the Fade out, then land Hammer of Justice anyway. In that situation, it's better — as the paladin closes in — to Fade, Angelic Feather, run toward the paladin, then Fear.

*The original dictation ends this section with "fear the hunter" rather than "fear the paladin" — likely a slip carried over from the Vs Hunter section above, preserved here rather than silently corrected. Verify which target was actually intended before treating this as settled.*
MD,
            ],
        ];
    }
}
