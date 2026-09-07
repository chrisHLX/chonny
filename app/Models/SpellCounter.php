<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One materialized "X counters Y" fact. See the create_spell_counters_table migration for why
 * this is a stored relationship rather than a per-request computation, and SpellCounterIndexer
 * for how each row is derived.
 *
 * Read it from either direction via Spell::counteredBy() / Spell::counters().
 */
class SpellCounter extends Model
{
    /** Every value `mechanism` can hold, in the order they are most useful to a reader. */
    public const MECHANISMS = [
        self::MECHANISM_IMMUNITY_MECHANIC,
        self::MECHANISM_IMMUNITY_TALENT,
        self::MECHANISM_IMMUNITY_SCHOOL,
        self::MECHANISM_USABLE_WHILE,
        self::MECHANISM_DODGE_PARRY,
    ];

    public const MECHANISM_IMMUNITY_MECHANIC = 'immunity_mechanic';

    /**
     * Immunity that exists ONLY when a specific PvP talent is selected - see
     * data/spelldata/cc-immunity-overrides.txt and the 2026_09_07 migration. Split from
     * MECHANISM_IMMUNITY_MECHANIC because the claim is genuinely weaker: the base ability alone
     * does nothing (Fade without Phase Shift is just a threat drop), so presenting it identically
     * to an unconditional immunity would overstate it for every viewer who has not taken that
     * talent. Still ranked high-confidence - the fact itself is certain, only its availability is
     * conditional.
     */
    public const MECHANISM_IMMUNITY_TALENT = 'immunity_talent';

    public const MECHANISM_IMMUNITY_SCHOOL = 'immunity_school';

    public const MECHANISM_USABLE_WHILE = 'usable_while';

    public const MECHANISM_DODGE_PARRY = 'dodge_parry';

    /**
     * How much a mechanism should be trusted, and therefore how prominently it is shown.
     *
     * The three high-confidence mechanisms describe an ability that genuinely stops or avoids the
     * CC: it grants immunity to the mechanic, grants immunity to the school, or raises the
     * dodge/parry chance against a Physical ability. Each is derived from a specific, unambiguous
     * effect row.
     *
     * `usable_while` is different in kind and is ranked low deliberately. Acting THROUGH a CC is a
     * weaker claim than preventing it, and — see SpellCounterIndexer's docblock — Blizzard's
     * "Allow While Stunned" attribute is demonstrably set on auras that merely persist through a
     * stun (Living Bomb, Freezing Trap), so the pool contains real false positives that no amount
     * of parsing can separate. It is kept because the attribute is real and the honest answer is
     * "this signal exists and is noisy", not silently dropped as if it were never there.
     */
    public const MECHANISM_CONFIDENCE = [
        self::MECHANISM_IMMUNITY_MECHANIC => 'high',
        self::MECHANISM_IMMUNITY_TALENT => 'high',
        self::MECHANISM_IMMUNITY_SCHOOL => 'high',
        self::MECHANISM_DODGE_PARRY => 'high',
        self::MECHANISM_USABLE_WHILE => 'low',
    ];

    /** Short human label per mechanism — one definition, so no blade re-invents these strings. */
    public const MECHANISM_LABELS = [
        self::MECHANISM_IMMUNITY_MECHANIC => 'Grants immunity',
        self::MECHANISM_IMMUNITY_TALENT => 'Grants immunity (with PvP talent)',
        self::MECHANISM_IMMUNITY_SCHOOL => 'School immunity',
        self::MECHANISM_USABLE_WHILE => 'Usable while affected',
        self::MECHANISM_DODGE_PARRY => 'Dodge / parry chance',
    ];

    protected $fillable = [
        'countered_spell_id',
        'counter_spell_id',
        'mechanism',
        'detail',
    ];

    public function counteredSpell()
    {
        return $this->belongsTo(Spell::class, 'countered_spell_id');
    }

    public function counterSpell()
    {
        return $this->belongsTo(Spell::class, 'counter_spell_id');
    }

    public function isHighConfidence(): bool
    {
        return (self::MECHANISM_CONFIDENCE[$this->mechanism] ?? 'low') === 'high';
    }

    public function label(): string
    {
        return self::MECHANISM_LABELS[$this->mechanism] ?? $this->mechanism;
    }
}
