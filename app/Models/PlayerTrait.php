<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// 'Trait' is a reserved PHP keyword — this model maps to the `traits` table via $table.
class PlayerTrait extends Model
{
    protected $table = 'traits';

    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'active',
        'sort_order',
    ];

    public function questions()
    {
        // Placeholder — relationship will be defined once the pivot table exists.
        return $this->belongsToMany(Question::class);
    }
}
