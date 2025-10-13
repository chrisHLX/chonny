<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_concept_mastery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('concept_id')->constrained()->onDelete('cascade');
            $table->unsignedTinyInteger('mastery_percentage')->default(0); // 0-100%
            $table->unsignedInteger('total_questions')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'concept_id']); // one record per user per concept
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_concept_mastery');
    }
};
