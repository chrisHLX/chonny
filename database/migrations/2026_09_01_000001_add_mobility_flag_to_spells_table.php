<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spells.is_mobility` — same shape/discipline as `is_peel`/`is_interrupt` (see that migration's
 * own docblock), added 2026-09-01 for the WoW Comps "Mobility" tab. Deliberately its own flag,
 * not folded into `categorize()`'s heuristic bucket of the same name (see
 * ModuleSpellReferenceService::categorize()'s docblock) — categorize()'s 'Mobility' return value
 * is an approximate, effect-signal-driven grouping used only for Active Abilities' display
 * headers (same "not authoritative" posture as its other four buckets); `is_mobility` is the
 * hand-curated, authoritative flag that drives the dedicated tab, matching the existing
 * precedent of Offensive/Defensive Cooldowns using a hand-verified source rather than
 * categorize()'s own looser heuristic for their dedicated tabs.
 *
 * Scope, per direct instruction (2026-09-01): ALL mobility — both directions. A gap-closer
 * (Charge, Infernal Strike, Fel Rush) and a pure escape (Sprint, Blink, Disengage) both count;
 * "we don't know how players will use them" — a gap-closer used to chase is exactly as relevant
 * to "what can this class use to reposition" as an escape used to flee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->boolean('is_mobility')->default(false)->after('is_interrupt');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('is_mobility');
        });
    }
};
