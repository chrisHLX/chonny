<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The level of play a guide is drawn from, and how much play (see guides-from-play.md).
 *
 * A guide drawn from observed games is validated by the level of those games: games where every
 * player is a Gladiator describe Gladiator-level play. The level lives on the guide so the page can
 * say it and a listing can filter by it, rather than it being buried in the title.
 *
 * - evidence_level: a plain string ("Gladiator"), not a DB enum — the vocabulary will grow (Elite,
 *   Duelist) and the suite runs on SQLite (see CLAUDE.md, "Never use a DB enum").
 * - evidence_games: how many games it rests on. The number grows as more games are played.
 * - evidence_note: one line of provenance, e.g. "26 Sep 2026 · 4 won, 3 lost".
 *
 * All nullable: a guide not drawn from observed games has none of them, and says nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->string('evidence_level', 32)->nullable()->after('authored_build_version');
            $table->unsignedSmallInteger('evidence_games')->nullable()->after('evidence_level');
            $table->string('evidence_note', 160)->nullable()->after('evidence_games');
        });
    }

    public function down(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropColumn(['evidence_level', 'evidence_games', 'evidence_note']);
        });
    }
};
