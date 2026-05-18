<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_content', function (Blueprint $table) {
            $table->foreignId('source_material_id')
                ->nullable()
                ->after('ai_request_id')
                ->constrained('source_materials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subject_content', function (Blueprint $table) {
            $table->dropForeign(['source_material_id']);
            $table->dropColumn('source_material_id');
        });
    }
};
