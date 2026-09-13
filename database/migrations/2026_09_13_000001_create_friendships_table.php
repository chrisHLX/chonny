<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friends: two players who have both agreed to it.
 *
 * ONE ROW PER PAIR, whichever of the two asked. requester_id is who sent it, addressee_id is who
 * has to accept, and the row flips from pending to accepted in place — there is never a second,
 * reverse row. The unique key below only covers one direction, so the "no reverse row" half of that
 * is enforced in FriendshipService, which checks both directions before inserting and turns "B asks
 * A while A's request to B is still pending" into an accept rather than a duplicate.
 *
 * WHY A FRIENDSHIP IS MUTUAL AND NOT A FOLLOW. Friendship is what lets somebody edit your guides
 * (see add_collaboration_to_user_guides), so it has to be something both people agreed to. A
 * one-sided follow would hand edit access to anybody who clicked a button.
 *
 * Declined requests are deleted rather than kept as a "declined" status, so a declined player can
 * ask again later, and nobody is shown a record of having been turned down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('addressee_id')->constrained('users')->cascadeOnDelete();

            // Plain string, never a DB enum — an ALTER on a MySQL enum is invalid on SQLite, and the
            // test suite runs on SQLite. Values come from App\Enums\FriendshipStatus.
            $table->string('status', 16)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['requester_id', 'addressee_id']);
            $table->index(['addressee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
