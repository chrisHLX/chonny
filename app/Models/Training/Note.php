<?php

namespace App\Models\Training;

use App\Enums\Training\NoteKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A highlight or note a learner put on a passage of a Training lesson. */
class Note extends Model
{
    protected $table = 'training_notes';

    protected $fillable = ['lesson_slug', 'block_id', 'quote', 'prefix', 'suffix', 'start_offset', 'kind', 'body', 'status', 'revisit_on'];

    protected function casts(): array
    {
        return ['kind' => NoteKind::class, 'revisit_on' => 'date', 'start_offset' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revisits(): HasMany
    {
        return $this->hasMany(NoteRevisit::class, 'training_note_id')->orderBy('created_at');
    }

    /** Notes due for a revisit today or earlier. */
    public function scopeDue(Builder $query): void
    {
        $query->whereNotNull('revisit_on')->where('status', '!=', 'resolved')->whereDate('revisit_on', '<=', today());
    }

    /** The shape the lesson page's script reads. */
    public function toClient(): array
    {
        return [
            'id' => $this->id,
            'block' => $this->block_id,
            'quote' => $this->quote,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'offset' => $this->start_offset,
            'kind' => $this->kind->value,
            'kindLabel' => $this->kind->label(),
            'body' => $this->body,
            'status' => $this->status,
            'revisitOn' => $this->revisit_on?->toDateString(),
            'revisits' => $this->revisits_count ?? null,
        ];
    }
}
