<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            // From the spell's own "Attributes" line — distinguishes a real, player-facing
            // ability from an internal SimC sub-spell (a channeled ability's own separate
            // damage-bolt/heal-bolt/visual helper spell_id, sharing the parent's display name —
            // confirmed on Penance and Ultimate Penitence, both of which are actually several
            // spell_id records sharing one name). See SpellDataFileParser and
            // ModuleSpellReferenceService::resolveSpellByName().
            $table->boolean('not_in_spellbook')->default(false)->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('not_in_spellbook');
        });
    }
};
