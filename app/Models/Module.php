<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    //

    protected $fillable = [
        'subject_id',
        'name',
        'content_source',
        'status',
        'description',
        'race',
        'difficulty',
        'published',
        'created_by',
        'parent_module', 
        'version',       
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
        ->withPivot(['status', 'score', 'current_difficulty', 'last_activity_at', 'completed_at'])
        ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function modulePages()
    {
        return $this->hasMany(ModulePage::class);
    }

    public function children()
    {
        return $this->hasMany(Module::class, 'parent_module_id');
    }

    public function latestChild()
    {
        return $this->children()->orderByDesc('version')->first();
    }

    public function proficiencies()
    {
        return $this->belongsToMany(Proficiency::class, 'module_proficiency');
    }

    public function parent()
    {
        return $this->belongsTo(Module::class, 'parent_module_id');
    }

    public function cards()
    {
        return $this->hasMany(Card::class);
    }

}
