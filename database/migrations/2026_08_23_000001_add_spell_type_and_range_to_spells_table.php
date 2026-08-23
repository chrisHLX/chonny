<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spells.spell_type` / `spells.range_yards` — raw, unparsed facts straight from SimC's own
 * "Spell Type : Melee|Magic|Ranged|None" and "Range : N yards" lines, previously discarded the
 * same way `mechanic`/`is_passive`/`cast_type` were before each of those was added. Confirmed
 * present and clean across a real spot-check (Kidney Shot: Melee/5 yards; Fear: Magic/30 yards)
 * before adding this — same "verify the field actually exists and is populated before writing
 * the parser" discipline as every other raw-data column added this project.
 *
 * `spell_type` is the categorical fact this was actually added for — added 2026-08-23 for
 * wow:cc-formula's melee-vs-ranged rule ("a same-caster follow-up CC should be ranged, since a
 * melee caster who's already committed to the kill target loses less time using a ranged CC
 * than repositioning back to melee range of the healer"). `range_yards` is stored alongside as
 * the raw string (some values are a min-max range like "6 - 25 yards", not a single number —
 * deliberately NOT parsed into a numeric column here, since nothing needs that precision yet;
 * `spell_type` alone is sufficient for the melee/ranged classification this was built for).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->string('spell_type')->nullable()->after('mechanic');
            $table->string('range_yards')->nullable()->after('spell_type');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn(['spell_type', 'range_yards']);
        });
    }
};
