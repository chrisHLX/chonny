<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The highest title a character's account has earned in each bracket that HAS a title of its
     * own: 3v3 (Gladiator, and its Rank 1 form "Galactic Gladiator: Midnight Season 1") and Solo
     * Shuffle (Legend / "Galactic Legend: …"). Every rank below those — Combatant to Elite — is
     * earned from any rated bracket, so no achievement can say which bracket it came from, and
     * `pvp_rank_title` remains the right home for them.
     *
     * JSON rather than columns: it is only ever read with the character, never queried into.
     */
    public function up(): void
    {
        Schema::table('battlenet_characters', function (Blueprint $table) {
            $table->json('arena_titles')->nullable()->after('pvp_rank_tier');
        });
    }

    public function down(): void
    {
        Schema::table('battlenet_characters', function (Blueprint $table) {
            $table->dropColumn('arena_titles');
        });
    }
};
