<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Structural spell-to-spell relationship, derived at import time from the source data's own
 * explicit spell_id references — never inferred from free-text description parsing, with one
 * deliberate exception (see 'modifies_cooldown' below). `relationship_type` is one of:
 * - 'modifies' — effect-value modifier (e.g. "Improved Power Word: Shield modifies Power Word:
 *   Shield"), from "Modified By" / "Affecting Spells".
 * - 'modifies_charges' — charge-count modifier (e.g. "Protector of the Frail grants Pain
 *   Suppression an extra charge"), from "Category" / "Affected Spells (Category)".
 * - 'replaces' — action-bar spell replacement (e.g. "Beacon of Virtue replaces Beacon of
 *   Light"), from a Talent Entry line's replace="<name>" (id=<id>) annotation.
 * - 'modifies_cooldown' — cooldown-duration modifier parsed from a PvP talent's free-text
 *   description (e.g. "Ultimate Radiance" reduces Evangelism's cooldown by 45s) — see
 *   ImportSpellData::importPvpTalentRelationships(). PvP talent JSON carries no structured
 *   spell_id references at all (unlike spelldata's Affecting Spells/Category fields), so this
 *   is the one relationship type sourced from regex-parsed prose rather than a structural field.
 *
 * `modifier_value`/`modifier_unit` carry a computable magnitude wherever confidently known
 * (populated for 'modifies_cooldown' always, and for the subset of 'modifies_charges' Category
 * effect types that convert unambiguously — see game-data.md). Null means "not confidently
 * derivable," not zero — the relationship still renders descriptively via `description`.
 */
class SpellRelationship extends Model
{
    protected $table = 'spell_relationships';

    protected $fillable = [
        'source_spell_id',
        'target_spell_id',
        'relationship_type',
        'description',
        'modifier_value',
        'modifier_unit',
    ];

    protected $casts = [
        'modifier_value' => 'decimal:2',
    ];

    public function sourceSpell()
    {
        return $this->belongsTo(Spell::class, 'source_spell_id');
    }

    public function targetSpell()
    {
        return $this->belongsTo(Spell::class, 'target_spell_id');
    }

    public function game(): ?Game
    {
        return $this->sourceSpell?->game();
    }

    public function scopeInGame(Builder $query, int $gameId): Builder
    {
        return $query->whereHas('sourceSpell', fn (Builder $q) => $q->inGame($gameId));
    }
}
