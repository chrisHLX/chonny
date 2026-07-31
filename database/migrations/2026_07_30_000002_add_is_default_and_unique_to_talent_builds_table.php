<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_builds', function (Blueprint $table) {
            // A default/meta build (user_id = null) — the fallback used when a user has no
            // saved build of their own for a spec. Uniqueness of "one default per (spec_id,
            // patch_id)" is enforced at the service layer (TalentSelectionService::setDefault()),
            // not a DB constraint — a plain unique index would also block multiple non-default
            // (is_default = false) rows per spec/patch, which is not the intent.
            $table->boolean('is_default')->default(false)->after('is_public');

            // Structurally impossible for a real user to have two saved builds for the same
            // spec — MySQL doesn't count NULL user_id rows as colliding with each other, so this
            // only constrains real users, matching the user_subject_context precedent (see
            // CLAUDE.md's Subject Context Dimensions section).
            $table->unique(['user_id', 'spec_id']);
        });
    }

    public function down(): void
    {
        Schema::table('talent_builds', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'spec_id']);
            $table->dropColumn('is_default');
        });
    }
};
