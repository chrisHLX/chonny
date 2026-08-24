<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves TalentSelectionService's global spell-cache version counter out of the Redis cache store
 * and into its own tiny DB table — see CLAUDE.md's "Spell cache version counter moved off the
 * flushable cache store" section (2026-08-23) for the full incident. In short: the counter used
 * to live at Cache::forever('wow_spell_cache_version', ...), i.e. inside the exact same Redis
 * store that a plain `php artisan cache:clear` flushes wholesale — so any bump (automatic, via
 * ImportSpellData/ApplyDefaultTalents/RefreshSpellIcons, or manual) was silently erased back to
 * its default of 1 by the next ordinary cache:clear, for any reason, run afterward. This caused a
 * real production incident: stale wow_spell_references:* cache entries (missing a 'cooldown' key
 * added to modifier arrays by an older code shape) kept being served under the never-truly-bumped
 * v1 cache key until each individual key's own 6h TTL happened to lapse.
 *
 * A single-row table is immune to cache:clear / config:clear / optimize:clear entirely — only an
 * actual migration rollback or direct DB write can reset it now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wow_spell_cache_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        // Single seed row (id=1) that TalentSelectionService always reads/writes — created here
        // so the service never has to distinguish "row missing" from "version is 1" as a first-run
        // case. bumpSpellCacheVersion() defensively re-creates this row if it's ever missing.
        DB::table('wow_spell_cache_state')->insert([
            'id' => 1,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('wow_spell_cache_state');
    }
};
