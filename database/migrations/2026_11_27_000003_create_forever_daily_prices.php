<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WoW Forever prices kept for good, one row per market, item and day (2026-11-27, Chriso: the price
 * history is the part of the addon most useful later, so nothing of it should go). forever_prices
 * keeps each hour for ForeverMarketService::KEEP_DAYS; an older day is rolled up into these rows before
 * its hours are removed (ForeverMarketService::rollUp). The scans themselves stay on disk as before.
 *
 *  - low: the day's cheapest reading; usual: the median of its hourly cheapest readings;
 *    listed: the median of how many were listed; readings: how many hours had a reading.
 *  - forever_variant_prices_daily: the same for a weapon or armour piece's suffix versions, by bonus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forever_prices_daily', function (Blueprint $table) {
            $table->id();
            $table->string('market', 96);
            $table->unsignedInteger('item_id');
            $table->date('day');
            $table->unsignedBigInteger('low');
            $table->unsignedBigInteger('usual');
            $table->unsignedInteger('listed');
            $table->unsignedSmallInteger('readings');
            $table->unique(['market', 'item_id', 'day']);
            $table->index(['market', 'day']);
        });

        Schema::create('forever_variant_prices_daily', function (Blueprint $table) {
            $table->id();
            $table->string('market', 96);
            $table->unsignedInteger('item_id');
            $table->string('bonus', 128);
            $table->date('day');
            $table->unsignedBigInteger('low');
            $table->unsignedBigInteger('usual');
            $table->unsignedInteger('listed');
            $table->unsignedSmallInteger('readings');
            $table->unique(['market', 'item_id', 'bonus', 'day']);
            $table->index(['market', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forever_variant_prices_daily');
        Schema::dropIfExists('forever_prices_daily');
    }
};
