<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// The collectible-card system (mint numbers, editions) was a derivation of Pokemon-card
// collecting bolted onto AI-generated modules from an earlier product direction — unrelated
// to adaptive learning, removed 2026-07-10. No other table has a foreign key into `cards`.

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cards');
    }

    public function down(): void
    {
        Schema::create('cards', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->foreignId('proficiency_id')->nullable()->constrained('proficiencies')->nullOnDelete();
            $table->json('stats');
            $table->float('accuracy');
            $table->integer('attempts');
            $table->unsignedInteger('mint_number');
            $table->string('edition')->default('First Edition');
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->unique(['module_id', 'mint_number'], 'cards_module_mint_unique');
        });
    }
};
