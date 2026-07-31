<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_relationships', function (Blueprint $table) {
            // Populated at import time wherever magnitude is confidently derivable (PvP-talent
            // description parsing, the subset of Category effect types that convert unambiguously
            // to flat seconds/percent) — see ImportSpellData::importPvpTalentRelationships() and
            // the magnitude threading added to importCategoryRelationships(). Left null wherever
            // magnitude isn't confidently known (never guessed) — the relationship still renders
            // descriptively, same as before this column existed.
            $table->decimal('modifier_value', 10, 2)->nullable()->after('description');
            $table->string('modifier_unit')->nullable()->after('modifier_value'); // seconds|percent|charges
        });
    }

    public function down(): void
    {
        Schema::table('spell_relationships', function (Blueprint $table) {
            $table->dropColumn(['modifier_value', 'modifier_unit']);
        });
    }
};
