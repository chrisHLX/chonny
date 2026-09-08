<?php

namespace App\Enums;

/**
 * A member's standing in a guild. Two roles, because a third would need a reason.
 *
 * Stored as a plain string column, never a DB enum — see the guilds migration.
 */
enum GuildRole: string
{
    /** Created it. May rename it, remove members, and delete it. Cannot leave — see Guild::leave(). */
    case Owner = 'owner';

    /** Everyone else. May read guild-visible guides and share their own with the guild. */
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Member => 'Member',
        };
    }
}
