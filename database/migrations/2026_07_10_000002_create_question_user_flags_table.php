<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A user flagging a question as personally important/worth remembering while taking a quiz —
// deliberately its own table, not folded into `question_user` (which already carries unrelated
// attempt-tracking columns).

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_user_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_user_flags');
    }
};
