<?php

namespace App\Models;

use App\Support\BotDetector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class PageViewEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['page', 'class_id', 'spec_id', 'slot', 'session_id', 'user_id', 'is_bot', 'referrer_host'];

    protected $casts = ['is_bot' => 'boolean'];

    /**
     * Real visitors only. `is_bot` is NULL for everything logged before 2026-09-24, when the
     * user agent started being read at all — those rows are excluded here rather than assumed
     * human, because roughly half of them were crawlers and there is no way to tell which.
     */
    public function scopeHuman($query)
    {
        return $query->where('is_bot', false);
    }

    /** Rows from before bot detection existed, kept but never mixed into a "real visitors" count. */
    public function scopeUnclassified($query)
    {
        return $query->whereNull('is_bot');
    }

    /**
     * Fire-and-forget, same discipline as FunnelEvent::log() — usage tracking must never break
     * the page it's observing, so failures are logged and swallowed rather than thrown.
     *
     * A row with class_id/spec_id both null is a bare page view (WowComps::mount() /
     * SpellExplorer::mount() before anything's been picked); a populated row is a class/spec
     * selection, optionally scoped to a comp slot.
     */
    public static function log(string $page, ?int $classId = null, ?int $specId = null, ?string $slot = null): void
    {
        try {
            static::create([
                'page' => $page,
                'class_id' => $classId,
                'spec_id' => $specId,
                'slot' => $slot,
                'session_id' => session()->getId(),
                'user_id' => auth()->id(),
                // Read here rather than filtered at the edge on purpose: the row still gets
                // written, so crawler interest in a page stays measurable, it just stops being
                // counted as audience.
                'is_bot' => BotDetector::isBot(request()->userAgent()),
                'referrer_host' => BotDetector::referrerHost(request()->headers->get('referer')),
            ]);
        } catch (\Throwable $e) {
            Log::error('PageViewEvent::log failed', ['page' => $page, 'error' => $e->getMessage()]);
        }
    }
}
