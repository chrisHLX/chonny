<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The comp a user guide is written for — one to three specs, so a chain or a "go" can be authored
 * for 2v2 or 3v3 rather than for a single spec in isolation.
 *
 * REPLACES user_guides.class_id / spec_id, which are dropped here. Those were added one day
 * earlier (2026-09-07, step 1) as a single nullable spec, with a docblock arguing that a CC chain
 * "may span a comp and have neither". That reasoning was right about the problem and wrong about
 * the shape: a nullable single spec can record that a guide ISN'T scoped to one spec, but it
 * cannot record WHICH three specs it is scoped to, which is what a comp guide actually is. Rather
 * than leave a half-used column behind and derive the roster somewhere else — the dangling-field
 * pattern this codebase has had to clean up before (see recommended_module) — the column goes and
 * this table becomes the single source of truth. Nothing is in production and one dev row existed,
 * so this is a straight replacement, not a data migration.
 *
 * Bracket (2v2 vs 3v3) is DERIVED from how many members exist, never stored. A stored bracket
 * would be a second thing to keep in sync with the roster and would immediately be able to
 * disagree with it.
 *
 * `position` is the comp slot and is uniquely constrained per guide — unlike user_guide_blocks,
 * where position is a drag-reorderable sequence that legitimately passes through duplicates
 * mid-rewrite. A roster slot is assigned, not dragged, so there is no transient state to protect
 * and the constraint is worth having.
 *
 * The same spec may legitimately appear in two slots (a real 3v3 can run two of the same spec), so
 * there is deliberately NO unique constraint on (user_guide_id, spec_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_guide_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_guide_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('spec_id')->constrained('specializations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_guide_id', 'position']);
            $table->index('spec_id');
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropForeign(['class_id']);
            $table->dropForeign(['spec_id']);
            // Dropped together with the composite index that led with spec_id — MySQL will not
            // drop a column an index still depends on.
            $table->dropIndex('user_guides_spec_id_status_index');
            $table->dropColumn(['class_id', 'spec_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('spec_id')->nullable()->constrained('specializations')->nullOnDelete();
            $table->index(['spec_id', 'status'], 'user_guides_spec_id_status_index');
        });

        Schema::dropIfExists('user_guide_members');
    }
};
