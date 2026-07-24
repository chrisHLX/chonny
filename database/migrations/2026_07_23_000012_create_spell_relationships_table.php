<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spell_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_spell_id')->constrained('spells')->cascadeOnDelete();
            $table->foreignId('target_spell_id')->constrained('spells')->cascadeOnDelete();
            $table->string('relationship_type')->default('modifies');
            $table->text('description')->nullable();
            $table->timestamps();

            // Explicit short name — the auto-generated one (spell_relationships_source_spell_id_
            // target_spell_id_relationship_type_unique) exceeds MySQL's 64-char identifier limit.
            $table->unique(['source_spell_id', 'target_spell_id', 'relationship_type'], 'spell_relationships_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spell_relationships');
    }
};
