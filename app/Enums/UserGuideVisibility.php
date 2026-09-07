<?php

namespace App\Enums;

/**
 * Who may read a PUBLISHED guide. Orthogonal to UserGuideStatus, which answers whether the author
 * has finished with it at all — see the add_guide_sharing migration for why these are two columns
 * rather than more values on one.
 *
 * Stored as a plain string column, never a DB enum — see the user_guides migration for why.
 */
enum UserGuideVisibility: string
{
    /** Anyone with the link. Listed, indexable, shareable. */
    case Public = 'public';

    /**
     * Only people the author has explicitly added (user_guide_viewers), plus the author. The
     * default, so a guide can never become world-readable by accident — publishing is one
     * decision and going public is a second, separate one.
     */
    case Invited = 'invited';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Invited => 'Private',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Anyone with the link can read it.',
            self::Invited => 'Only people you add can read it.',
        };
    }
}
