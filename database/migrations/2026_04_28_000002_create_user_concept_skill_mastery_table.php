<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_concept_skill_mastery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('concept_id')->constrained()->onDelete('cascade');
            $table->enum('skill_type', ['recall', 'analysis', 'application']);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->decimal('mastery_percentage', 5, 2)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'concept_id', 'skill_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_concept_skill_mastery');
    }
};
