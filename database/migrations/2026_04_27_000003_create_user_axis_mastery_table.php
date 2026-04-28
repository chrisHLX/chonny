<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_axis_mastery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('axis_id')->constrained()->onDelete('cascade');
            $table->decimal('mastery_percentage', 5, 2)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'axis_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_axis_mastery');
    }
};
