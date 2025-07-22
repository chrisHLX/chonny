<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('question');                      // The actual question text
            $table->json('answer');                          // Correct answer(s), can be text or structured
            $table->enum('type', ['mcq', 'true_false', 'match', 'open']);
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('easy');
            $table->string('created_by')->nullable();        // "system", "admin", or user id string if needed
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
