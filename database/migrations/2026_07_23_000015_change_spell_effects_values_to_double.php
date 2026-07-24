<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // decimal(16,4) silently rounded source values with >4 decimal places (e.g. -45.74709
        // -> -45.7471), causing every re-import to detect a permanent false "update" for those
        // rows. These are SimulationCraft floating-point coefficients, not exact/currency-style
        // values, so double is the correct type — it isn't decimal-place-limited the same way.
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->double('base_value')->nullable()->change();
            $table->double('scaled_value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('spell_effects', function (Blueprint $table) {
            $table->decimal('base_value', 16, 4)->nullable()->change();
            $table->decimal('scaled_value', 16, 4)->nullable()->change();
        });
    }
};
