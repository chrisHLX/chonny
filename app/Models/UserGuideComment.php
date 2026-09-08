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

    protected $fillable = ['user_guide_id', 'user_id', 'body'];

    public function guide()
    {
        return $this->belongsTo(UserGuide::class, 'user_guide_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
