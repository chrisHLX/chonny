<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A linked Battle.net account, and the WoW characters on it.
 *
 * NO TOKEN IS STORED, DELIBERATELY. Battle.net issues no refresh tokens, so a stored access token is
 * a 24-hour credential with nothing to renew it — keeping it would buy one day of convenience for a
 * permanent secret sitting in the database. The user token is used exactly once, in the OAuth
 * callback, for the one call that genuinely needs it: /profile/user/wow, the account's own
 * character list, which is what proves these characters belong to this person. Everything after
 * that (ratings, exp, gear, talents) comes from Blizzard's PUBLIC character endpoints on the app's
 * own client-credentials token, so refreshing a character never needs the player to log in again.
 * Refreshing the LIST (a new alt) does — that is a re-link, and Blizzard remembers consent.
 *
 * ONE BATTLE.NET ACCOUNT PER MINDCOLLECTOR ACCOUNT, AND VICE VERSA — both unique. The second one is
 * the one that matters: without it, two MindCollector accounts could each claim the same characters
 * and attribute guides to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battlenet_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // The OAuth `sub` — Blizzard's stable numeric account id. The battletag can change
            // (paid name change); this cannot.
            $table->unsignedBigInteger('battlenet_id')->unique();
            $table->string('battletag', 64);
            $table->timestamp('characters_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('battlenet_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battlenet_account_id')->constrained()->cascadeOnDelete();

            // us | eu | kr | tw. Plain string — the character endpoints are per-region hosts.
            $table->string('region', 4);
            $table->unsignedBigInteger('blizzard_character_id');
            $table->string('name', 32);
            $table->string('realm_slug', 64);
            $table->string('realm_name', 64);

            // Resolved at sync time for icons/colours. nullOnDelete: a character must never vanish
            // because reference data was re-imported.
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('spec_id')->nullable()->constrained('specializations')->nullOnDelete();
            $table->string('race', 32)->nullable();
            $table->string('faction', 16)->nullable();
            $table->unsignedSmallInteger('level')->default(0);
            $table->unsignedSmallInteger('item_level')->nullable();
            $table->unsignedInteger('achievement_points')->nullable();

            // "Exp": the highest personal rating this character has EVER reached, from Blizzard's
            // own lifetime statistics (ids 370 / 595). Columns, not JSON, because "this account's
            // best exp" is a MAX over them.
            $table->unsignedSmallInteger('exp_2v2')->nullable();
            $table->unsignedSmallInteger('exp_3v3')->nullable();

            // The best PvP season rank achievement earned ("Rival II: Midnight Season 1") and its
            // position in the tier ladder, so it can be compared without re-parsing the name.
            $table->string('pvp_rank_title', 96)->nullable();
            $table->unsignedTinyInteger('pvp_rank_tier')->nullable();

            $table->unsignedInteger('arenas_played')->nullable();
            $table->unsignedInteger('arenas_won')->nullable();

            // Snapshots replaced wholesale on every sync, always read with the character, never
            // queried into. Everything inside them is Blizzard's EXTERNAL ids (item, talent, node,
            // spell) — never this database's patch-scoped internal keys, which are reassigned on a
            // patch bump. Talents resolve against the current patch at render time.
            $table->json('ratings')->nullable();
            $table->json('talents')->nullable();
            $table->json('equipment')->nullable();

            $table->timestamp('last_login_at')->nullable();

            // synced_at null means "details never fetched" — the character list alone was stored.
            $table->timestamp('synced_at')->nullable();
            $table->string('sync_error', 255)->nullable();
            $table->timestamps();

            // A character is one row however many times it is synced, and re-parents if it moves
            // to another Battle.net account (a character transfer).
            $table->unique(['region', 'blizzard_character_id']);
            $table->index('battlenet_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battlenet_characters');
        Schema::dropIfExists('battlenet_accounts');
    }
};
