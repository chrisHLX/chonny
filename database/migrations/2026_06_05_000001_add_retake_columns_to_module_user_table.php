<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_user', function (Blueprint $table) {
            $table->timestamp('retake_started_at')->nullable()->after('completed_at');
            $table->unsignedInteger('retake_count')->default(0)->after('retake_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('module_user', function (Blueprint $table) {
            $table->dropColumn(['retake_started_at', 'retake_count']);
        });
    }
};
