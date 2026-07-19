<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "One declaration per dimension" is a database invariant, not a service-layer rule —
        // it must be structurally impossible for a user to be both Rogue and Mage. Enforced by
        // the unique(user_id, dimension_id) constraint below, not just application code.
        Schema::create('user_subject_context', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dimension_id')->constrained('subject_context_dimensions')->cascadeOnDelete();
            $table->foreignId('subject_context_option_id')->constrained('subject_context_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'dimension_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subject_context');
    }
};
