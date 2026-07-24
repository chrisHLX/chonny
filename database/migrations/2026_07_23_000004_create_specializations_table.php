<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specializations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            // Blizzard's numeric playable-specialization id (e.g. 257 = Holy Priest) — stable
            // across patches, used to match spec-scoped talent trees / pvp talents on import.
            $table->unsignedInteger('external_spec_id')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'slug']);
            $table->index('class_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specializations');
    }
};
