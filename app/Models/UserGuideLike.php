<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's like of one guide. Unique per (guide, user) at the DB level — a ranking that can be
 * stuffed by liking twice is not a ranking.
 *
 * Replaced the 1-5 star rating on 2026-09-09. "Rating" is an overloaded word on an arena site,
 * where it already means Current Rating, so a card reading "Not rated yet" beside a 3v3 comp read
 * as a claim about the team — see the replace_guide_ratings_with_views_and_likes migration.
 *
 * user_guides.like_count is denormalised from this table and recomputed on every write; this
 * remains the source of truth. See UserGuide::syncLikeCount().
 */
class UserGuideLike extends Model
{
    protected $fillable = ['user_guide_id', 'user_id'];

    public function guide()
    {
        return $this->belongsTo(UserGuide::class, 'user_guide_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
