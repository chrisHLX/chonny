<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-authored CC-chain exceptions (e.g. Root -> Silence, Death Grip -> Sleep) — combos that
 * work for reasons outside the general DR-sequencing algorithm (e.g. two mechanics that simply
 * don't share a DR category, or a comp-specific AoE setup). manual_knowledge tier throughout —
 * every row here is expert-dictated, same tier as the arena-structure.md content. Never
 * generated or derived; see CLAUDE.md's "Synergies tab" section.
 *
 * `reason` is required, not optional — an exception with no stated reason is indistinguishable
 * from a mistake later; every row must explain *why* it's an exception to the general rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cc_chain_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cc_chain_exceptions');
    }
};
