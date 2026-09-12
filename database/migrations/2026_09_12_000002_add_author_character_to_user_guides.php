<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the author's own characters a guide is "written as".
 *
 * Opt-in per guide, and the only thing that ever puts a character's name on a public page. Null is
 * the normal state. nullOnDelete: unlinking Battle.net (or the character leaving the account)
 * removes the attribution, never the guide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->foreignId('battlenet_character_id')->nullable()->after('guild_id')
                ->constrained('battlenet_characters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('battlenet_character_id');
        });
    }
};
