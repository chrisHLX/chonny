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
        Schema::table('question_user', function (Blueprint $table) {
            $table->boolean('last_answer_correct')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_user', function (Blueprint $table) {
            $table->boolean('last_answer_correct')->nullable(false)->change();
        });
    }
};
