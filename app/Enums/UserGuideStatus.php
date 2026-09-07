<?php

namespace App\Enums;

/**
 * Whether the author has finished with a guide.
 *
 * Deliberately only two states. An earlier version had a third, `unlisted`, which was trying to be
 * a VISIBILITY value inside a status column — "who may read this" is a genuinely separate question
 * from "is this finished", and it now lives in UserGuideVisibility (see the add_guide_sharing
 * migration). Keeping both in one column meant every access check had to enumerate combinations
 * instead of asking two clear questions.
 *
 * Stored as a plain string column, never a DB enum — see the user_guides migration for why.
 */
enum UserGuideStatus: string
{
    /** Visible only to its author, whatever the visibility says. The default for a new guide. */
    case Draft = 'draft';

    /** Finished. Who can actually read it is then decided by UserGuideVisibility. */
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
