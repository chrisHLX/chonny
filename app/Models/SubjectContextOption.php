<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One closed-list value within a SubjectContextDimension — e.g. "Zerg" (Race), "Rogue" (Class),
 * "Assassination" (Spec, parented to Rogue via parent_option_id). This same table is the
 * vocabulary shared by both user declarations (user_subject_context) and module tagging
 * (module_context_option) — that shared FK is what makes the ontology closed: neither the AI
 * nor an admin can invent a label outside it.
 */
class SubjectContextOption extends Model
{
    protected $fillable = [
        'dimension_id',
        'name',
        'slug',
        'parent_option_id',
        'order',
    ];

    public function dimension()
    {
        return $this->belongsTo(SubjectContextDimension::class, 'dimension_id');
    }

    public function parentOption()
    {
        return $this->belongsTo(self::class, 'parent_option_id');
    }

    public function childOptions()
    {
        return $this->hasMany(self::class, 'parent_option_id');
    }

    /**
     * Depth in the parent_option_id chain — used by the routing preference's specificity
     * ordering (deeper match wins). 0 for a root option (e.g. Rogue), 1 for its child (e.g.
     * Assassination). Computed on the fly rather than stored — this hierarchy is shallow
     * (2 levels max per the plan) and options rarely change, so there's no need for a
     * denormalised depth column.
     */
    public function depth(): int
    {
        $depth = 0;
        $current = $this;

        while ($current->parent_option_id) {
            $depth++;
            $current = $current->parentOption;

            if (!$current) {
                break;
            }
        }

        return $depth;
    }

    public function modules()
    {
        return $this->belongsToMany(Module::class, 'module_context_option');
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'question_context_option');
    }
}
