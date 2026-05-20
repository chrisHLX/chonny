<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('module_id')->constrained()->onDelete('cascade');

            // Proficiency is chosen from your existing proficiencies table
            $table->foreignId('proficiency_id')->nullable()
                ->constrained('proficiencies')
                ->nullOnDelete();

            // Stats JSON (army, economy, tactics, etc.)
            $table->json('stats')->nullable();

            // Performance metrics
            $table->float('accuracy')->nullable();
            $table->integer('attempts')->default(1);

            // Card metadata
            $table->unsignedInteger('mint_number');
            $table->unique(['module_id', 'mint_number'], 'cards_module_mint_unique');
            $table->string('edition')->default('First Edition');
            $table->string('image_path')->nullable();

            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
