<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 'verified_override' to spell_class_availability.source's enum — a fourth kind of row,
 * distinct from the three ImportSpellData derives automatically (baseline/talent/pvp_talent).
 * A verified_override row is never auto-derived from spec_id=NULL data (see CLAUDE.md's
 * "Baseline ability display" section for why that's structurally unsafe) — it's hand-curated
 * from data/spelldata/baseline-spec-overrides.txt, one line per manually-confirmed
 * (spell, class, spec) fact, imported via ImportSpellData::importBaselineSpecOverrides().
 * spec_id is always explicit on these rows, never null.
 *
 * Driver-aware, fixed same-day after it shipped: a first version ran a raw MySQL
 * `ALTER TABLE ... MODIFY COLUMN` unconditionally, which is invalid syntax on SQLite —
 * phpunit.xml runs the whole test suite against an in-memory SQLite DB (RefreshDatabase),
 * so that one unguarded statement broke migrations for every single test (167 failures,
 * not the usual 12 pre-existing ones). SQLite has no native ENUM type at all — Laravel's
 * schema builder represents `$table->enum()` there as a CHECK constraint, and SQLite's
 * ALTER TABLE can't modify a CHECK constraint in place — so the only real cross-driver fix
 * is to drop and recreate the column via Schema::table()->enum()->change(), which Laravel
 * translates correctly per-driver (a full table rebuild under the hood on SQLite, a plain
 * MODIFY on MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE spell_class_availability MODIFY COLUMN source ENUM('baseline', 'talent', 'pvp_talent', 'verified_override') NOT NULL");

            return;
        }

        Schema::table('spell_class_availability', function ($table) {
            $table->enum('source', ['baseline', 'talent', 'pvp_talent', 'verified_override'])->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE spell_class_availability MODIFY COLUMN source ENUM('baseline', 'talent', 'pvp_talent') NOT NULL");

            return;
        }

        Schema::table('spell_class_availability', function ($table) {
            $table->enum('source', ['baseline', 'talent', 'pvp_talent'])->change();
        });
    }
};
