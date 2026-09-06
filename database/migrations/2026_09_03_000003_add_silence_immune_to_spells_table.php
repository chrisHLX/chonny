<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materializes the "immune to Silence because it's a Physical-school ability" fact (see
 * CLAUDE.md's Aspect of the Turtle / Kidney Shot investigation, 2026-09-02) as a real,
 * one-column-queryable boolean — deliberately SEPARATE from usable_while_cc, not folded into
 * it, because the two have genuinely different provenance: usable_while_cc is derived from a
 * real Blizzard Attribute flag ("Allow While Stunned" etc.); this is derived from spells.school
 * (already-captured data) plus well-established real-game mechanics (Silence is a school
 * lockout, and no flag for it exists anywhere in the dataset — confirmed via a full-text scan).
 * Mixing a flag-backed fact and a school-inferred one into the same column would blur a
 * distinction worth keeping visible. Computed only for non-passive spells — silence-immunity is
 * a meaningless question for something nobody casts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->boolean('silence_immune_by_school')->default(false)->after('bypasses_active_defense');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('silence_immune_by_school');
        });
    }
};
