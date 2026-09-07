<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named sections inside a guide, so one guide can hold several chains, several gos, prose, and an
 * opponent's expected defensives side by side — instead of being a single flat sequence.
 *
 * THIS DROPS user_guides.type, which is the third change to that table in two days and is
 * deliberate rather than churn: the column recorded whether a guide was a chain or a go, and a
 * guide can now be BOTH at once. Kind therefore belongs to the section, which is also the thing
 * that decides which abilities the palette offers. Leaving a guide-level type would have made it a
 * label that disagrees with its own contents the moment someone adds a go to a chain guide.
 *
 * LAYOUT IS (row, column), NOT A FLAT POSITION. `row` orders sections down the page; `column`
 * places two sections side by side in the same row. That is what makes the "VS" view work — your
 * go on the left, the defensives you are trying to force out of the opponent on the right, read
 * across rather than one after the other. A flat position could not express "these two are the
 * same moment".
 *
 * `opponent_spec_id` is what makes a section a VS section: when set, the section's palette offers
 * that spec's DEFENSIVE cooldowns rather than the comp's own abilities. It is nullable and
 * independent of column, so a defensive section is not forced into a particular side of the page.
 *
 * `body` carries a text section's Markdown. Rendered with Str::markdown(..., html_input => strip,
 * allow_unsafe_links => false) — the same settings every other user-supplied Markdown in this
 * codebase already uses (ModulePage, SubjectContent). Raw HTML is stripped rather than trusted,
 * because unlike a ModulePage this content is authored by any signed-in user and will be shown to
 * other people.
 *
 * kind is a plain string, not a DB enum, for the SQLite reason documented on the user_guides
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_guide_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_guide_id')->constrained()->cascadeOnDelete();

            // App\Enums\UserGuideSectionKind — 'chain' | 'go' | 'defensives' | 'text'.
            $table->string('kind');
            $table->string('title');

            $table->unsignedSmallInteger('row')->default(0);
            // 0 = left / full width, 1 = the parallel section beside it.
            $table->unsignedTinyInteger('column')->default(0);

            // Set on a VS section: whose defensives this section is about.
            $table->foreignId('opponent_spec_id')->nullable()->constrained('specializations')->nullOnDelete();

            // Markdown, for kind = text. Null for every sequence kind.
            $table->longText('body')->nullable();

            $table->timestamps();

            // A cell holds one section. Unlike blocks, sections are placed rather than dragged
            // through transient duplicates, so this constraint is safe and worth having.
            $table->unique(['user_guide_id', 'row', 'column']);
        });

        Schema::table('user_guide_blocks', function (Blueprint $table) {
            $table->foreignId('user_guide_section_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        // Blocks now belong to a section, and a section belongs to a guide. Keeping the old direct
        // guide_id would be a second path to the same fact, free to disagree with the section's
        // own — the exact duplication this feature has already had to undo once.
        Schema::table('user_guide_blocks', function (Blueprint $table) {
            $table->dropForeign(['user_guide_id']);
            $table->dropIndex('user_guide_blocks_user_guide_id_position_index');
            $table->dropColumn('user_guide_id');
            $table->index(['user_guide_section_id', 'position']);
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropIndex('user_guides_type_status_index');
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->string('type')->default('cc_chain');
            $table->index(['type', 'status'], 'user_guides_type_status_index');
        });

        Schema::table('user_guide_blocks', function (Blueprint $table) {
            $table->foreignId('user_guide_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['user_guide_id', 'position'], 'user_guide_blocks_user_guide_id_position_index');
            $table->dropForeign(['user_guide_section_id']);
            $table->dropIndex(['user_guide_section_id', 'position']);
            $table->dropColumn('user_guide_section_id');
        });

        Schema::dropIfExists('user_guide_sections');
    }
};
