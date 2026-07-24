<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModulePage;
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
 * reported as unresolved on the Cooldowns Referenced page rather than filled in
 * from general knowledge. This run is the direct input for the next step
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

        $this->command?->info('✅ Discipline Priest: Fade & Death Matchup Timing module seeded.');
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
                'title'       => 'Cooldowns Referenced',
                'content'     => <<<'MD'
# Cooldowns Referenced

Every ability named in the "Fade & Death Timing" dictation, resolved (or explicitly left unresolved) against this project's own SimulationCraft spell data only — no internet lookups, no AI general-knowledge fallback. This is a companion piece to the Discipline Priest (Oracle) module's Core Cooldowns page, not a replacement for it.

## Own Kit — Priest (resolved from `data/spelldata/filtered/priest/`)

- **Fade** — 30 second base cooldown, reduced to 20 seconds with 2/2 Improved Fade talented (matches the Oracle module's Core Cooldowns page).
- **Shadow Word: Death** ("Death") — 10 second cooldown, 1 charge, instant, 40 yard range.
- **Psychic Scream** ("Fear") — 40 second base cooldown, 8 second duration. Psychic Voice reduces the cooldown by 10 seconds, down to 30.
- **Angelic Feather** ("Feathers") — baseline effect is +40% movement speed (project data tooltip: "Movement speed increased by 40%").

## Opponent — Hunter (resolved from `data/spelldata/filtered/hunter/`)

- **Intimidation** ("pet stun") — 5 second stun, cast by the Hunter's pet.
- **Freezing Trap** ("trap") — Freeze-mechanic CC, breaks on damage. Spell data lists a 100 yard cast range — that's the maximum range for placing/throwing it, not a tactical "safe distance." The dictation's general principle (more distance from the hunter = harder for them to land it) is the actionable guidance, not a specific yardage.

## Opponent — Mage (resolved from `data/spelldata/filtered/mage/`)

- **Dragon's Breath** ("DB") — Confuse (Disorient mechanic), 90 degree cone, 12 yard radius, 4 second duration, 45 second cooldown.
- **Polymorph** ("poly") — name was already correct in the dictation. Checked both the filtered Mage data *and* the raw pre-filter dump (`data/spelldata/raw/mage.txt`) directly — absent from both; only "Mass Polymorph" (a different, AoE version) appears anywhere. So this isn't a filtering-script gap, it's missing from the source data itself. Notably, this isn't a "CC spells got excluded" pattern either — Frost Nova, Counterspell, Ice Block, and Spellsteal (all pure-utility/CC, no damage component) are all present in the same raw dump. No rule in the data explains why Polymorph specifically is missing. Duration/range/mechanics can't be verified from project files. This doesn't block the DR logic in the Vs Mage notes below, since the Incapacitate diminishing-returns reset window is a general PvP system rule, not a Polymorph-specific number.
- **Snowdrift** (Mage, Frost — a PvP Talent) — this is "the mage's slow stun thing in the frost school." **Name confirmed by the player directly, not by project data.** It's absent from both the filtered Mage data and the raw dump (`data/spelldata/raw/mage.txt`), confirmed by checking the raw source directly. This is *not* a case of PvP talents being excluded wholesale — the raw dump has 181 entries tagged `(desc=PvP Talent)`, including "Improved Mass Invisibility" from the same PvP talent tree Snowdrift belongs to, and those entries correctly survive the split script into `filtered/mage/baseline.txt`. Snowdrift specifically is missing from this SimC build's (1205-01, WoW 12.0.7.68453) spell database — a per-ability gap, not a category-wide one. Exact numbers (cooldown, duration, slow%, stun trigger) remain unverified: this is a case of "the expert supplied a fact the project's data can't confirm," the same division of labor described for canonical modules generally, rather than an AI-guessed name. Needs either an updated data pull that actually includes it, or another verification path — deliberately not filled in with an internet lookup here.

## Opponent — Paladin (resolved from `data/spelldata/filtered/paladin/`)

- **Hammer of Justice** ("HoJ") — 10 yard range, 6 second stun duration, 45 second cooldown.
- **Divine Steed** ("the mount speed thing") — 3 second duration (base), 45 second cooldown (1 charge). Self-buff, no target range — it's a mount effect on the caster, not something aimed at a distance.
MD,
            ],
            [
                'page_number' => 2,
                'title'       => 'Matchup Notes',
                'content'     => <<<'MD'
# Matchup Notes

## Vs Mage

Save Shadow Word: Death for the mage's Polymorph — landing a hard CC on the mage while Polymorph's Incapacitate category is active puts that whole category on diminishing returns, meaning the mage can only land a half-duration Polymorph again within the current 16 second DR reset window. (This DR-timer number is a general PvP system rule rather than a Polymorph-specific spell value, and matches what was dictated exactly.)

Fade is used against two different mage effects: Dragon's Breath (a Disorient — see Cooldowns Referenced above), and Snowdrift, the frost school's slow/stun setup. Snowdrift's name is player-confirmed rather than project-data-verified — it doesn't appear in either the filtered or raw Mage spell data (see Cooldowns Referenced) — so treat the name as settled but its exact mechanics (duration, slow%, stun trigger) as still unresolved.

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
