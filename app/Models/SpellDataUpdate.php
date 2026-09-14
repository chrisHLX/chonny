<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One import:spelldata run that changed at least one ability a player can press. See SpellChangeRecorder. */
class SpellDataUpdate extends Model
{
    protected $fillable = ['patch_id', 'build_version', 'changed_spell_count'];

    public function patch()
    {
        return $this->belongsTo(Patch::class);
    }

    public function changes()
    {
        return $this->hasMany(SpellChange::class);
    }
}
