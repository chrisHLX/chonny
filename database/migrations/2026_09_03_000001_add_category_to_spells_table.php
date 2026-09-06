<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materializes ModuleSpellReferenceService::categorize()'s output onto the spell row itself,
 * computed once at import time instead of re-derived on every single request. categorize()'s
 * own signature (`categorize(Spell $spell): string`) takes no build/viewer argument at all — its
 * output can only ever change on a re-import or a curated-fact edit, never between two viewers
 * looking at the same spell, so recomputing it live on every page load was pure waste (and meant
 * "Offensive/Defensive/Crowd Control/Mobility/Utility/Other" had no SQL-queryable home anywhere).
 *
 * One of six fixed values: 'Offensive', 'Defensive', 'Crowd Control', 'Mobility', 'Utility',
 * 'Other'. Nullable only until the first post-migration import populates it; never null
 * afterward for a real spell row. See ImportSpellData::materializeSpellShape() for the write
 * side, and CLAUDE.md's 2026-09-03 "spell shape" section for the full design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->string('category')->nullable()->after('dr_category');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
