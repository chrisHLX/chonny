<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One slot in the comp a guide is written for. See the create_user_guide_members_table migration
 * for why the roster is a table rather than columns on user_guides.
 */
class UserGuideMember extends Model
{
    /** A comp is at most 3 — this is arena, not raid. */
    public const MAX_MEMBERS = 3;

    protected $fillable = [
        'user_guide_id',
        'position',
        'spec_id',
        'talent_build_id',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function guide()
    {
        return $this->belongsTo(UserGuide::class, 'user_guide_id');
    }

    public function specialization()
    {
        return $this->belongsTo(Specialization::class, 'spec_id');
    }

    /**
     * The talents this member is playing, or null to fall back to the spec's admin-curated
     * default. See the add_talent_build_to_user_guide_members migration for why the build belongs
     * to the member rather than to the author.
     */
    public function talentBuild()
    {
        return $this->belongsTo(TalentBuild::class, 'talent_build_id');
    }
}
