<?php
// app/Models/Replay.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Replay extends Model
{
    protected $fillable = [
        'user_id',
        'filename',
        'original_name',
        'ai_feedback',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
