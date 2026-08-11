<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spells.pvp_duration_seconds` — curated real in-PvP CC duration, same tier and same
 * hand-authored-one-at-a-time discipline as `dr_category` (see the 2026_08_11_000001 migration's
 * docblock). Deliberately NOT derived from `duration_seconds` via a flat `MIN(duration, 6)`
 * formula, even though the first batch of curated values (see CLAUDE.md's "PvP CC duration cap"
 * section) mostly land exactly on CcChainBuilder::PVP_CC_DURATION_CAP_SECONDS — Kidney Shot is a
 * confirmed counter-example: its stored `duration_seconds` is 3.00 (a low-combo-point value from
 * the raw dump, not what a player experiences at or near max combo points), but the domain
 * expert's real answer is 6s. A formula over unreliable input still produces an unreliable
 * output; only a verified, spell-specific number belongs here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->decimal('pvp_duration_seconds', 4, 1)->nullable()->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('pvp_duration_seconds');
        });
    }
};
