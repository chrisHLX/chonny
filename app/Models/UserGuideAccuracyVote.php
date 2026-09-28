<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One reader's "accurate for this patch?" vote on a guide. See the create migration. */
class UserGuideAccuracyVote extends Model
{
    protected $fillable = [
        'user_guide_id',
        'user_id',
        'voter_key',
        'build_version',
        'accurate',
    ];

    protected $casts = [
        'accurate' => 'boolean',
    ];

    public function guide()
    {
        return $this->belongsTo(UserGuide::class, 'user_guide_id');
    }
}
