<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAxisMastery extends Model
{
    protected $fillable = ['user_id', 'axis_id', 'mastery_percentage'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function axis()
    {
        return $this->belongsTo(Axis::class);
    }
}
