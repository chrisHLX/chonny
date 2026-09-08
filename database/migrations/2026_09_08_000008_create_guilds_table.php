<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guilds: a named group of players who can see each other's guides.
 *
 * The unit a real arena team already organises itself around. Without one, sharing a guide with
 * your five regular partners meant adding all five to every guide by email, one at a time
 * (user_guide_viewers) — which is why guild membership is the thing that grants access, rather
 * than the guild being a folder guides are copied into.
 *
 * JOINING IS BY LINK, DELIBERATELY. A guild's slug is its invite: anyone who has it can join, and
 * the owner can remove people. An approval queue is a real feature but a different one, and
 * building it first would have made the simplest case (paste the link in Discord) the slow path.
 * Nothing sensitive is behind a guild — the worst outcome of an unwanted join is that somebody
 * reads a guide the owner can revoke them from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guilds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 60);

            // The invite. Unique globally (unlike a guide slug, which is unique per author) because
            // a guild URL carries no username to namespace it.
            $table->string('slug', 80)->unique();
            $table->string('description', 300)->nullable();
            $table->timestamps();

            $table->index('owner_id');
        });

        Schema::create('guild_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Plain string, never a DB enum — an ALTER on a MySQL enum is invalid on SQLite, and
            // the test suite runs on SQLite.
            $table->string('role', 16)->default('member');
            $table->timestamps();

            $table->unique(['guild_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_user');
        Schema::dropIfExists('guilds');
    }
};
