<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class PageViewEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['page', 'class_id', 'spec_id', 'slot', 'session_id', 'user_id'];

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
                'page'       => $page,
                'class_id'   => $classId,
                'spec_id'    => $specId,
                'slot'       => $slot,
                'session_id' => session()->getId(),
                'user_id'    => auth()->id(),
            ]);
        } catch (\Throwable $e) {
            Log::error('PageViewEvent::log failed', ['page' => $page, 'error' => $e->getMessage()]);
        }
    }
}
