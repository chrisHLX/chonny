<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_next_step_reflections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('next_step_id')->constrained('user_next_steps')->cascadeOnDelete();
            $table->enum('did_try', ['yes', 'no', 'partially']);
            $table->text('how_it_went');
            $table->text('why_reasoning');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique('next_step_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_next_step_reflections');
    }
};
