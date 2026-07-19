<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A module with zero rows here is context-free (universal) — no separate flag needed.
        Schema::create('module_context_option', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_context_option_id')->constrained('subject_context_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['module_id', 'subject_context_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_context_option');
    }
};
