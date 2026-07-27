<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which class/spec/hero-tree a canonical context module's "Spells" reference section is about
 * (e.g. Priest / Discipline / Oracle). Declared explicitly by the module's seeder rather than
 * derived from the generic Subject/SubjectContextOption tagging system — that system only goes
 * Class -> Spec for WoW (no hero-tree dimension exists), and retrofitting one for a single
 * module would be speculative infrastructure. See ModuleSpellReferenceService for how this
 * scopes both name-resolution (seed time) and modifier-lookup (render time).
 */
class ModuleGameBuild extends Model
{
    protected $table = 'module_game_builds';

    protected $fillable = [
        'module_id',
        'class_id',
        'specialization_id',
        'hero_talent_tree_id',
    ];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function gameClass()
    {
        return $this->belongsTo(GameClass::class, 'class_id');
    }

    public function specialization()
    {
        return $this->belongsTo(Specialization::class);
    }

    public function heroTalentTree()
    {
        return $this->belongsTo(TalentTree::class, 'hero_talent_tree_id');
    }
}
