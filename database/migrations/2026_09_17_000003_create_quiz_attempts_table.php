<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One run through a quiz level. See App\Quiz\GameQuiz.
 *
 * `questions` holds the questions exactly as they were asked, answers included. They are built
 * fresh from live game data when the attempt starts, so a quiz never goes stale; they are stored
 * here only so grading uses what the player actually saw, and so the correct answer never has to
 * travel to the browser before the player picks one.
 *
 * `game` + `subject` keep this game-neutral: WoW uses `spec:{id}`, another game can use its own key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('session_id', 100)->nullable();
            $table->string('game', 30);
            $table->string('subject', 60);
            $table->unsignedTinyInteger('level');
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedTinyInteger('total');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'game', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
