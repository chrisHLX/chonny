<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_reflection_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reflection_id')->constrained('user_next_step_reflections')->cascadeOnDelete();
            $table->foreignId('concept_id')->nullable()->constrained()->nullOnDelete();
            $table->string('signal');
            $table->text('interpretation');
            $table->decimal('confidence', 3, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reflection_evidence');
    }
};
