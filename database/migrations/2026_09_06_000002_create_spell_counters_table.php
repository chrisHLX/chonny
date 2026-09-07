<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "What counters this spell" as a real, queryable relationship instead of a page-local
 * computation.
 *
 * Before this table, the only place in the codebase that could answer "what counters Kidney Shot"
 * was ClaudesCounters::getCounterableByClassProperty(), which rebuilt four in-memory candidate
 * pools (usable-while-CC'd, mechanic-immunity, dodge/parry, school-immunity) on every single page
 * load and then matched them per CC spell. The matching itself was correct — the problem was that
 * it lived inside one Livewire component, so the spell detail modal, SpellFinder, and everything
 * else structurally could not ask the question at all.
 *
 * That was never a computation: it is a relationship between two spells, and every input to it
 * (dr_category, usable_while_cc, Mechanic Immunity effects, School Immunity effects, Modify
 * Dodge%/Parry% effects, school, bypasses_active_defense) is BUILD-INDEPENDENT — none of it
 * depends on a viewer's talent selection. So it is materialized once at import time, the same way
 * spells.category already is, and read as an Eloquent relation everywhere.
 *
 * mechanism is a plain string, deliberately NOT a DB enum — a MySQL `ALTER TABLE ... MODIFY
 * COLUMN` on an enum is invalid on SQLite, and phpunit.xml runs the whole suite against in-memory
 * SQLite (this exact mistake broke every test on 2026-08-06 when the source enum on
 * spell_class_availability was extended). Current values, written by SpellCounterIndexer:
 *
 *   immunity_mechanic  the counter grants Mechanic Immunity matching the CC's dr_category
 *   immunity_school    the counter grants School Immunity covering the CC's own school
 *   usable_while       the counter can still be cast while affected by this CC
 *   dodge_parry        the counter raises Dodge%/Parry% and the CC is a Physical-school ability
 *                      that does not bypass the active-defense roll
 *
 * `detail` carries the human-readable specific (the matched mechanic name, or the school list) so
 * the UI can say WHY something counters without re-deriving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spell_counters', function (Blueprint $table) {
            $table->id();
            // The CC ability being countered.
            $table->foreignId('countered_spell_id')->constrained('spells')->cascadeOnDelete();
            // The ability that counters it.
            $table->foreignId('counter_spell_id')->constrained('spells')->cascadeOnDelete();
            $table->string('mechanism');
            $table->string('detail')->nullable();
            $table->timestamps();

            $table->unique(['countered_spell_id', 'counter_spell_id', 'mechanism'], 'spell_counters_unique');
            $table->index('counter_spell_id');
            $table->index('mechanism');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spell_counters');
    }
};
