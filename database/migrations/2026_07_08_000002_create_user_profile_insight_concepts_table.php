<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profile_insight_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insight_id')->constrained('user_profile_insights')->cascadeOnDelete();
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['strength', 'growth_area']);
            $table->timestamps();

            $table->unique(['insight_id', 'concept_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_insight_concepts');
    }
};
