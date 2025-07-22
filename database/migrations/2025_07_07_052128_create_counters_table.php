<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete(); // The unit being countered
            $table->foreignId('counter_unit_id')->constrained('units')->cascadeOnDelete(); // The countering unit
            $table->string('effectiveness')->default('Moderate'); // e.g., High, Moderate, Low
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('counters');
    }
};
