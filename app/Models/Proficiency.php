<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proficiency extends Model
{
    use HasFactory;

    protected $fillable = ['subject_id', 'name', 'description', 'outcomes'];

    protected $casts = ['outcomes' => 'array'];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function modules()
    {
        return $this->belongsToMany(Module::class, 'module_proficiency');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_proficiency')->withPivot('progress');
    }
}
