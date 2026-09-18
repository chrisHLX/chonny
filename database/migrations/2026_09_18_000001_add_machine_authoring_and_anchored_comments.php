<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guides written by a model, and comments that attach to one step rather than the whole guide.
 *
 * WHY A MODEL NAME RATHER THAN A BOOLEAN. `authored_by_model` holds what actually wrote it
 * ("Claude Opus 5"), because the byline has to name it — a guide presented with the same framing
 * as a player's plan would spend exactly the credibility this project protects everywhere else
 * (see Guides\Show's docblock on why /g/ is its own namespace). It also survives a second model
 * later, which a boolean would not. NULL means a person wrote it, which is every existing row.
 *
 * WHY COMMENTS CAN ANCHOR. A flat comment gets "this is wrong". A comment attached to step 3 gets
 * "this doesn't force Pain Suppression, Disc just shields it" — which is the one input the data
 * cannot supply (see arena-structure.md Part 16.3 and the forcing-table question). Both columns
 * are nullable: an un-anchored comment is still a comment on the guide as a whole, and that is
 * still the common case.
 *
 * Anchors cascade on delete. A note about a step that no longer exists has nothing to say, and
 * keeping it would put orphaned criticism under a guide that has already moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->string('authored_by_model')->nullable()->after('user_id');
            $table->index('authored_by_model');
        });

        Schema::table('user_guide_comments', function (Blueprint $table) {
            $table->foreignId('user_guide_section_id')->nullable()->after('user_guide_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('user_guide_block_id')->nullable()->after('user_guide_section_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_guide_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_guide_block_id');
            $table->dropConstrainedForeignId('user_guide_section_id');
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropIndex(['authored_by_model']);
            $table->dropColumn('authored_by_model');
        });
    }
};
