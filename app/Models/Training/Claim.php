<?php

namespace App\Models\Training;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A claim examined with the four questions, and later what turned out to be true. */
class Claim extends Model
{
    public const OUTCOMES = ['held' => 'It held up', 'failed' => 'It was wrong', 'unclear' => 'Still unclear'];

    protected $table = 'training_claims';

    protected $fillable = ['claim', 'source', 'confidence_before', 'known', 'alternatives', 'would_change', 'if_acted', 'confidence_after', 'outcome', 'outcome_note', 'revisit_on'];

    protected function casts(): array
    {
        return ['revisit_on' => 'date', 'confidence_before' => 'integer', 'confidence_after' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDue(): bool
    {
        return $this->outcome === null && $this->revisit_on !== null && $this->revisit_on->lte(today());
    }
}
