<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleSuggestions extends Model
{
    //
    protected $fillable = [
        'module_id',
        'stats_hash',
        'suggestions_json',
    ];

    protected $casts = [
        'suggestions_json' => 'array',
    ];

    public function getRecommendations()
    {
        return $this->suggestions_json['recommendations'] ?? [];
    }


}
