<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three values the SimC dump has always carried and this schema has never stored, each of which
 * a description token asks for by name and gets "(varies)" instead.
 *
 * Measured against the 12.1.0.69933 dumps before adding them, counting occurrences in raw
 * Description lines across all 13 classes:
 *
 *   $u / $<id>u / $<id>U   273   "stacking up to $194879u times"   -> Stacks : N maximum
 *   $AN / $<id>AN / $aN    283   "all enemies within $A1 yards"    -> effect line, Radius: N yards
 *   $xN                     19   "Affects $x1 total targets"       -> effect line, Chain Targets: N
 *
 * ModuleSpellReferenceService's Pass 3 names these three tokens in its own comment as ones that
 * "aren't captured in this schema at all, so there is nothing to resolve them to" — that is what
 * these columns close. The comment stays accurate about the rest: $tN (tick period) and $oN
 * (total over-time damage) have no field in the dump to read, and $oN additionally needs the SP
 * scaling this project deliberately does not model, so both keep returning "(varies)".
 *
 * All nullable, all plain numerics. Nullable is load-bearing rather than incidental: a spell with
 * no Stacks line genuinely does not stack, and a null here is what keeps resolveValueToken()
 * returning null so the honest "(varies)" survives instead of a confident 0. No enum anywhere —
 * the suite runs on in-memory SQLite and an ALTER on an enum column breaks every test at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->unsignedInteger('max_stacks')->nullable()->after('charges');
        });

        Schema::table('spell_effects', function (Blueprint $table) {
            $table->decimal('radius_yards', 8, 2)->nullable()->after('misc_value');
            $table->unsignedInteger('chain_targets')->nullable()->after('radius_yards');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('max_stacks');
        });

        Schema::table('spell_effects', function (Blueprint $table) {
            $table->dropColumn(['radius_yards', 'chain_targets']);
        });
    }
};
