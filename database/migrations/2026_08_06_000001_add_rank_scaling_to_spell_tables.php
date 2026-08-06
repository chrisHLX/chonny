<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            // Some multi-rank talents (max_ranks > 1) carry a per-rank-different magnitude for
            // the SAME effect — e.g. Improved Fade (spell_id 390670): rank 1 reduces Fade's
            // cooldown by 5s, rank 2 by 10s, both under the identical spell_id and effect_index
            // (SimC's own Talent Entry line: "Effect#1 [op=set, values=(-5000, -10000)]").
            // base_value only ever holds ONE number (whichever rank SimC's dump treats as
            // canonical) — these two columns capture the full per-rank picture, read by
            // ModuleSpellReferenceService at render time once it knows which rank a given build
            // actually has selected (see TalentSelectionService::selectedRanks()). 'set' means
            // rank_values[rank-1] IS the final value (replaces base_value); 'mul' means
            // base_value is multiplied by rank_values[rank-1] (confirmed both ops occur in the
            // dataset, e.g. "[op=mul, values=(1, 2)]").
            $table->string('rank_op')->nullable()->after('pvp_coefficient');
            $table->json('rank_values')->nullable()->after('rank_op');
        });

        Schema::table('spell_relationships', function (Blueprint $table) {
            // Which effect (by index) on the SOURCE spell produced this relationship — already
            // known at import time (SpellDataFileParser::parseSpellRefs()' effect_index, and
            // importCategoryRelationships()'s own $effect array) but never persisted before.
            // Needed so that when the source effect turns out to be rank-scaled (rank_op/
            // rank_values above), ModuleSpellReferenceService can re-look-up that specific effect
            // at render time and compute the magnitude for whatever rank the current build
            // actually selected — a value that can only be known per-build/per-viewer, never as
            // a single static number baked in at import time.
            $table->unsignedInteger('effect_index')->nullable()->after('modifier_unit');
        });
    }

    public function down(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->dropColumn(['rank_op', 'rank_values']);
        });

        Schema::table('spell_relationships', function (Blueprint $table) {
            $table->dropColumn('effect_index');
        });
    }
};
