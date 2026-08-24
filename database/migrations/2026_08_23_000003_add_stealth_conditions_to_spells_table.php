<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spells.requires_stealth` / `spells.requires_target_out_of_combat` — curated facts (same
 * manual_knowledge tier as dr_category/pairs_with_category, verified via description text and
 * direct player knowledge, never derived) for hard-CC abilities that can only land under a
 * specific real-game precondition, not just "instant vs cast":
 *
 * - Sap (Rogue) requires BOTH: the caster must be stealthed, AND the target must not have dealt
 *   damage or received healing for the preceding ~5 seconds (WoW's real "in combat" gate) —
 *   confirmed directly by the domain expert 2026-08-23, correcting an earlier assumption that
 *   losing stealth alone was the whole story. This is why Sap shows up almost exclusively as a
 *   real opener or after a long chain that's fully removed the target from combat, never as a
 *   normal mid-fight follow-up the way most other hard CC can be.
 * - Rake's stealth-stun component (spell_id 163505, see CLAUDE.md's "Rake's Stun tag was on the
 *   wrong spell_id entirely" history) requires ONLY caster stealth — no target-combat-state gate
 *   — so it gets requires_stealth but not requires_target_out_of_combat.
 *
 * Deliberately two separate boolean columns, not one combined flag: Rake and Sap don't share
 * the same precondition set, and collapsing them would lose that distinction (see CLAUDE.md's
 * "conditional field" discussion, 2026-08-23, for the full back-and-forth this splits out).
 * Both boolean/default-false (not nullable strings) to match the existing is_peel/is_interrupt
 * convention for plain yes/no curated flags on this table, as opposed to dr_category/
 * chain_target/pairs_with_category which are nullable strings because they have more than two
 * states.
 *
 * Sparse-column pattern, consistent with every other curated CC field already on this table
 * (dr_category is non-null on ~172 of ~12,500 spell rows, pairs_with_category on 2) — a
 * nullable/default-false column costs a negligible, fixed amount of storage per row regardless
 * of population rate; a separate side table for "special landing conditions" would be more
 * complexity (new model, migration, import logic, join) for no real benefit at this table size.
 *
 * Curated via data/spelldata/cc-synergies-overrides.txt, same as every other CC-chain field —
 * see ImportSpellData::importCcSynergyOverrides().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->boolean('requires_stealth')->default(false)->after('pairs_with_category');
            $table->boolean('requires_target_out_of_combat')->default(false)->after('requires_stealth');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn(['requires_stealth', 'requires_target_out_of_combat']);
        });
    }
};
