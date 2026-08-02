<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpellbookSnapshotEntry extends Model
{
    const UPDATED_AT = null;

    const CREATED_AT = null;

    protected $fillable = [
        'snapshot_id',
        'spell_id',
        'name',
        'kind',
        'resolved_description',
    ];

    public function snapshot()
    {
        return $this->belongsTo(SpellbookSnapshot::class, 'snapshot_id');
    }
}
