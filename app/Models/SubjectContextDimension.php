<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The *shape* of player specificity for a Subject — e.g. WoW: Class, Spec (parented to Class);
 * SC2: Race; LoL: Role. Dimension names are data, never code — see the "Subject Context
 * Dimensions" note in CLAUDE.md for the full design rationale (context vs behaviour vs evidence).
 */
class SubjectContextDimension extends Model
{
    protected $fillable = [
        'subject_id',
        'name',
        'slug',
        'order',
        'required',
        'parent_dimension_id',
    ];

    protected $casts = [
        'required' => 'boolean',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function parentDimension()
    {
        return $this->belongsTo(self::class, 'parent_dimension_id');
    }

    public function childDimensions()
    {
        return $this->hasMany(self::class, 'parent_dimension_id');
    }

    public function options()
    {
        return $this->hasMany(SubjectContextOption::class, 'dimension_id');
    }
}
