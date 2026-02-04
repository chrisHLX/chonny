<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedStripeEvent extends Model
{
    protected $fillable = ['stripe_id', 'type'];
}
