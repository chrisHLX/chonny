<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One WoW Forever auction house scan a player's app shared (ForeverMarketService::store). The scan
 * itself is gzipped JSON at storage/app/{path}; its readings are merged into forever_prices.
 */
class ForeverScan extends Model
{
    protected $fillable = ['user_id', 'market', 'scanned_at', 'auctions', 'items', 'path'];

    protected $casts = ['scanned_at' => 'datetime'];
}
