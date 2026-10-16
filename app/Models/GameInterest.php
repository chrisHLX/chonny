<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One player asking to be told when a coming game opens on MindCollector (PlatformController). */
class GameInterest extends Model
{
    protected $fillable = ['game', 'user_id', 'email'];

    protected $casts = ['notified_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
