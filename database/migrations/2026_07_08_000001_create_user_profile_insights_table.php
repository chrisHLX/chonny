<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profile_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('archetype_id')->nullable()->constrained()->nullOnDelete();
            $table->string('profile_title');
            $table->string('confidence_level');
            $table->text('summary');
            $table->json('evidence')->nullable();
            $table->json('self_report_check')->nullable();
            $table->text('likely_in_game_pattern')->nullable();
            $table->string('growth_area_pattern')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['user_id', 'subject_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_insights');
    }
};
