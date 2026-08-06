<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spells.name had no index at all despite being queried constantly by
 * ModuleSpellReferenceService's same-named-sibling recovery (categorize(), findEffectByIndex())
 * and resolveSpellByName() — confirmed via a real WowComps page render (2026-08-06) doing a
 * full 12,527-row table scan (~49ms) per lookup, hundreds of times per render. Composite on
 * (name, patch_id) since every real call site filters by both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->index(['name', 'patch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropIndex(['name', 'patch_id']);
        });
    }
};
