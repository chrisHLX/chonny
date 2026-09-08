<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of guide: a comp, and one spec. See App\Enums\UserGuideType for why this is a
 * different axis from the `type` column dropped by create_user_guide_sections_table, rather than
 * that decision being reversed.
 *
 * `type` defaults to 'comp' so every guide written before this migration keeps behaving exactly as
 * it did — they all have a real 2-3 spec roster, which is what a comp guide is.
 *
 * `opponent_spec_id` is the ONE opponent a class guide is written against ("Rogue vs Disc"), and is
 * null for a rotation or technique guide that has no opponent at all. Deliberately a column rather
 * than a second row in user_guide_members: that table is "the comp this guide is written for", and
 * putting an enemy in it would make every roster query — the bracket, the palette, the guide
 * listing's spec icons — quietly wrong. It is also why this is not a Defensives section's own
 * opponent_spec_id: a section's opponent is per-section by design, whereas this one is a fact about
 * the whole guide, and a class guide should not make its author name the same opponent again for
 * every section they add.
 *
 * nullOnDelete rather than cascade: losing a specialization row must never delete somebody's guide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->string('type')->default('comp')->after('user_id');
            $table->foreignId('opponent_spec_id')->nullable()->after('type')
                ->constrained('specializations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opponent_spec_id');
            $table->dropColumn('type');
        });
    }
};
