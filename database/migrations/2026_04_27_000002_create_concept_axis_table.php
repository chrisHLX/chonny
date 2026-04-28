<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concept_axis', function (Blueprint $table) {
            $table->foreignId('concept_id')->constrained()->onDelete('cascade');
            $table->foreignId('axis_id')->constrained()->onDelete('cascade');
            $table->decimal('weight', 5, 2)->nullable();
            $table->primary(['concept_id', 'axis_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concept_axis');
    }
};
