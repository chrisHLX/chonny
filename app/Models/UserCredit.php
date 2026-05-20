<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCredit extends Model
{
    protected $fillable = ['user_id', 'ai_credits', 'learned_credits'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getTotalAttribute()
    {
        return $this->ai_credits + $this->learned_credits;
    }
}
