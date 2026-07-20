<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A question with zero rows here is context-free (universal) — same convention as
        // module_context_option. Only meaningful for diagnostic_mcq scenario questions, but not
        // restricted at the schema level in case other question types ever want it.
        Schema::create('question_context_option', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_context_option_id')->constrained('subject_context_options')->cascadeOnDelete();
            $table->timestamps();

            // Explicit short name — the auto-generated one (question_context_option_question_id_
            // subject_context_option_id_unique) exceeds MySQL's 64-character identifier limit.
            $table->unique(['question_id', 'subject_context_option_id'], 'question_context_option_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_context_option');
    }
};
