<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sharing the games the app uploaded to YouTube (2026-10-28): a page per video at /wow/videos/{id}
 * and a library of them at /wow/videos (VideoController).
 *
 *  - status: what YouTube last said: public, unlisted, private or removed (YouTubeVideoService::refresh).
 *    `privacy` stays what the player chose at upload.
 *  - processing: YouTube has the file but has not finished it, so it does not play yet.
 *  - views, embeddable, title: from YouTube, when it can be asked (an API key, or oEmbed for the title).
 *  - listed: in the library. Null follows the video: listed when it is public on YouTube.
 *  - game: what the library filters by (bracket, both teams' specs, MMR, the result), taken once from
 *    the stored rounds so the list never reads a round's payload. played_at sorts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->string('status', 16)->nullable();
            $table->boolean('processing')->default(false);
            $table->unsignedBigInteger('views')->nullable();
            $table->boolean('embeddable')->nullable();
            $table->string('title', 200)->nullable();
            $table->boolean('listed')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->json('game')->nullable();
            $table->timestamp('played_at')->nullable();
            $table->index('video_id');
        });
    }

    public function down(): void
    {
        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->dropIndex(['video_id']);
            $table->dropColumn(['status', 'processing', 'views', 'embeddable', 'title', 'listed', 'checked_at', 'game', 'played_at']);
        });
    }
};
