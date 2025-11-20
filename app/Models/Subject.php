<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    
    protected $fillable = ['name', 'description'];

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
    
}
