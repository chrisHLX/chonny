<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes a player writes on their own games from the browser (2026-10-22).
 *
 * The desktop app already writes notes, into a notes.json it keeps beside the cards. Those arrive
 * through `wow:game-cards --notes=` and are matched to a round by when they were written. These
 * are the same idea from the website, where there is no file to write: they name their game
 * outright, so they need no timestamp matching at all.
 *
 * Deliberately a plain string body and no structure. A note is whatever the player observed while
 * reviewing; giving it fields now would decide what is worth noticing before anyone has noticed
 * anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The lobby the note is about. Not a foreign key: a lobby is a grouping key on
            // arena_rounds, not a table, and a note should outlive a re-measure of its rounds.
            $table->string('lobby_id');
            $table->text('body');
            $table->timestamps();
            $table->index(['user_id', 'lobby_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_notes');
    }
};
