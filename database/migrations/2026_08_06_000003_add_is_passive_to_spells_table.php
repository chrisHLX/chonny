<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            // Blizzard's own "Passive (6)" Attributes marker — the real signal for "is this
            // something a player presses" vs. "is this a passive aura/buff", replacing the
            // cooldown-presence stand-in <x-spells.table> previously used to split the Spells
            // table into "Active Abilities"/"Buffs & Passives" (that component's own comment
            // already flagged it as "a simple, deterministic stand-in... for a real buff/passive
            // classification we don't have yet"). Found 2026-08-06: Mind Control is a real,
            // actively-cast Crowd Control ability with no cooldown timer at all in actual WoW
            // (balanced by its channel time and diminishing returns, not a cooldown) — the old
            // cooldown-presence heuristic filed it under "Buffs & Passives", which reads as
            // clearly wrong for something you press on an enemy player.
            $table->boolean('is_passive')->default(false)->after('mechanic');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('is_passive');
        });
    }
};
