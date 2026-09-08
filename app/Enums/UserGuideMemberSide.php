<?php

namespace App\Enums;

/**
 * Which team a roster row belongs to.
 *
 * A guide is written about a MATCHUP, not just about your own comp — "RMP vs Thug Cleave" is the
 * unit of real arena knowledge, and until now the enemy could only ever be a single spec named
 * per Defensives section. Modelling the enemy as a second SIDE of the same roster (rather than as
 * a separate table) means the enemy team inherits everything the comp already has for free: the
 * spec picker, class colouring, and — the part that matters most — its own talent builds, so
 * "their Priest is running Ultimate Penitence" is expressible.
 *
 * Stored as a plain string column, never a DB enum, for the same reason every other enum in this
 * schema is: an ALTER on a MySQL enum is invalid on SQLite, and the suite runs on SQLite.
 */
enum UserGuideMemberSide: string
{
    /** The author's own comp. The default, so every pre-existing row is one of these. */
    case Team = 'team';

    /** The comp being played against. */
    case Enemy = 'enemy';

    public function label(): string
    {
        return match ($this) {
            self::Team => 'Your comp',
            self::Enemy => 'Enemy team',
        };
    }

    /** Heading shown above the slot row. */
    public function slotLabel(): string
    {
        return match ($this) {
            self::Team => 'Your comp',
            self::Enemy => 'Playing against',
        };
    }
}
