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
        'variables',
        'charges',
        'cooldown_seconds',
        'cooldown_scaling_note',
        'duration_seconds',
        'pvp_duration_seconds',
        'not_in_spellbook',
        'icon_name',
        'mechanic',
        'spell_type',
        'range_yards',
        'is_passive',
        'dr_category',
        'conditional_dr_gating_spell_id',
        'conditional_dr_category',
        'cast_type',
        'chain_target',
        'is_peel',
        'is_interrupt',
        'is_mobility',
        'pairs_with_category',
        'requires_stealth',
        'requires_target_out_of_combat',
        'usable_while_cc',
        'bypasses_active_defense',
        'cc_immunity_note',
        'category',
        'silence_immune_by_school',
        'grants_cc_immunity',
        'grants_cc_immunity_override',
        'cc_immunity_gating_spell_id',
        'grants_school_immunity',
        'school_immunity_override',
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
        'pvp_duration_seconds' => 'decimal:1',
        'not_in_spellbook' => 'boolean',
        'is_passive' => 'boolean',
        'is_peel' => 'boolean',
        'is_interrupt' => 'boolean',
        'is_mobility' => 'boolean',
        'requires_stealth' => 'boolean',
        'requires_target_out_of_combat' => 'boolean',
        'bypasses_active_defense' => 'boolean',
        'silence_immune_by_school' => 'boolean',
        'grants_cc_immunity' => 'array',
        'grants_cc_immunity_override' => 'array',
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

    /**
     * Every ability that counters THIS spell — the reverse-lookup half of spell_counters.
     * Only ever populated for a spell that is itself real CC (has a dr_category); see
     * SpellCounterIndexer for how each row is derived and what `mechanism` means.
     *
     * This exists so "what counters Kidney Shot" is answerable from anywhere (the detail modal,
     * the detail page, SpellFinder) rather than only from inside ClaudesCounters, which used to
     * be the sole place in the codebase that could compute it at all.
     */
    public function counteredBy()
    {
        return $this->hasMany(SpellCounter::class, 'countered_spell_id');
    }

    /** Every CC ability this spell counters — the forward direction of the same table. */
    public function counters()
    {
        return $this->hasMany(SpellCounter::class, 'counter_spell_id');
    }

    public function ccChainExceptions()
    {
        return $this->belongsToMany(CcChainException::class, 'cc_chain_exception_spells')
            ->withPivot('order')
            ->orderByPivot('order');
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

    /**
     * Display-only: strips the trailing "(desc=Color)" disambiguation suffix some spells carry
     * as a literal part of their raw `name` (see TalentSelectionService's docblock on the
     * general pattern, and MurlokTalentImportService::normalizeSpellName() for the Evoker-
     * specific case — that class's whole kit uses this suffix as a real per-dragonflight-color
     * naming convention, not the "noise" it represents for other classes). Deliberately an
     * accessor, not a mutation of `name` itself — the raw column is load-bearing for exact-name
     * matching elsewhere (murlok comparison, duplicate-copy disambiguation throughout this
     * codebase) and must stay untouched. Added 2026-08-09 after fixing Evoker's near-empty
     * spell kit surfaced this as a real, visible papercut across most of that class's UI —
     * "Pyre (desc=Red)" rendering verbatim to players instead of "Pyre".
     */
    public function getDisplayNameAttribute(): string
    {
        return trim(preg_replace('/\s*\(desc=[^)]*\)\s*$/i', '', $this->name ?? ''));
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('patch', fn (Builder $q) => $q->where('game_id', $gameId));
    }
}
