<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's rating of one guide, 1-5. Unique per (guide, user) at the DB level — a ranking
 * that can be stuffed by re-rating is not a ranking.
 *
 * user_guides.rating_avg / rating_count are denormalised from this table and recomputed on every
 * write; this remains the source of truth. See UserGuide::recalculateRating().
 */
class UserGuideRating extends Model
{
    public const MIN = 1;

    public const MAX = 5;

    protected $fillable = ['user_guide_id', 'user_id', 'value'];

    protected $casts = ['value' => 'integer'];

    public function guide()
    {
        return $this->belongsTo(UserGuide::class, 'user_guide_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
