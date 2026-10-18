<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Internal Cooldown: 30 seconds" — how often a proc can fire, which descriptions ask for as
 * "$proccooldown" ("Roll removes a snare effect once every $proccooldown sec", Swift Art). Never
 * stored until 2026-10-18, so those read "(varies)". Same "spell" name rule as the ap_coefficient
 * migration beside it: deploy.sh re-imports the spell data when one ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->decimal('proc_cooldown_seconds', 8, 2)->nullable()->after('proc_chance');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('proc_cooldown_seconds');
        });
    }
};
