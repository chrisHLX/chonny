<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A game the player uploaded to their own YouTube channel from the app (2026-10-26; the
 * migration says what each column is). Its Watch page plays the video from YouTube.
 */
class YoutubeVideo extends Model
{
    protected $fillable = ['user_id', 'lobby_id', 'video_id', 'privacy', 'started', 'rounds'];

    protected function casts(): array
    {
        return ['rounds' => 'array'];
    }
}
