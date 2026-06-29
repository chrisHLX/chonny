<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stores self-reported context from survey_mcq questions.
// Kept separate from user_trait_evidence intentionally:
//   traits  = behavioural tendencies derived from diagnostic_mcq answers
//   profile = explicit self-reported context (years played, rating, role, goals)
// Profile evidence feeds the AI profile prompt as an interpreter, not as trait signals.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profile_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('question_key');        // e.g. "years_played", "arena_rating"
            $table->string('answer_text');          // display label e.g. "5+ years"
            $table->smallInteger('answer_value')->nullable(); // ordinal int e.g. 4 (for scale/ranking options)
            $table->json('metadata')->nullable();   // reserved for future structured fields
            $table->timestamp('answered_at');
            $table->timestamps();

            // One answer per user per survey question (updateOrCreate on retake)
            $table->unique(['user_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_evidence');
    }
};
