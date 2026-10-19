<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sitemap lists the 3v3, 2v2 and RBG leaderboard players (~22,000 of ~385,000 entries). Without
 * an index led by the bracket that query grouped every entry: 8.1s on production's one core every
 * time the sitemap's six-hour cache ran out. This one covers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pvp_leaderboard_entries', function (Blueprint $table) {
            $table->index(['bracket', 'region', 'realm_slug', 'name', 'rating'], 'pvp_lb_bracket_player');
        });
    }

    public function down(): void
    {
        Schema::table('pvp_leaderboard_entries', function (Blueprint $table) {
            $table->dropIndex('pvp_lb_bracket_player');
        });
    }
};
