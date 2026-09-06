<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captures a "School Immunity" effect's own payload — which school(s) it covers ("All",
 * "Physical", or a comma-joined list like "Arcane, Fire, Frost, Holy, Nature, Shadow"). Stored
 * raw, not re-encoded as a bitmask, since the one consumer of this column
 * (ModuleSpellReferenceService::schoolImmunityGrantedBy()) matches it directly against a CC
 * spell's own `school` column string.
 *
 * Real gap this closes, confirmed 2026-09-04: Cloak of Shadows, Divine Shield, and Blessing of
 * Protection all use School Immunity, not Mechanic Immunity (spells.usable_while_cc's sibling —
 * see the 2026-09-02 migration) — none of them showed up as counters to a Physical-school Stun
 * like Kidney Shot because this field was never captured at all. Flagged as a "deliberately not
 * built" follow-up when Mechanic Immunity capture first shipped; closed now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->string('affected_schools')->nullable()->after('misc_value');
        });
    }

    public function down(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->dropColumn('affected_schools');
        });
    }
};
