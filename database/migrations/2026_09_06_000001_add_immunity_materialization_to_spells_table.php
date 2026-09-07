<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materializes the two immunity facts that were previously only ever computed at render time,
 * per-request, through ModuleSpellReferenceService — completing the "spell shape" pass
 * ImportSpellData::materializeSpellShape() already started for spells.category /
 * spells.silence_immune_by_school (2026-09-03).
 *
 * Both are strictly BUILD-INDEPENDENT: what a spell grants immunity to is a property of its own
 * effect rows, identical for every viewer regardless of which talents they have selected. That
 * makes them exactly the wrong thing to recompute on every page render (the mistake
 * spells.category was already fixed for) and exactly the right thing to answer in SQL.
 *
 *  - grants_cc_immunity      JSON array of real mechanic names ("Stun", "Fear", ...), sourced
 *                            from ModuleSpellReferenceService::ccImmunityGrantedBy(). NULL means
 *                            "not yet materialized"; [] means "materialized, grants none" — the
 *                            two are deliberately distinguishable so a stale row is visible.
 *  - grants_school_immunity  The raw "Affected School(s)" payload of a School Immunity effect —
 *                            either the literal 'All' or a comma list ('Arcane, Fire, ...') —
 *                            resolved INCLUDING the same-name sibling fallback
 *                            grantsSchoolImmunityFor() already performs (real, confirmed case:
 *                            Cloak of Shadows' own castable copy 31224 carries no School
 *                            Immunity effect at all, it triggers hidden aura 35729 which does).
 *
 * Nothing is dropped or narrowed: ccImmunityGrantedBy()/grantsSchoolImmunityFor() stay exactly
 * as they are and remain the single engine that computes these — this only stores their answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->json('grants_cc_immunity')->nullable()->after('cc_immunity_note');
            $table->string('grants_school_immunity')->nullable()->after('grants_cc_immunity');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn(['grants_cc_immunity', 'grants_school_immunity']);
        });
    }
};
