<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $fillable = [
        'user_id',
        'module_id',
        'proficiency_id',
        'stats',
        'accuracy',
        'attempts',
        'mint_number',
        'edition',
        'image_path',
    ];

    protected $casts = [
        'stats' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function proficiency()
    {
        return $this->belongsTo(Proficiency::class);
    }
}

