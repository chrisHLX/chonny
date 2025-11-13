<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_proficiency', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('proficiency_id')->constrained()->onDelete('cascade');
            $table->float('progress')->default(0); // optional, track progress %
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_proficiency');
    }
};

