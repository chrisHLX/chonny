<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_tree_specializations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_tree_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specialization_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['talent_tree_id', 'specialization_id'], 'talent_tree_specializations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_tree_specializations');
    }
};
