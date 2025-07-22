<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('unit_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('attribute_name'); // e.g., HP, Armor
            $table->string('value'); // store as string for flexibility
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('unit_attributes');
    }
};
