<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hand-verified, per-spell prose note for a real cooldown-reduction mechanic that has no
     * safe static magnitude to compute — either because the source data's own numbers disagree
     * (Ascendance's structural effect says -60%, its own tooltip prose says -9%; not resolvable
     * without guessing which is real) or because the effect scales dynamically with a resource
     * spent (Elemental Tempo: "-X sec per Maelstrom Weapon stack consumed", no fixed number).
     *
     * Deliberately NOT auto-derived from ModuleSpellReferenceService::modifiersFor()'s existing
     * 'mentions' text-scan fallback — that fallback is a blunt "does the source's description
     * contain this spell's literal name" check, correct for surfacing "something modifies this,
     * go read the source's own description" but far too noisy to treat every hit as a cooldown
     * fact: of Stormstrike's 11 real 'mentions' hits, only one (Elemental Tempo) is actually
     * about its cooldown — the rest (Crash Lightning, Doom Winds, Awakening Storms, etc.) mention
     * Stormstrike for unrelated reasons (proc triggers, damage coefficients). Same hand-curated,
     * one-verified-line-at-a-time discipline as baseline-spec-overrides.txt/cc-synergies-
     * overrides.txt, not a bulk heuristic — see data/spelldata/cooldown-scaling-notes.txt.
     */
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->text('cooldown_scaling_note')->nullable()->after('cooldown_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('cooldown_scaling_note');
        });
    }
};
