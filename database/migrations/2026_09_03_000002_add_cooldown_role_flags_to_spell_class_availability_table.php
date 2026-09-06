<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materializes, per (spell, class, spec) row, the exact same predicate WowComps::isMainSpell()
 * already computes live for its "Offensive"/"Defensive" branches — so "grab Assassination Rogue
 * spells that are Offensive Cooldowns" becomes a single boolean-column WHERE clause instead of a
 * live PHP recomputation. Lives on spell_class_availability, not spells, because — unlike
 * spells.category (added alongside this migration) — this genuinely IS spec-scoped: a spell
 * observed as a real, pressed cooldown for one spec (is_priority = true, from real arena-log
 * cast evidence) may not be for a different spec that also has access to the same spell_id.
 *
 * `is_priority` — real, observed-cast evidence for THIS spec specifically (see
 * ArenaLogService::isPrioritySpell()/spellUsageIds()) — is this spell_id (or a same-named
 * sibling) actually pressed by real players of this class+spec, not just theoretically available.
 *
 * `is_offensive_cooldown` / `is_defensive_cooldown` — the full WowComps::isMainSpell() predicate,
 * precomputed: spells.category matches ('Offensive'/'Defensive') AND the arena-log-verified
 * ArenaLogService::offensiveDefensiveClassification() signal agrees AND is_priority is true AND
 * the cooldown clears WowComps::MIN_COOLDOWN_TAB_SECONDS (or a named exception). All four inputs
 * are patch-import-time facts, none of them viewer/build-dependent, so this is safe to compute
 * once and never risks drifting from what the live Cooldowns tab actually shows — see
 * ImportSpellData::materializeSpellShape() for the write side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_class_availability', function (Blueprint $table) {
            $table->boolean('is_priority')->default(false)->after('source');
            $table->boolean('is_offensive_cooldown')->default(false)->after('is_priority');
            $table->boolean('is_defensive_cooldown')->default(false)->after('is_offensive_cooldown');
        });
    }

    public function down(): void
    {
        Schema::table('spell_class_availability', function (Blueprint $table) {
            $table->dropColumn(['is_priority', 'is_offensive_cooldown', 'is_defensive_cooldown']);
        });
    }
};
