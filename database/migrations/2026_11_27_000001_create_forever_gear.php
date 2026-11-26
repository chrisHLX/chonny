<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WoW Forever weapons and armour as the game itself describes them, for a gear page (2026-11-27,
 * ForeverGearService). The Auction House addon reads them in game (Gear.lua) and the app sends them.
 *
 *  - forever_items: every weapon and armour number in the client's files, from the addon's sweep
 *    (/mco items): what the server says about it, or in_game false when the server says it has no such
 *    item (other WoW versions' items, raid gear before its raid opens). stats are GetItemStats' keys
 *    without ITEM_MOD_ and _SHORT (RESISTANCE0_NAME is armour); lines are the tooltip's effect and
 *    requirement lines ("Equip: ...", "Classes: Hunter", "Requires The Defilers - Revered"); damage is
 *    [min, max, speed].
 *  - forever_item_variants: a suffix version ("of the Bandit"), by the bonus numbers on its link.
 *  - forever_variant_prices: a suffix version's cheapest listing per market and hour, merged like
 *    forever_prices (the scan with more auctions wins the hour).
 *  - forever_vendor_items: what each vendor sells, its price in copper or other costs ([[count, item
 *    number or currency name]]), stock when limited, and its "Requires" lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forever_items', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->primary();
            $table->boolean('in_game');
            $table->string('name', 128)->nullable();
            $table->unsignedTinyInteger('quality')->nullable();
            $table->unsignedSmallInteger('item_level')->nullable();
            $table->unsignedSmallInteger('required_level')->nullable();
            $table->string('slot', 32)->nullable();
            $table->unsignedSmallInteger('class_id')->nullable();
            $table->unsignedSmallInteger('subclass_id')->nullable();
            $table->unsignedTinyInteger('bind')->nullable();
            $table->unsignedInteger('sell_price')->nullable();
            $table->text('stats')->nullable();
            $table->text('lines')->nullable();
            $table->string('damage', 64)->nullable();
            $table->string('build', 32);
            $table->foreignId('user_id');
            $table->timestamp('updated_at');
            $table->index(['in_game', 'slot', 'required_level']);
        });

        Schema::create('forever_item_variants', function (Blueprint $table) {
            $table->unsignedInteger('item_id');
            $table->string('bonus', 64);
            $table->string('name', 160);
            $table->text('stats')->nullable();
            $table->foreignId('user_id');
            $table->timestamp('updated_at');
            $table->primary(['item_id', 'bonus']);
        });

        Schema::create('forever_variant_prices', function (Blueprint $table) {
            $table->id();
            $table->string('market', 96);
            $table->unsignedInteger('item_id');
            $table->string('bonus', 64);
            $table->timestamp('hour');
            $table->timestamp('scanned_at');
            $table->unsignedBigInteger('min_unit');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('scan_auctions');
            $table->unique(['market', 'item_id', 'bonus', 'hour']);
            $table->index(['market', 'hour']);
        });

        Schema::create('forever_vendor_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('npc_id');
            $table->string('npc_name', 128);
            $table->string('zone', 128)->nullable();
            $table->string('subzone', 128)->nullable();
            $table->unsignedInteger('map_id')->nullable();
            $table->decimal('x', 5, 1)->nullable();
            $table->decimal('y', 5, 1)->nullable();
            $table->unsignedInteger('item_id')->index();
            $table->unsignedBigInteger('price');
            $table->unsignedSmallInteger('stack');
            $table->unsignedSmallInteger('stock')->nullable();
            $table->text('costs')->nullable();
            $table->text('requires')->nullable();
            $table->foreignId('user_id');
            $table->timestamp('seen_at');
            $table->unique(['npc_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forever_vendor_items');
        Schema::dropIfExists('forever_variant_prices');
        Schema::dropIfExists('forever_item_variants');
        Schema::dropIfExists('forever_items');
    }
};
