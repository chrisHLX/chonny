<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_spell_references', function (Blueprint $table) {
            $table->id();
            // Curated, hand-authored list of "which spells this module's prose actually names" —
            // authorial intent, not something re-derived from text matching on every render. The
            // full detail (description, cooldown, modifiers) for each of these is always computed
            // live from the current spells/spell_relationships tables at render time — this pivot
            // only records *which* spells, never a snapshot of their data.
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spell_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['module_id', 'spell_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_spell_references');
    }
};
