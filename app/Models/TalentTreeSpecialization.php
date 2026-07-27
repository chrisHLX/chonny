<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which specs a hero talent tree is available to — e.g. Voidweaver is available to
 * Discipline and Shadow, not Holy. A hero tree belongs to exactly two specs by game
 * design, hence a real pivot rather than a single nullable spec_id on talent_trees.
 *
 * Deliberately NOT derived from Blizzard's Game Data API — that API's
 * playable-specialization endpoint returns the same hero_talent_trees list regardless
 * of which spec is queried (confirmed live, 2026-07-25), so it can't answer this
 * question. Populated instead from the SimC dump's own Talent Entry line, e.g.
 * "Talent Entry : Archon (Holy) [tree=hero, ...]" — see ImportSpellData::scanHeroTreeSpecs().
 */
class TalentTreeSpecialization extends Model
{
    protected $table = 'talent_tree_specializations';

    protected $fillable = [
        'talent_tree_id',
        'specialization_id',
    ];

    public function talentTree()
    {
        return $this->belongsTo(TalentTree::class);
    }

    public function specialization()
    {
        return $this->belongsTo(Specialization::class);
    }
}
