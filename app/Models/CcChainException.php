<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A hand-authored CC-chain combo that works outside the general DR-sequencing algorithm
 * (e.g. Root -> Silence, Death Grip -> Sleep) — manual_knowledge tier throughout, never
 * generated. See the 2026_08_11 migrations and CLAUDE.md's "Synergies tab" section.
 */
class CcChainException extends Model
{
    protected $fillable = [
        'name',
        'reason',
    ];

    public function spells()
    {
        return $this->belongsToMany(Spell::class, 'cc_chain_exception_spells')
            ->withPivot('order')
            ->orderByPivot('order');
    }
}
