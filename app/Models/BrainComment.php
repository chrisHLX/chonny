<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reader's comment on one section of the MindCollector Brain.
 *
 * Body is stored and rendered as PLAIN TEXT, never Markdown — same reasoning as
 * {@see UserGuideComment}: the document around it is our prose, this is anyone's.
 *
 * `section_key` is the explicit `{#id}` from `data/brain/brain.md`; NULL means the comment is on
 * the document as a whole. The key is never trusted from the request — see Brain::postComment(),
 * which resolves it against the parsed document before writing.
 */
class BrainComment extends Model
{
    public const MAX_LENGTH = 1000;

    protected $fillable = ['section_key', 'user_id', 'body'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
