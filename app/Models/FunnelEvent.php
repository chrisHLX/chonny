<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class FunnelEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['guest_session_id', 'event', 'module_id', 'user_id'];

    /**
     * Fire-and-forget: funnel tracking must never break the flow it's observing, so failures
     * are logged and swallowed rather than thrown — same discipline as
     * NextStepService::recordInsightAndGenerateInitialStep().
     */
    public static function log(string $event, ?string $guestSessionId, ?int $moduleId = null, ?int $userId = null): void
    {
        try {
            static::create([
                'guest_session_id' => $guestSessionId,
                'event'            => $event,
                'module_id'        => $moduleId,
                'user_id'          => $userId,
            ]);
        } catch (\Throwable $e) {
            Log::error('FunnelEvent::log failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
