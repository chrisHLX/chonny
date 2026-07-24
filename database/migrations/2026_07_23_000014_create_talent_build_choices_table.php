<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_build_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_build_id')->constrained()->cascadeOnDelete();
            $table->foreignId('talent_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chosen_entry_id')->constrained('talent_node_entries')->cascadeOnDelete();
            $table->unsignedInteger('rank');
            $table->timestamps();

            $table->unique(['talent_build_id', 'talent_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_build_choices');
    }
};
