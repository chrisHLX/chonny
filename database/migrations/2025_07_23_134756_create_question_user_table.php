<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('question_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('question_id')->constrained()->onDelete('cascade');

            $table->unsignedInteger('attempts')->default(0); // you can compare the attempts to the correct_count to see how well they understand the question
            $table->unsignedInteger('correct_count')->default(0);

            $table->timestamp('last_answered_at')->nullable();
            $table->integer('total_time_spent')->default(0); // in seconds
            $table->integer('last_time_spent')->nullable(); // in seconds
            $table->text('last_answer')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_user');
    }
};
