<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic capture of each effect's raw "Misc Value: N" field — previously discarded entirely.
 * Meaning is contextual to the effect's own `type`: for a "Mechanic Immunity" effect it's
 * Blizzard's internal mechanic-id enum (e.g. 9 = Silence, 12 = Stun — see
 * ModuleSpellReferenceService::MECHANIC_IMMUNITY_CODE_MAP); for a "School Immunity"/
 * "Modify Damage Taken%"/etc. effect it's usually a school bitmask instead (already separately
 * surfaced via each effect's own "Affected School(s)" line where present — not yet captured
 * either, still a follow-up). Stored as a plain nullable integer, hex-prefixed raw values
 * (`0x7e`) converted to decimal at parse time — this column does not itself say which meaning
 * applies, that's a per-`type` interpretation done by the reading code.
 *
 * Added 2026-09-02 alongside spells.usable_while_cc/bypasses_active_defense, prompted by a real
 * question (does Aspect of the Turtle work while silenced?) that needed this exact field to
 * answer precisely — see CLAUDE.md's investigation write-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->integer('misc_value')->nullable()->after('scaled_value');
        });
    }

    public function down(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->dropColumn('misc_value');
        });
    }
};
