<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Structural spell-to-spell relationship, derived at import time from the source data's own
 * explicit spell_id references — never inferred from free-text description parsing, with one
 * deliberate exception (the PvP-talent-sourced half of 'modifies_cooldown' below).
 * `relationship_type` is one of:
 * - 'modifies' — effect-value modifier (e.g. "Improved Power Word: Shield modifies Power Word:
 *   Shield"), from "Modified By" / "Affecting Spells".
 * - 'modifies_charges' — charge-count modifier (e.g. "Protector of the Frail grants Pain
 *   Suppression an extra charge"), from "Category" / "Affected Spells (Category)", specifically
 *   the "Modify Cooldown Charge (Category)" effect type — see ImportSpellData's
 *   categoryRelationshipMapping().
 * - 'modifies_cooldown' — cooldown-duration modifier, from two sources: (a) the "Modify Recharge
 *   Time (Category)"/"Modify Recharge Time% (Category)"/"Modify Cooldown Time (Category)" effect
 *   types under "Category" / "Affected Spells (Category)" (e.g. Discipline Priest's spec passive
 *   adding 19s to Mind Blast's cooldown), or (b) parsed from a PvP talent's free-text description
 *   (e.g. "Ultimate Radiance" reduces Evangelism's cooldown by 45s) — see
 *   ImportSpellData::importPvpTalentRelationships(). PvP talent JSON carries no structured
 *   spell_id references at all (unlike spelldata's Affecting Spells/Category fields), so (b) is
 *   the one relationship type sourced from regex-parsed prose rather than a structural field.
 * - 'modifies_charge_rate' — charge recharge-rate modifier ("Modify Charge Cooldown Recharge
 *   Rate% (Category)") — descriptive only, no verified magnitude conversion yet.
 * - 'hasted_cooldown' — tags a cooldown/charge category as haste-scaled ("Hasted Cooldown
 *   Duration (Category)" / "Hasted Cooldown Regeneration (Category)") — descriptive only; not a
 *   per-talent modifier so much as an always-on mechanical property (see the Mind Blast worked
 *   example in game-data.md).
 * - 'bypasses_cooldown' — conditionally ignores a cooldown ("Ignore Spell Charge Cooldown
 *   (Category)") — descriptive only.
 * - 'replaces' — action-bar spell replacement (e.g. "Beacon of Virtue replaces Beacon of
 *   Light"), from a Talent Entry line's replace="<name>" (id=<id>) annotation.
 *
 * The five 'modifies_charges'/'modifies_cooldown'/'modifies_charge_rate'/'hasted_cooldown'/
 * 'bypasses_cooldown' types all originate from the same structural "Category"/"Affected Spells
 * (Category)" field pair — SimC's shared textual marker for 8 distinct effect types, previously
 * (before 2026-08-01) all lumped under 'modifies_charges' regardless of which. See game-data.md's
 * "modifies_charges is a coarser label than the data actually supports" finding (2026-07-24) and
 * ImportSpellData::categoryRelationshipMapping() for the split.
 *
 * `modifier_value`/`modifier_unit` carry a computable magnitude only where confidently derivable
 * from a hand-verified worked example (both PvP-talent-sourced 'modifies_cooldown' rows, the
 * "Modify Cooldown Charge (Category)" 'modifies_charges' rows, and the "Modify Recharge Time
 * (Category)" 'modifies_cooldown' rows — see categoryRelationshipMapping()). Null means "not
 * confidently derivable," not zero — the relationship still renders descriptively via
 * `description`.
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
