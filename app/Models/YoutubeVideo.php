<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game the player uploaded to their own YouTube channel from the app (2026-10-26; the
 * migrations say what each column is). Its Watch page plays the video from YouTube, and since
 * 2026-10-28 anyone with the link can open it at /wow/videos/{video_id} while YouTube lets others
 * watch it (VideoController).
 */
class YoutubeVideo extends Model
{
    protected $fillable = ['user_id', 'lobby_id', 'video_id', 'privacy', 'started', 'rounds', 'status', 'listed'];

    protected function casts(): array
    {
        return [
            'rounds' => 'array',
            'game' => 'array',
            'processing' => 'boolean',
            'embeddable' => 'boolean',
            'listed' => 'boolean',
            'checked_at' => 'datetime',
            'played_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What YouTube last said, or what the player chose at upload until it has been asked. */
    public function currentPrivacy(): string
    {
        return $this->status ?? $this->privacy;
    }

    /** Whether someone other than the player can play it: unlisted or public, and not removed. */
    public function watchable(): bool
    {
        return in_array($this->currentPrivacy(), ['public', 'unlisted'], true) && $this->embeddable !== false;
    }

    /** In the library at /wow/videos: the player's choice, else whether it is public on YouTube. */
    public function inLibrary(): bool
    {
        return $this->watchable() && ($this->listed ?? $this->currentPrivacy() === 'public');
    }

    /** Who can watch it, in a sentence for its owner (Your games, Videos; its own page). */
    public function shareLine(): string
    {
        $line = match (true) {
            $this->currentPrivacy() === 'removed' => 'YouTube no longer has this video.',
            $this->currentPrivacy() === 'private' => 'Private on YouTube: only you can play it, so anyone you send it to gets an error. To share it, make it Unlisted in YouTube Studio.',
            $this->embeddable === false => 'YouTube does not allow this video to be played on other sites, so only YouTube can show it. Allow embedding in YouTube Studio.',
            $this->currentPrivacy() === 'public' => 'Public on YouTube: anyone can find it there.',
            default => 'Unlisted on YouTube: anyone with the link can watch it, here or on YouTube, but nobody can find it by searching.',
        };
        if ($this->watchable()) {
            $line .= $this->inLibrary() ? ' It is in MindCollector\'s video library.' : ' It is not in MindCollector\'s video library.';
        }

        return $this->processing ? 'YouTube is still processing it, so it does not play yet. '.$line : $line;
    }
}
