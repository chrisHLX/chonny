<?php

namespace App\Models\Training;

use App\Enums\Training\RevisitOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteRevisit extends Model
{
    protected $table = 'training_note_revisits';

    protected $fillable = ['outcome', 'reflection'];

    protected function casts(): array
    {
        return ['outcome' => RevisitOutcome::class];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'training_note_id');
    }
}
