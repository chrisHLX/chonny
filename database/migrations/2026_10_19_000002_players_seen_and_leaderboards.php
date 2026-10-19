<?php

use App\Http\Services\RoundPlayerIndexer;
use App\Models\ArenaRound;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The player pages' own data (2026-10-19, Chriso: "we have our own database to link games and
 * talents to players and rating").
 *
 * arena_round_players: every player in every uploaded round, one row each, with what the round
 * measured for them. A player page reads its games from here and never decodes a round's ~130 KB
 * payload. Written whenever a round's payload is saved (ArenaRound::booted), and backfilled below.
 * `realm_key` is the realm with everything but letters and digits taken out, where the log's
 * "AzjolNerub" and Blizzard's "azjolnerub" or "Azjol-Nerub" meet.
 *
 * pvp_leaderboard_entries: Blizzard's PvP leaderboards (every bracket, every Shuffle and Blitz spec,
 * about 5,000 a board), the latest entry per character per board, refreshed daily
 * (wow:pvp-leaderboards).
 *
 * player_rating_history: a character's rating in a bracket over a season, keyed by Blizzard's
 * character id so a leaderboard player has one without a battlenet_characters row. ONE ROW PER
 * CHARACTER, BRACKET AND SEASON, the changes appended as points: the leaderboards hold ~190,000
 * entries a region, and a row per change (the day-old player_rating_snapshots, ~200 bytes each)
 * would have grown by tens of MB a day. A point is ~15 bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_round_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arena_round_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('match_id', 64);
            $table->string('bracket', 32);
            $table->timestamp('played_at')->nullable();
            $table->string('guid', 48);
            $table->string('name', 32);
            $table->string('realm', 64);
            $table->string('realm_key', 64);
            $table->string('region', 4);
            $table->unsignedSmallInteger('spec_external_id')->nullable();
            $table->string('class_slug', 24)->nullable();
            $table->string('side', 8);
            $table->boolean('healer')->default(false);
            $table->boolean('won')->nullable();
            $table->unsignedSmallInteger('mmr')->nullable();
            $table->unsignedSmallInteger('ilvl')->nullable();
            $table->unsignedBigInteger('damage')->default(0);
            $table->unsignedBigInteger('healing')->default(0);
            $table->unsignedBigInteger('damage_taken')->default(0);
            $table->unsignedTinyInteger('deaths')->default(0);
            $table->unsignedSmallInteger('seconds')->default(0);
            $table->json('pvp_talents')->nullable();

            $table->unique(['arena_round_id', 'guid']);
            $table->index(['region', 'realm_key', 'name']);
            $table->index('name');
            $table->index('match_id');
        });

        Schema::create('pvp_leaderboard_entries', function (Blueprint $table) {
            $table->id();
            $table->string('region', 4);
            $table->unsignedSmallInteger('season');
            $table->string('bracket', 64);
            $table->unsignedBigInteger('blizzard_character_id');
            $table->string('name', 32);
            $table->string('realm_slug', 64);
            $table->unsignedSmallInteger('spec_external_id')->nullable();
            $table->string('class_slug', 24)->nullable();
            $table->string('faction', 10)->nullable();
            $table->unsignedInteger('rank');
            $table->unsignedSmallInteger('rating');
            $table->unsignedInteger('won')->default(0);
            $table->unsignedInteger('lost')->default(0);
            $table->unsignedSmallInteger('tier_id')->nullable();
            $table->timestamp('seen_at');

            $table->unique(['region', 'season', 'bracket', 'blizzard_character_id'], 'pvp_lb_unique');
            $table->index(['region', 'realm_slug', 'name']);
            $table->index('name');
            $table->index(['region', 'blizzard_character_id']);
        });

        Schema::create('player_rating_history', function (Blueprint $table) {
            $table->id();
            $table->string('region', 4);
            $table->unsignedBigInteger('blizzard_character_id');
            $table->string('bracket', 64);
            $table->unsignedSmallInteger('season');
            // [[unix time, rating, won, lost], ...], oldest first, one point per change seen.
            $table->json('points');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['region', 'blizzard_character_id', 'bracket', 'season'], 'prh_unique');
            $table->index(['region', 'season']);
        });

        // The day-old one-row-per-change table (2026_10_19_000001) into points, then dropped.
        $rows = DB::table('player_rating_snapshots as s')
            ->join('battlenet_characters as c', 'c.id', '=', 's.battlenet_character_id')
            ->whereNotNull('s.season')
            ->orderBy('s.recorded_at')->orderBy('s.id')
            ->get(['c.region', 'c.blizzard_character_id', 's.bracket', 's.season', 's.rating', 's.won', 's.lost', 's.recorded_at']);
        foreach ($rows->groupBy(fn ($r) => $r->region.'|'.$r->blizzard_character_id.'|'.$r->bracket.'|'.$r->season) as $points) {
            $first = $points->first();
            DB::table('player_rating_history')->insert([
                'region' => $first->region,
                'blizzard_character_id' => $first->blizzard_character_id,
                'bracket' => $first->bracket,
                'season' => $first->season,
                'points' => json_encode($points->map(fn ($p) => [strtotime($p->recorded_at), (int) $p->rating, (int) $p->won, (int) $p->lost])->values()->all()),
                'updated_at' => now(),
            ]);
        }
        Schema::dropIfExists('player_rating_snapshots');

        // Every round already uploaded: about 800 on 19 Oct, a few seconds.
        $indexer = app(RoundPlayerIndexer::class);
        ArenaRound::query()->orderBy('id')->chunkById(50, fn ($rounds) => $rounds->each(fn ($r) => $indexer->index($r)));
    }

    public function down(): void
    {
        Schema::dropIfExists('player_rating_history');
        Schema::create('player_rating_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battlenet_character_id')->constrained()->cascadeOnDelete();
            $table->string('bracket', 64);
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedSmallInteger('rating');
            $table->unsignedInteger('won')->default(0);
            $table->unsignedInteger('lost')->default(0);
            $table->timestamp('recorded_at');
            $table->index(['battlenet_character_id', 'bracket', 'recorded_at'], 'prs_character_bracket_time');
        });
        Schema::dropIfExists('pvp_leaderboard_entries');
        Schema::dropIfExists('arena_round_players');
    }
};
