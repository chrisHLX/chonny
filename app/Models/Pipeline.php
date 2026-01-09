<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pipeline extends Model
{
    protected $fillable = [
        'user_id',
        'module_id',
        'type',
        'status',
        'started_at',
        'completed_at',
        'error',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ------------------
    // Relationships
    // ------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(PipelineStep::class);
    }

    // ------------------
    // Convenience helpers
    // ------------------

    public function markRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
        ]);
    }
}

