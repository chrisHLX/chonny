<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spells.is_peel` / `spells.is_interrupt` — two new functional-role flags, deliberately
 * SEPARATE from `dr_category`, requested 2026-08-11 alongside the Synergies tab. Neither fits
 * as a dr_category value:
 *   - "Peels" spans two DIFFERENT real DR pools (Root and Knockback don't diminish each other in
 *     the actual game — see dr-categories-reference.md) — folding them into one dr_category
 *     value would silently break CcChainBuilder's DR-collision math for every spell tagged it.
 *   - Interrupts aren't a DR concept at all (a Counterspell never diminishes against a Kidney
 *     Shot); tagging one as a dr_category value would make it eligible for chain-sequencing
 *     logic it has no business being part of.
 * A spell can independently be BOTH (e.g. Ursol's Vortex is dr_category=Knockback AND is_peel;
 * Typhoon is dr_category=Knockback AND is_interrupt) — plain nullable-false booleans, not an
 * enum, so both flags can coexist freely. Simple true/false, not tri-state like dr_category —
 * "not tagged" is a legitimate default here (most spells genuinely aren't peels or interrupts),
 * unlike dr_category where NULL specifically means "not yet classified, don't guess."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->boolean('is_peel')->default(false)->after('chain_target');
            $table->boolean('is_interrupt')->default(false)->after('is_peel');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn(['is_peel', 'is_interrupt']);
        });
    }
};
