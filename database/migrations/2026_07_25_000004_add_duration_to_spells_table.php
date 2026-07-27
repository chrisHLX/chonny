<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            // Needed to resolve the "$d" tooltip token in descriptions (see
            // ModuleSpellReferenceService). Null means "not a finite duration in the dump"
            // (e.g. "Duration: Aura (infinite)"), not zero — same convention as cooldown_seconds.
            $table->decimal('duration_seconds', 8, 2)->nullable()->after('cooldown_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('duration_seconds');
        });
    }
};
