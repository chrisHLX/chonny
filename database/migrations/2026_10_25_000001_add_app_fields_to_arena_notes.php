<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes from the desktop app (2026-10-25), which took over the developer app's notes: written
 * during a game, a Ctrl+Shift+M mark, or one kept for the next game. Those do not know their game
 * when they are written (the round is not uploaded yet), so they carry the time instead and
 * GameCardService::attachNotes() files them the way it already files notes.json:
 *
 *  - `noted_at`: when it was written, on the player's PC clock, which is the combat log's clock and
 *    so the clock `played_at` is stored in. Kept as that wall-clock time, never converted.
 *  - `round_start`: the round it was written in, when the app knew it (its ARENA_MATCH_START).
 *  - `kind`: note, mark (a moment, perhaps with no text yet) or next (for the next game).
 *  - `client_id`: the app's own id for it, so a note sent twice (a retry) is stored once, and a mark
 *    written about later updates in place.
 *
 * `lobby_id` becomes nullable: a note from the browser or on a past game still names its game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arena_notes', function (Blueprint $table) {
            $table->string('lobby_id')->nullable()->change();
            $table->string('kind', 10)->default('note');
            $table->dateTime('noted_at')->nullable();
            $table->dateTime('round_start')->nullable();
            $table->string('client_id', 64)->nullable();
            $table->unique(['user_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('arena_notes', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_id']);
            $table->dropColumn(['kind', 'noted_at', 'round_start', 'client_id']);
        });
    }
};
