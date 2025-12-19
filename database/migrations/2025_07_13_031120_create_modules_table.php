<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migration for Modules table.
     */
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('content_source')->default('OPEN AI 4.0-mini'); // Added a parent id so we can attach modules to parents
            $table->text('description')->nullable();
            $table->string('race')->nullable(); // 'Zerg', 'Terran', 'Protoss', or null
            $table->json('art_spec')->nullable(); // JSON field for art specifications (image svg generation)
            $table->string('art_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // optional custom module
            $table->foreignId('parent_id')->nullable()->constrained('modules')->nullOnDelete();
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
