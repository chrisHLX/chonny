<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    
    protected $fillable = ['name', 'description', 'category_id'];

    public function concepts()
    {
        return $this->hasMany(Concept::class);
    }

    public function modules()
    {
        return $this->hasMany(Module::class);
    }
    
    public function proficiencies()
    {
        return $this->hasMany(Proficiency::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function contextDimensions()
    {
        return $this->hasMany(SubjectContextDimension::class);
    }

}
