<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cc_immunity_note` — free-text, hand-curated only, never auto-derived. Same precedent as
 * `cooldown_scaling_note` (2026-08-26): a real mechanic exists but doesn't fit a flag/CSV shape.
 *
 * Specifically exists for PvP-talent-only facts, which have NO structured backing anywhere in
 * this pipeline to derive from automatically (confirmed: PvP talents get zero spell_effects
 * rows at all — see cc-immunity-overrides.txt's own header). The motivating case: Priest's
 * "Phase Shift" PvP talent makes casting Fade also grant "avoiding all attacks and spells for
 * 1 sec" — a genuine temporary immunity window, but one that lives purely in that talent's own
 * free-text description with nothing structured to parse. Populated only via
 * data/spelldata/cc-immunity-overrides.txt's `importCcImmunityOverrides()` pass — never touched
 * by the automatic usable_while_cc/bypasses_active_defense derivation added alongside this
 * column (see the prior migration in this same batch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->text('cc_immunity_note')->nullable()->after('bypasses_active_defense');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('cc_immunity_note');
        });
    }
};
