<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * App 0.6.39's addon read an auction house link's context field as its bonus, so every suffix version
 * ("of the Bandit", "of the Bear"...) was stored under the bonus "1": one item's versions merged into
 * one price, and the name and stats kept for "1" were whichever version was read first. No real bonus
 * is one digit (ForeverGearService::BONUS), so those rows go. Fixed versions send the real ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['forever_variant_prices', 'forever_item_variants'] as $table) {
            DB::table($table)->whereRaw('LENGTH(bonus) = 1')->delete();
        }
    }

    public function down(): void
    {
        // The rows were wrong; nothing to put back.
    }
};
