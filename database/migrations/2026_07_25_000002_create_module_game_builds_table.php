<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_game_builds', function (Blueprint $table) {
            $table->id();
            // One row per module, deliberately not columns on `modules` itself — that table is
            // shared by every module type (AI-generated, diagnostic, canonical), and this is a
            // WoW-game-data-specific extension only canonical context modules need. Keeping it
            // separate means the generic Module/ModulePage contract stays untouched (ContentQa
            // etc. never need to know this exists).
            $table->foreignId('module_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('specialization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hero_talent_tree_id')->nullable()->constrained('talent_trees')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_game_builds');
    }
};
