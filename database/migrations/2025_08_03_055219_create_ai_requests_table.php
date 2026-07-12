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
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // optional: if request is tied to a user
            $table->string('purpose'); // e.g. 'tag_question', 'diagnose_progress', 'generate_quiz'
            $table->longText('prompt'); // raw prompt sent to OpenAI
            $table->json('response'); // full API response as JSON
            $table->json('metadata')->nullable(); // store additional data like temperature, model, related IDs
            $table->integer('duration_ms')->nullable();
            $table->text('template_prompt')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
