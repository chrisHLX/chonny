<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One in-game character export (MindCollectorExport addon, /mcexport) at a point in time.
 * Append-only — a new export is always a new snapshot, never an update to an old one, so
 * patch-over-patch diffing comes for free. See spellbook-verifier.md.
 */
class SpellbookSnapshot extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'class',
        'spec_id',
        'client_build',
        'loadout_string',
        'exported_at',
        'source_file_hash',
    ];

    protected $casts = [
        'exported_at' => 'datetime',
        'spec_id' => 'integer',
    ];

    public function entries()
    {
        return $this->hasMany(SpellbookSnapshotEntry::class, 'snapshot_id');
    }
}
