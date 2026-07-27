<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Spell extends Model
{
    protected $fillable = [
        'patch_id',
        'spell_id',
        'name',
        'school',
        'description',
        'charges',
        'cooldown_seconds',
        'duration_seconds',
    ];

    // Without these, isDirty() falls back to strcmp() for uncast numeric attributes — MySQL
    // returns the decimal column as a string ("180.00") while the parser fills a PHP float
    // (180.0), so every spell with a cooldown was flagged dirty on every single import run.
    // Casting both sides through the same type before comparison is what makes upsertTrack()'s
    // dirty-check (and therefore idempotency) actually work for these columns.
    protected $casts = [
        'charges' => 'integer',
        'cooldown_seconds' => 'decimal:2',
        'duration_seconds' => 'decimal:2',
    ];

    public function patch()
    {
        return $this->belongsTo(Patch::class);
    }

    public function effects()
    {
        return $this->hasMany(SpellEffect::class);
    }

    public function talentNodeEntries()
    {
        return $this->hasMany(TalentNodeEntry::class);
    }

    public function classAvailability()
    {
        return $this->hasMany(SpellClassAvailability::class);
    }

    /** Relationships where this spell is the one doing the modifying. */
    public function outgoingRelationships()
    {
        return $this->hasMany(SpellRelationship::class, 'source_spell_id');
    }

    /** Relationships where this spell is the one being modified. */
    public function incomingRelationships()
    {
        return $this->hasMany(SpellRelationship::class, 'target_spell_id');
    }

    public function game(): ?Game
    {
        return $this->patch?->game;
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('patch', fn (Builder $q) => $q->where('game_id', $gameId));
    }
}
