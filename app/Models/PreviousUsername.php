<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A handle a player changed away from — see the create_previous_usernames_table migration. */
class PreviousUsername extends Model
{
    protected $fillable = ['user_id', 'username'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
