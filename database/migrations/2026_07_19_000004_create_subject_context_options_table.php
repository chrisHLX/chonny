<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_context_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dimension_id')->constrained('subject_context_dimensions')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->foreignId('parent_option_id')->nullable()->constrained('subject_context_options')->nullOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['dimension_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_context_options');
    }
};
