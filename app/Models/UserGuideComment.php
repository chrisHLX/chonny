<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A comment on a guide. Flat, not threaded — see the migration.
 *
 * The body is stored and rendered as PLAIN TEXT, never Markdown, which is a deliberate difference
 * from a guide's own prose sections. Those are written by the author of the thing being read;
 * this is written by anyone, so the safest render is the one with no markup surface at all.
 */
class UserGuideComment extends Model
{
    public const MAX_LENGTH = 1000;

    protected $fillable = ['user_guide_id', 'user_guide_section_id', 'user_guide_block_id', 'user_id', 'body'];

    /** A note attached to one step or section, rather than to the guide as a whole. */
    public function isAnchored(): bool
    {
        return $this->user_guide_block_id !== null || $this->user_guide_section_id !== null;
    }

    public function section()
    {
        return $this->belongsTo(UserGuideSection::class, 'user_guide_section_id');
    }

    public function block()
    {
        return $this->belongsTo(UserGuideBlock::class, 'user_guide_block_id');
    }

    public function guide()
    {
        return $this->belongsTo(UserGuide::class, 'user_guide_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
