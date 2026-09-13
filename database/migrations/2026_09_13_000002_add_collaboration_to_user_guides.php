<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Letting friends and guildmates edit a guide, and recording who changed what.
 *
 * EDIT ACCESS IS TWO SWITCHES ON THE GUIDE, not a list of collaborators. "My friends can edit" and
 * "my guild can edit" are the two things an arena team actually wants, and both follow membership:
 * accept a new friend or have someone join the guild and they can edit at once; unfriend them or
 * remove them from the guild and they cannot, without the author touching the guide. A per-person
 * list would have to be kept in step with both by hand. Both default to off, so every existing
 * guide stays author-only.
 *
 * guild_can_edit uses the guide's existing guild_id — the same guild a guild-visible guide is
 * shared with — rather than a second guild column, so a guide never names two different guilds.
 *
 * ATTRIBUTION is a real column, never part of a block's payload. The payload is reserved for
 * references and the author's own words (see create_user_guide_blocks_table); a user id in there
 * would be invisible to a query and silently wrong after an account deletion. These are foreign
 * keys that null out when the account goes, so a guide never loses a step because the person who
 * added it deleted their account.
 *
 * - user_guides.last_edited_by_user_id: whoever last changed anything, for "edited by X, 5m ago".
 * - user_guide_sections.created_by_user_id / updated_by_user_id: who added the section, and who
 *   last changed it (a rename, a reorder, a step added or removed, a note).
 * - user_guide_blocks.added_by_user_id: who put this step here.
 *
 * Existing rows are credited to the guide's author, who up to now was the only person who could
 * have written them. The backfill is a correlated subquery, which MySQL and SQLite both accept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->boolean('friends_can_edit')->default(false)->after('visibility');
            $table->boolean('guild_can_edit')->default(false)->after('friends_can_edit');
            $table->foreignId('last_edited_by_user_id')->nullable()->after('guild_can_edit')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('user_guide_sections', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('user_guide_blocks', function (Blueprint $table) {
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('UPDATE user_guides SET last_edited_by_user_id = user_id');

        DB::statement(
            'UPDATE user_guide_sections SET
                created_by_user_id = (SELECT user_id FROM user_guides WHERE user_guides.id = user_guide_sections.user_guide_id),
                updated_by_user_id = (SELECT user_id FROM user_guides WHERE user_guides.id = user_guide_sections.user_guide_id)'
        );

        DB::statement(
            'UPDATE user_guide_blocks SET added_by_user_id = (
                SELECT user_guides.user_id FROM user_guide_sections
                JOIN user_guides ON user_guides.id = user_guide_sections.user_guide_id
                WHERE user_guide_sections.id = user_guide_blocks.user_guide_section_id
            )'
        );
    }

    public function down(): void
    {
        Schema::table('user_guide_blocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by_user_id');
        });

        Schema::table('user_guide_sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by_user_id');
            $table->dropConstrainedForeignId('created_by_user_id');
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_edited_by_user_id');
            $table->dropColumn(['friends_can_edit', 'guild_can_edit']);
        });
    }
};
