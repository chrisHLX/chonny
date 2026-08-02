<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_builds', function (Blueprint $table) {
            // A build scoped to one specific module — takes priority over the user's own build
            // and the spec's admin default when resolving what's selected on that module's page
            // (see TalentSelectionService::resolveBuildForModule()). A module's own build is the
            // content author's deliberate choice for that specific guide, same reasoning as why
            // ModuleGameBuild.hero_talent_tree_id is locked to the module rather than left to the
            // viewer's preference. Nullable + unique: at most one build per module, and MySQL
            // doesn't count NULL as colliding with NULL, so this coexists with the existing
            // (user_id, spec_id) unique constraint (user/admin-default builds all have
            // module_id = null) without conflict.
            $table->foreignId('module_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->unique('module_id');
        });
    }

    public function down(): void
    {
        Schema::table('talent_builds', function (Blueprint $table) {
            $table->dropUnique(['module_id']);
            $table->dropConstrainedForeignId('module_id');
        });
    }
};
