<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            // Blizzard's own single-value classification of what kind of control/defensive
            // mechanic a spell applies (e.g. "Stun", "Charm", "Shield", "Bleed") — present in the
            // raw SimC dump ("Mechanic : Charm") but never captured before now. Far more
            // authoritative than ModuleSpellReferenceService::categorize()'s effect-type-string
            // regex heuristic, which it now checks first — found 2026-08-06 investigating why
            // Mind Control (a real Charm effect, per this field) categorized as Offensive: its
            // core mechanic effect type is literally "Possess" in the Effects block, which the
            // regex had no way to recognize, while its incidental "Modify Damage Done%" side
            // effect happened to match the Offensive pattern instead.
            $table->string('mechanic')->nullable()->after('not_in_spellbook');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('mechanic');
        });
    }
};
