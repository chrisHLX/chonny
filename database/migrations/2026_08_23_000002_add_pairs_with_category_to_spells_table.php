<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spells.pairs_with_category` — a curated fact (same tier/verification bar as dr_category
 * itself, not a statistical inference) meaning "this CC's landing/effectiveness structurally
 * depends on a CC of the named dr_category being applied to the same target around the same
 * time." Added 2026-08-23 after confirming Solar Beam is a stationary ground-zone silence
 * ("Summons a beam of solar light over an enemy target's location... silencing all enemies
 * within the beam" — own description text) rather than a personal/target-following debuff: a
 * mobile target can simply walk out, so real play pairs it with an instant Root (Entangling
 * Roots/Mass Entanglement) to hold the target inside the zone. Verified two ways before adding,
 * not guessed from correlation alone: (1) the spell's own description text states the zone
 * mechanic directly, (2) a direct scan of the raw arena-log archive found 71/109 real Solar Beam
 * casts had a Root landing on some enemy within +-15s (Ring of Frost — same "enemies entering
 * the ring are incapacitated" zone mechanic per its own description — was checked at the same
 * time and tagged identically, since it's the same structural pattern, not a one-off).
 *
 * Deliberately NOT a general "these two abilities are often used together" co-occurrence field —
 * that class of indirect/statistical signal has repeatedly misled this project before (Mind Sear
 * via spell_relationships, the reverted alwaysAvailableAbilityIds() heuristic) precisely because
 * correlation in a match log doesn't distinguish "structurally required" from "just often chosen
 * near each other for unrelated reasons." This column only ever gets set after confirming the
 * REQUIREMENT via the spell's own text, same discipline as every other curated CC field in this
 * file (dr_category, chain_target, is_peel, is_interrupt).
 *
 * Nullable string referencing a real dr_category value (currently only ever "Root" — Knockback/
 * Disarm/Slow/other categories may turn out to have their own zone-style abilities later, but
 * none have been verified yet, so nothing else is tagged this way). Curated via
 * data/spelldata/cc-synergies-overrides.txt, same as dr_category/chain_target/is_peel/
 * is_interrupt/pvp_duration_seconds — see ImportSpellData::importCcSynergyOverrides().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->string('pairs_with_category')->nullable()->after('pvp_duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('pairs_with_category');
        });
    }
};
