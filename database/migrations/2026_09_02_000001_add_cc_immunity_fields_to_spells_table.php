<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two new real-data-backed fields, both derived automatically at import time from the raw
 * SimC dump's "Attributes" line — no hand curation needed for either, unlike dr_category/
 * chain_target/etc. See SpellDataFileParser's own docblock for the full investigation and
 * the specific attribute codes each one is derived from.
 *
 * `usable_while_cc` — nullable comma-separated list of CC types this spell can still be cast
 * through (subset of: stun, fear, flee, confuse, charm, horror). Derived from "Allow While
 * Stunned"/"Allow While Stunned By Horror Mechanic"/"Allow While Stunned by Stun Mechanic"/
 * "Allow While Feared By Fear Mechanic"/"Allow While Fleeing"/"Allow While Confused"/
 * "Allow While Charmed" — real, exact-coded Attribute flags Blizzard's own data already
 * carries (706 spells in the current dataset have at least one). Confirmed against Death
 * Grip (all of stun/horror/fear/flee/confuse/charm) and Dark Pact (stun/flee/confuse/charm —
 * matches its own tooltip text "Usable while suffering from control impairing effects"
 * exactly). NOT the same thing as dr_category — this describes what a spell can be cast
 * THROUGH, not what CC it applies.
 *
 * `bypasses_active_defense` — boolean, derived from "No Active Defense" / "No Attack Dodge" /
 * "No Attack Parry" / "No Attack Block" (179/47/etc. spells respectively) being present on the
 * spell's own Attributes line — the ability never enters the normal dodge/parry/block
 * attack-table roll at all. Confirmed on Storm Bolt (has "No Active Defense") vs. Kidney Shot
 * (has neither) — matches the real, reported in-game difference (Storm Bolt can't be dodged/
 * parried, Kidney Shot can).
 *
 * Deliberately NOT built in this migration (documented as open follow-ups, not silent gaps):
 * real School Immunity ("Cloak of Shadows"-style, Affected School(s) list) and Mechanic
 * Immunity ("Icebound Fortitude"/"Berserker Rage"-style, Misc Value naming a specific
 * mechanic) effect data — both exist in the raw dump's Effects block but nothing in
 * spell_effects captures either field today. Also not built: PvP-talent-only facts (e.g.
 * Priest's "Phase Shift" — casting Fade also grants "avoiding all attacks and spells for
 * 1 sec") — PvP talents have zero structured effect data anywhere in this pipeline (confirmed:
 * Phase Shift, spell_id 408557, has 0 spell_effects rows), so this class of fact can only ever
 * be hand-curated from its own description text, the same way cc-synergies-overrides.txt
 * already hand-curates dr_category/chain_target. See data/spelldata/cc-immunity-overrides.txt
 * for that curated layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->string('usable_while_cc')->nullable()->after('mechanic');
            $table->boolean('bypasses_active_defense')->default(false)->after('usable_while_cc');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn(['usable_while_cc', 'bypasses_active_defense']);
        });
    }
};
