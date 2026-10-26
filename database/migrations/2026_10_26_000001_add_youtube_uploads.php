<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploading the app's recordings to the player's own YouTube channel (2026-10-26).
 *
 *  - users.youtube_token: the refresh token Google gives when the player links YouTube (scope
 *    youtube.upload only), encrypted by the model's cast. Deleted on Disconnect, after revoking it.
 *  - youtube_videos: each game the player uploaded, so its Watch page can play the video from
 *    YouTube. `started` and `rounds` are the recording's timing file (the PC's clock, the combat
 *    log's clock): round n begins (its START minus `started`) seconds into the video, exactly as for
 *    a local recording. The video itself is never on our servers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('youtube_token')->nullable();
            $table->timestamp('youtube_linked_at')->nullable();
        });

        Schema::create('youtube_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('lobby_id', 64);
            $table->string('video_id', 32);
            $table->string('privacy', 16);
            $table->string('started', 32)->nullable();
            $table->json('rounds')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'lobby_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_videos');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['youtube_token', 'youtube_linked_at']);
        });
    }
};
