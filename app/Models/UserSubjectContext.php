<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's declared context for one dimension of one subject — e.g. "I play Rogue." Context,
 * not behaviour or evidence (see the design-principle note in CLAUDE.md): declared by the
 * player, never inferred. unique(user_id, dimension_id) at the DB level makes it structurally
 * impossible to declare two options for the same dimension — always use
 * SubjectContextService::declare() rather than creating/updating rows directly, so the
 * cascade-clear-children rule (changing Class clears Spec) is never bypassed.
 */
class UserSubjectContext extends Model
{
    protected $table = 'user_subject_context';

    protected $fillable = [
        'user_id',
        'dimension_id',
        'subject_context_option_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function dimension()
    {
        return $this->belongsTo(SubjectContextDimension::class, 'dimension_id');
    }

    public function option()
    {
        return $this->belongsTo(SubjectContextOption::class, 'subject_context_option_id');
    }
}
