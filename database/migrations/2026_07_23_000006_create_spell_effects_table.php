<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spell_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spell_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('effect_index');
            $table->string('type')->nullable();
            $table->decimal('base_value', 16, 4)->nullable();
            $table->decimal('scaled_value', 16, 4)->nullable();
            $table->timestamps();

            $table->unique(['spell_id', 'effect_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spell_effects');
    }
};
