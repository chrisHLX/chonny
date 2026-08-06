<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            // SimC's own structured spell model carries these as first-class fields
            // (spelleffect_data_t.sp_coefficient/ap_coefficient upstream) — confirmed present in
            // our raw .txt dumps as "SP Coefficient: 0.8109" / "PvP Coefficient: 1.059" text,
            // just never parsed. When base_value/scaled_value are both 0 (common for
            // spell-power-scaled damage/healing effects — the real number only exists once
            // multiplied by the caster's actual Spell Power, which this system doesn't have),
            // this is what lets the resolver show "≈X% of Spell Power" instead of leaving the
            // description as an unresolved formula. See ModuleSpellReferenceService.
            $table->decimal('sp_coefficient', 8, 5)->nullable()->after('scaled_value');
            $table->decimal('pvp_coefficient', 8, 5)->nullable()->after('sp_coefficient');
        });

        Schema::table('spells', function (Blueprint $table) {
            // Raw "Variables : $var=$?a<id>[...][...]" block text, captured separately from
            // description (previously leaked onto the end of it — a real parsing bug, fixed
            // alongside this). Lets ModuleSpellReferenceService pull out real talent/spell names
            // referenced by a formula's conditionals (e.g. Penance's Power of the Dark Side,
            // Twilight Equilibrium, Castigation, Harsh Discipline) even when the formula itself
            // can't be reduced to one number.
            $table->text('variables')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->dropColumn(['sp_coefficient', 'pvp_coefficient']);
        });

        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('variables');
        });
    }
};
