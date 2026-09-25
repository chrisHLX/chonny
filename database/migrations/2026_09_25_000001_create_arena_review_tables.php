<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signed-in player's own arena games.
 *
 * WHY THESE ARE DATABASE ROWS AND NOT FILES. Reviews were briefly committed to the repo, which
 * published them — a review names five other players with their talents and their gear. They are
 * user data, owned by whoever uploaded them, so they belong in a table with a `user_id` and
 * nowhere else. This also satisfies CLAUDE.md rule 14 more directly than the committed artifact
 * did: a database row is present in production by definition, so there is no gitignored-input
 * trap for the page to fall into.
 *
 * TWO TABLES, BECAUSE UPLOAD IS PER ROUND AND A REVIEW IS PER LOBBY. The browser cannot send a
 * 64MB combat log — production's nginx has no `client_max_body_size` for this site (so 1MB) and
 * PHP is at `upload_max_filesize=2M` — so it splits the log itself and posts one round at a time,
 * about 700KB gzipped. Rounds therefore arrive separately and out of any useful order, and the
 * review over a lobby's six can only be assembled once they are all in. `arena_rounds` is that
 * staging area; `arena_reviews` is the assembled result the page reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The lobby a round belongs to, and its place in it.
            //
            // NEITHER IS TAKEN FROM THE CLIENT, AND NEITHER CAN COME FROM THE ROUND ALONE. An
            // uploaded round arrives on its own, so the "lobby's first round" trick the local
            // ingester uses — it sees the whole file in order — is not available: every round
            // derives a lobby id of itself and a sequence of 1. Measured on a real upload, that
            // turned one 5-1 lobby into six separate one-round games.
            //
            // So the server groups them instead, by `roster_key` (below) plus time proximity, and
            // numbers them by when they were played. A dumb client stays dumb.
            $table->string('lobby_id', 32)->index();
            $table->unsignedTinyInteger('sequence')->default(1);

            // The six players and the arena, hashed. Two rounds with the same roster on the same
            // map within a few minutes are the same lobby — there is no other way for the same six
            // people to meet on the same map twice in that window.
            $table->string('roster_key', 32)->index();

            // The match's own derived id — stable across re-uploads of a growing log, which is
            // what makes uploading the same file twice a no-op instead of a duplicate.
            $table->string('match_id', 32);

            $table->string('bracket', 40);
            $table->timestamp('played_at')->nullable();

            // The whole derived round: metadata, per-player throughput, and each player's
            // COMBATANT_INFO build/gear/stats. Kept so a review can be REASSEMBLED after a
            // parser fix without asking the player to upload anything again — the raw log is not
            // retained, so this is the only second chance.
            $table->longText('payload');

            $table->timestamps();

            // Re-uploading the same log must not duplicate. Scoped per user: two players in the
            // same lobby each legitimately own their own copy of that round.
            $table->unique(['user_id', 'match_id']);
        });

        Schema::create('arena_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Which of the player's characters this game was played on. Nullable on purpose: the
            // link is derived by matching the log's own `affiliation 1` player against the
            // account's synced characters, and a character that has not been synced yet, or a
            // Battle.net account that was never linked, must not stop the review existing.
            $table->foreignId('battlenet_character_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('lobby_id', 32);

            // The name the combat log itself carries, so a review still says who played it when
            // there is no linked character to point at.
            $table->string('character_name')->nullable();

            $table->string('bracket', 40);
            $table->timestamp('played_at')->nullable();
            $table->unsignedTinyInteger('rounds')->default(0);
            $table->unsignedTinyInteger('rounds_won')->default(0);
            $table->unsignedTinyInteger('rounds_lost')->default(0);
            $table->unsignedSmallInteger('mirrors')->default(0);

            $table->longText('payload');

            $table->timestamps();

            $table->unique(['user_id', 'lobby_id']);
            $table->index(['user_id', 'played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_reviews');
        Schema::dropIfExists('arena_rounds');
    }
};
