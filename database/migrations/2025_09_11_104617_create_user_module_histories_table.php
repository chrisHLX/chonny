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
        Schema::create('user_module_histories', function (Blueprint $table) {
            $table->id();

            // Core relationships
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('module_id')->constrained()->onDelete('cascade');

            // Tracking attempts
            $table->unsignedInteger('attempt_number')->default(1);

            // Wrong and right questions can be stored as JSON arrays of question IDs
            // But these questions need to be the ones that the module was generated with
            $table->json('wrong_questions')->nullable();
            $table->json('right_questions')->nullable();

            // Version tracking (helps you trace progression, e.g. V2, V3A)
            $table->string('module_version')->nullable();

            // Status fields
            $table->enum('status', ['in_progress', 'completed', 'failed'])->default('in_progress');
            $table->timestamp('attempted_at')->useCurrent();

            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_module_histories');
    }
};
