<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->integer('duration_ms')->nullable()->after('metadata');
            $table->text('template_prompt')->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropColumn(['duration_ms', 'template_prompt']);
        });
    }
};
