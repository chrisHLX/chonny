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

    /**
     * Everyone in the guide's guild, plus anyone explicitly added. Membership is what grants
     * access, so adding somebody to the guild gives them every guild-visible guide at once —
     * and removing them takes all of them away without touching a guide row.
     *
     * A guide whose guild is deleted falls back to being readable by its author and explicit
     * viewers only. That is handled in UserGuide::isReadableBy() rather than by rewriting the
     * column, so the author's intent survives if the guild is recreated.
     */
    case Guild = 'guild';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Guild => 'Guild',
            self::Invited => 'Private',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Anyone can find and read it.',
            self::Guild => 'Everyone in your guild can read it.',
            self::Invited => 'Only people you add can read it.',
        };
    }
}
