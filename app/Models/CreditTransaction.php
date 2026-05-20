<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    protected $fillable = [
        'user_id', 'amount', 'credit_type', 'action', 'description'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
