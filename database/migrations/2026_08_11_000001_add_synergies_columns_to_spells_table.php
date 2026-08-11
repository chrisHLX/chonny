<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three new curation-tier columns for the Synergies tab (CC-chain builder) — see CLAUDE.md's
 * "Synergies tab: CC-chain data model" section for the full investigation and design record.
 *
 * dr_category — deliberately a plain nullable string, NOT a DB-level enum. Two reasons:
 *   1. The real diminishing-returns taxonomy is a manual_knowledge-tier fact (same tier as
 *      Hero Talents / cooldown durations elsewhere in this project) that the domain expert
 *      authors directly — it is explicitly NOT to be researched or proposed by AI. No
 *      authoritative category list exists yet to bake into a DB enum.
 *   2. spells.mechanic (added 2026_08_06_000002) LOOKS like it could serve as dr_category
 *      directly but does not — verified by direct spot-check, not assumed: Fear's real DR
 *      category is Disorient, but its mechanic value is "Flee"; Cyclone's real DR category is
 *      Incapacitate, but its mechanic value is "Banish". 2 of 4 spot-checked spells disagreed —
 *      an unknown-quality signal on a tiny sample, not a reliable proxy. mechanic may be shown
 *      to a curator as a labeled hint ("Blizzard mechanic: Flee — confirm DR category"), never
 *      pre-filled as a default value, per the same anchoring-risk reasoning documented in
 *      CLAUDE.md.
 *
 * cast_type — a real, closed, structurally-derivable fact (not a judgment call): every spell's
 * raw SimC dump has a "Cast Time : X seconds" line when it has a cast time, and no such line at
 * all when it's instant (confirmed directly: Kidney Shot's raw entry has no Cast Time line).
 * Safe to bake as a DB enum since the two values are exhaustive and stable.
 *
 * chain_target — the three values (healer / kill_target / both) were explicitly specified in
 * the original feature spec, not invented here. CORRECTED 2026-08-11 once a real `both` spell
 * existed to test against (Stuns — Kidney Shot, Cheap Shot, etc. — genuinely flexible, not
 * fixed to one role): "both" means a spell is an independent candidate for EITHER chain, and
 * DOES appear in both `kill_target_chain` and `healer_chain` at once when both are built — this
 * is the correct, useful behavior (a stun shown as a valid option in either role), not a bug.
 * The original claim here ("never auto-duplicated into both at once") was written before any
 * `both`-tagged spell existed to verify it against and was wrong — see
 * WowComps::getSynergiesProperty()'s docblock and CLAUDE.md for the full correction. A single
 * column is still sufficient (not a join table) because the spec's own resolution of "may vary
 * per comp context" is the `both` value itself, not per-comp storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->string('dr_category')->nullable()->after('mechanic');
            $table->enum('cast_type', ['instant', 'cast'])->nullable()->after('dr_category');
            $table->enum('chain_target', ['healer', 'kill_target', 'both'])->nullable()->after('cast_type');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn(['dr_category', 'cast_type', 'chain_target']);
        });
    }
};
