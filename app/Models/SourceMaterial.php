<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceMaterial extends Model
{
    protected $fillable = [
        'url',
        'url_type',
        'raw_content',
        'fetch_status',
        'fetched_at',
        'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'fetched_at' => 'datetime',
    ];
}
