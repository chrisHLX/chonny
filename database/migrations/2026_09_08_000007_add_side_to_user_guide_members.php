<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The enemy team, modelled as a second side of the roster rather than a separate table.
 *
 * A guide is about a matchup. Before this the enemy could only be ONE spec, named per Defensives
 * section (a comp guide) or once for the whole guide (a class guide) — so "RMP vs Thug Cleave"
 * was not expressible at all, which is what this adds.
 *
 * Reusing user_guide_members means the enemy side inherits the spec picker, the class colours and
 * per-member talent builds with no new machinery. `side` defaults to 'team', so every existing row
 * is already correct and nothing needs backfilling.
 *
 * THE UNIQUE KEY MOVES rather than being added to: position is unique per (guide, SIDE), because
 * your slot 0 and their slot 0 are different slots. Keeping the old (guide, position) key would
 * have made an enemy team structurally impossible to save — the second side's slot 0 would collide
 * with the first's.
 *
 * A roster slot stays uniquely constrained (unlike user_guide_blocks.position, which is not),
 * because a slot is ASSIGNED rather than dragged: there is no transient mid-reorder duplicate to
 * survive here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guide_members', function (Blueprint $table) {
            $table->string('side', 16)->default('team')->after('user_guide_id')->index();
        });

        // ORDER MATTERS: the new key is created BEFORE the old one is dropped. MySQL refuses to
        // drop an index a foreign key depends on, and the user_guide_id FK was leaning on the old
        // (user_guide_id, position) unique. The replacement also leads with user_guide_id, so it
        // can carry the FK — but only once it exists.
        Schema::table('user_guide_members', function (Blueprint $table) {
            $table->unique(['user_guide_id', 'side', 'position']);
        });

        Schema::table('user_guide_members', function (Blueprint $table) {
            $table->dropUnique('user_guide_members_user_guide_id_position_unique');
        });
    }

    public function down(): void
    {
        // Enemy rows would collide on the restored (guide, position) key, so they go first. They
        // are the only thing this migration made possible, so nothing else is lost.
        \Illuminate\Support\Facades\DB::table('user_guide_members')->where('side', 'enemy')->delete();

        // Same ordering constraint in reverse.
        Schema::table('user_guide_members', function (Blueprint $table) {
            $table->unique(['user_guide_id', 'position']);
        });

        Schema::table('user_guide_members', function (Blueprint $table) {
            $table->dropUnique(['user_guide_id', 'side', 'position']);
            $table->dropIndex(['side']);
            $table->dropColumn('side');
        });
    }
};
