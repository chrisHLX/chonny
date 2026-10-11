<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A player can keep their games out of the shared averages (2026-10-12). Uploads feed the population
 * by default (wow:pool-export, wow:population-refresh); this is the opt-out, set on Your games. Null
 * means in: when someone opted out, not a flag, so it says when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('pool_opt_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pool_opt_out_at');
        });
    }
};
