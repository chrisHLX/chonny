<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_learning_path_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insight_id')->nullable()->constrained('user_profile_insights')->nullOnDelete();
            $table->unsignedInteger('order_index');
            $table->string('stage_key');
            $table->foreignId('concept_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('module_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'subject_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_learning_path_stages');
    }
};
