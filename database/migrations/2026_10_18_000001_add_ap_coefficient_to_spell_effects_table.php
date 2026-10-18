<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "AP Coefficient: 1.64" — the attack-power twin of sp_coefficient (2026-08-02). A physical or
 * attack-power-scaled effect carries Base and Scaled Value 0 and its real size here, so without it
 * Blood Boil, Death Coil and every melee strike read "(varies)" where a caster's spell reads "≈78%
 * of Spell Power" (2026-10-18: about 105 of the 462 kit spells still showing "(varies)").
 *
 * The name contains "spell" on purpose: deploy.sh re-imports the spell data when a spell migration
 * ships, which is what fills the column on production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->decimal('ap_coefficient', 8, 5)->nullable()->after('pvp_coefficient');
        });
    }

    public function down(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->dropColumn('ap_coefficient');
        });
    }
};
