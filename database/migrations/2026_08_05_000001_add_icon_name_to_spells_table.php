<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->string('icon_name')->nullable()->after('not_in_spellbook');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('icon_name');
        });
    }
};
