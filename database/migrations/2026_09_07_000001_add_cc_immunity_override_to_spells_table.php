<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hand-curated CC-immunity fact for spells whose immunity has NO structured source anywhere in
 * this pipeline — overwhelmingly PvP talents.
 *
 * Why this has to be curated rather than derived, measured 2026-09-07 rather than assumed:
 *
 *   - 220 of 250 PvP talents have ZERO spell_effects rows, so ccImmunityGrantedBy() (which reads
 *     'Mechanic Immunity' effects) and grantsSchoolImmunityFor() have literally nothing to read.
 *   - 0 of those 220 appear in the raw SimC dumps at all — checked against every one of the
 *     12,468 `Name : X (id=N)` records in data/spelldata/raw/. This is NOT regenerate-filtered.php
 *     dropping them; SimC genuinely does not ship PvP talent spells.
 *   - Only 10 of the 220 have a same-name sibling carrying effects, so the sibling-recovery
 *     fallback used elsewhere in this codebase (findEffectByIndex(), categorize(), Cloak of
 *     Shadows' School Immunity) closes almost none of it.
 *   - Blizzard's own /data/wow/spell/{id} endpoint was called live for four of them and returns
 *     only id/name/description/media — no cooldown, no effects, no duration. The PvP talent index
 *     adds spell_id/spec/compatible_slots and nothing else. There is no official structured
 *     source to import.
 *
 * So the free-text description is the only source of truth, exactly as data/spelldata/
 * cc-immunity-overrides.txt's header already said for the cc_immunity_note column added
 * 2026-09-02. What that note could never do is answer a QUERY: it is prose, so
 * SpellCounterIndexer could not read it, and Fade — the single curated line in that file — still
 * produced zero rows in spell_counters. These two columns are the machine-readable half.
 *
 *   grants_cc_immunity_override  JSON array of real mechanic names, using the SAME vocabulary as
 *                                ModuleSpellReferenceService::MECHANIC_IMMUNITY_CODE_MAP
 *                                ("Stun", "Silence", "Incapacitate", "Fear", "Interrupt", "Sap")
 *                                so it unions cleanly into grants_cc_immunity with no translation
 *                                layer. NULL means "nothing curated"; the import validates every
 *                                name against that map and warns on anything else rather than
 *                                storing an invented mechanic.
 *
 *   cc_immunity_gating_spell_id  The EXTERNAL spell_id of the PvP talent that must be selected for
 *                                the immunity to exist at all. External, not the internal PK, for
 *                                the same reasons conditional_dr_gating_spell_id is (2026-09-06):
 *                                it is what the curation file states, what the description text
 *                                names, and what survives a re-import unchanged.
 *
 * The gating column is not an edge case — it is the DOMINANT shape here. Nearly every immunity
 * PvP talent is "passive talent X modifies pressed ability Y": Phase Shift/Fade, Zen Focus Tea/
 * Thunder Focus Tea, Sanctified Ground/Holy Word: Sanctify, The Beast Within/Bestial Wrath,
 * Obsidian Mettle/Obsidian Scales, Peaceweaver/Revival, Psychic Shroud/Psychic Scream,
 * Glimpse/Vengeful Retreat, Nullifying Shroud/Verdant Embrace. The immunity is therefore curated
 * onto the ability a player actually presses, with the talent recorded here — matching what
 * cc-immunity-overrides.txt's header already instructed for the prose note.
 *
 * Deliberately NOT a wildcard: there is no "all CC" value. Blizzard's own text carries carve-outs
 * (Phase Shift avoids all attacks and spells but explicitly NOT interrupts), so a wildcard would
 * silently over-claim. Every mechanic is listed explicitly, one verified name at a time — the same
 * discipline as baseline-spec-overrides.txt and cc-synergies-overrides.txt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->json('grants_cc_immunity_override')->nullable()->after('cc_immunity_note');
            $table->unsignedBigInteger('cc_immunity_gating_spell_id')->nullable()->after('grants_cc_immunity_override');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn(['grants_cc_immunity_override', 'cc_immunity_gating_spell_id']);
        });
    }
};
