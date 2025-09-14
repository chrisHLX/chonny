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
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_module')->nullable()->constrained('modules'); // Added a parent id so we can attach modules to parents
            $table->text('description')->nullable();
            $table->string('race')->nullable(); // 'Zerg', 'Terran', 'Protoss', or null
            $table->string('difficulty_level')->default('beginner'); // beginner, intermediate, advanced
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // optional custom module
            $table->string('version')->default("V1"); // module Versions (each version is a child of the parent module)
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
