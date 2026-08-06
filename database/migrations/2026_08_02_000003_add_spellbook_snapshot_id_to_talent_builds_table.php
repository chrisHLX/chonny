<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_builds', function (Blueprint $table) {
            // Which real in-game export (if any) this build was decoded from — lets the
            // description resolver prefer real, resolved text (spec conditionals evaluated,
            // real numbers from the exporting character's actual stats) over template
            // substitution, which can never produce real damage/healing numbers since those
            // depend on gear the site has no way to know. Nullable: most builds (admin
            // defaults hand-picked via the UI, personal builds click-selected) have no
            // corresponding export and fall back to the existing template resolver unchanged.
            $table->foreignId('spellbook_snapshot_id')->nullable()->after('module_id')
                ->constrained('spellbook_snapshots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('talent_builds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spellbook_snapshot_id');
        });
    }
};
