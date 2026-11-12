<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WoW Forever auction house scans pooled from every app user who shares them (2026-11-12,
 * ForeverMarketService). A market is region, realm and faction ("US/Arugal/Horde"): faction too
 * until it's known whether Forever's auction house is shared between them; the scans are kept whole
 * (forever_scans.path) so markets can be merged later.
 *
 *  - forever_scans: one row per scan a player sent, the scan itself gzipped on disk.
 *  - forever_prices: one reading per market, item and hour. Scans of the same hour from several
 *    players merge into one: the most complete scan (most auctions) wins, a later one breaks a tie,
 *    and `sources` counts how many scans saw it. `expiring` is how many of `quantity` sat in each
 *    time-left band (under 30m, 30m-2h, 2-12h, over 12h), "a,b,c,d", for telling expiry from selling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forever_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('market', 96)->index();
            $table->timestamp('scanned_at');
            $table->unsignedInteger('auctions');
            $table->unsignedInteger('items');
            $table->string('path', 191);
            $table->timestamps();
            $table->unique(['user_id', 'market', 'scanned_at']);
        });

        Schema::create('forever_prices', function (Blueprint $table) {
            $table->id();
            $table->string('market', 96);
            $table->unsignedInteger('item_id');
            $table->timestamp('hour');
            $table->timestamp('scanned_at');
            $table->unsignedBigInteger('min_unit');
            $table->unsignedInteger('quantity');
            $table->string('expiring', 64)->nullable();
            $table->unsignedInteger('scan_auctions');
            $table->unsignedSmallInteger('sources')->default(1);
            $table->unique(['market', 'item_id', 'hour']);
            $table->index(['market', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forever_prices');
        Schema::dropIfExists('forever_scans');
    }
};
