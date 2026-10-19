<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Player lookup (/wow/players, 2026-10-19): anyone can look up any character, as on Check-PvP.
 *
 * A looked-up character is a battlenet_characters row with NO account: the same row a linked
 * account's character is, so one character is one row whoever asked for it, and a player who later
 * links their Battle.net claims the row their own sign-in already finds (storeCharacterList matches
 * on region + Blizzard's character id). `looked_up_at` marks a row someone looked up publicly; only
 * those are listed or put in the sitemap, so linking an account never makes a character public.
 *
 * Rating snapshots: Blizzard serves only the rating a character has now, so a history exists only
 * if it is written down as it is seen. One row per bracket each time a sync finds the rating changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('battlenet_characters', function (Blueprint $table) {
            $table->foreignId('battlenet_account_id')->nullable()->change();
            $table->timestamp('looked_up_at')->nullable()->after('synced_at');
            $table->index(['region', 'realm_slug', 'name']);
            $table->index('looked_up_at');
        });

        Schema::create('player_rating_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battlenet_character_id')->constrained()->cascadeOnDelete();
            // Blizzard's bracket key: 3v3, 2v2, rbg, shuffle-druid-feral, blitz-...
            $table->string('bracket', 64);
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedSmallInteger('rating');
            $table->unsignedInteger('won')->default(0);
            $table->unsignedInteger('lost')->default(0);
            $table->timestamp('recorded_at');

            $table->index(['battlenet_character_id', 'bracket', 'recorded_at'], 'prs_character_bracket_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_rating_snapshots');

        Schema::table('battlenet_characters', function (Blueprint $table) {
            $table->dropIndex(['region', 'realm_slug', 'name']);
            $table->dropIndex(['looked_up_at']);
            $table->dropColumn('looked_up_at');
        });
    }
};
