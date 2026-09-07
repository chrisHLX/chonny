<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second, TALENT-CONDITIONAL dr_category for the handful of spells whose crowd-control type
 * genuinely changes depending on whether one specific talent is selected.
 *
 * The motivating, confirmed case (2026-09-06, reported live): Holy Word: Chastise incapacitates
 * by default and STUNS once Censure is talented — Blizzard's own description encodes the flip
 * explicitly, `$?s200199[stuns][incapacitates] them for $?s200199[$200200d][$200196d]`. Until
 * now `spells.dr_category` was a single flat value, so the displayed copy (88625) was hard-tagged
 * `Incapacitate` and CLAUDE.md logged this as a known "conditional-category gap" on the
 * assumption Incapacitate was at least the majority case. It isn't: Censure is selected in the
 * Holy Priest admin-default build the site actually renders, so every viewer was being shown the
 * wrong DR category for the build in front of them. (The same shape was already documented for
 * Monk's Disable, and Warrior's Charge carries an additive-stun variant — see this file's
 * companion note in cc-synergies-overrides.txt for why only genuine TYPE FLIPS belong here.)
 *
 * Two columns, both hand-curated via cc-synergies-overrides.txt and both null for the
 * overwhelming majority of spells:
 *
 *   conditional_dr_gating_spell_id — the EXTERNAL spell_id (Blizzard's, matching spells.spell_id,
 *                                    not the internal PK) of the talent that triggers the flip.
 *                                    External deliberately: it is what the curation file states,
 *                                    what the spell's own description text names, and what
 *                                    survives a re-import unchanged, whereas the internal id does
 *                                    not.
 *   conditional_dr_category        — the dr_category that applies INSTEAD of dr_category when
 *                                    that talent is selected.
 *
 * Resolution is a display-time concern, never a write: SpellProfileBuilder::resolveDrCategory()
 * picks between the two against the active build's real selections, and the base dr_category
 * column is left untouched so every build-independent consumer (the arena-log CC-chain corpus,
 * SpellCounterIndexer, CcFormulaService, the review pages) keeps reading exactly what it read
 * before. Those consumers are correct to: a real combat log already records whichever variant
 * actually landed under its own distinct aura spell_id (200196 vs 200200 for Chastise), so they
 * never needed the conditional in the first place.
 *
 * NOT added here, deliberately: a conditional pvp_duration_seconds. The only case that exists
 * today curates 3.0s for both branches, so a duration column would be speculative — and this
 * project's standing discipline is to add a curated field when a real case needs it, not ahead of
 * one. If a future conditional does change duration, add it the same way this pair was added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->unsignedBigInteger('conditional_dr_gating_spell_id')->nullable()->after('dr_category');
            $table->string('conditional_dr_category')->nullable()->after('conditional_dr_gating_spell_id');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn(['conditional_dr_gating_spell_id', 'conditional_dr_category']);
        });
    }
};
