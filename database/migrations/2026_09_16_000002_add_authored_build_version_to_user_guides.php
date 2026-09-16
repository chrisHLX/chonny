<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The game build a guide was written on, as a label frozen at the time.
 *
 * user_guides.patch_id can no longer answer this: import:spelldata now relabels the patch row in
 * place when the game moves to a new build, rather than forking a new row (see
 * ImportSpellData::resolvePatch()). patch_id stays the same across builds, so
 * "written on X, you're on Y" needs the label captured when the guide was authored.
 *
 * Deliberately NOT backfilled: existing guides were stamped while the label was a frozen
 * placeholder that never described the real build, so copying it in would invent a fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->string('authored_build_version')->nullable()->after('patch_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropColumn('authored_build_version');
        });
    }
};
