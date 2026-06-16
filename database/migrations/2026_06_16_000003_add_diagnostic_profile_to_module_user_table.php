<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_user', function (Blueprint $table) {
            $table->json('diagnostic_profile')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('module_user', function (Blueprint $table) {
            $table->dropColumn('diagnostic_profile');
        });
    }
};
