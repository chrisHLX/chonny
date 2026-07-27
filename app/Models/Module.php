<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Module extends Model
{
    protected $fillable = [
        'subject_id',
        'name',
        'slug',
        'content_source',
        'status',
        'type',
        'description',
        'race',
        'published',
        'created_by',
        'parent_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Module $module) {
            $base = Str::slug($module->name);
            $slug = $base;
            $i = 2;
            while (static::where('slug', $slug)->exists()) {
                $slug = "{$base}-{$i}";
                $i++;
            }
            $module->slug = $slug;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function resolveRouteBinding($value, $field = null): ?static
    {
        return $this->where('slug', $value)->firstOrFail();
    }

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
        return $this->hasMany(Module::class, 'parent_id');
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
        return $this->belongsTo(Module::class, 'parent_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function contextOptions()
    {
        return $this->belongsToMany(SubjectContextOption::class, 'module_context_option');
    }

    /** WoW class/spec/hero-tree this module's "Spells" reference section is about, if any. */
    public function gameBuild()
    {
        return $this->hasOne(ModuleGameBuild::class);
    }

    /** Curated list of spells this module's prose actually names — see module_spell_references
     *  migration. Full detail for each is always resolved live, never stored here. */
    public function spellReferences()
    {
        return $this->belongsToMany(Spell::class, 'module_spell_references');
    }

}
