<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ability_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type')->nullable(); // icon, splash, etc.
            $table->string('url');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('images');
    }
};
