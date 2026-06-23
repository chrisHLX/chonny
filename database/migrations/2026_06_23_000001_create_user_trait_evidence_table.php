<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_trait_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->foreignId('question_id')->constrained()->onDelete('cascade');
            $table->foreignId('trait_id')->constrained('traits')->onDelete('cascade');
            $table->string('selected_answer');
            $table->unsignedInteger('selected_option_index')->nullable();
            $table->integer('points');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'question_id', 'trait_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_trait_evidence');
    }
};
