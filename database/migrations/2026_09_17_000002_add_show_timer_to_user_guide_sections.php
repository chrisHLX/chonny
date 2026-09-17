<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an author turn off a sequence's timer: the "Control after DR" / "Available every" totals and
 * each step's duration and DR percentage. Some sequences are about order rather than timing (a
 * rotation, a setup), and the numbers are noise there.
 *
 * Defaults to true so every existing section keeps showing exactly what it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guide_sections', function (Blueprint $table) {
            $table->boolean('show_timer')->default(true)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('user_guide_sections', function (Blueprint $table) {
            $table->dropColumn('show_timer');
        });
    }
};
