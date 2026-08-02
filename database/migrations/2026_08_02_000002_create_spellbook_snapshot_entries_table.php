<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spellbook_snapshot_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('spellbook_snapshots')->cascadeOnDelete();
            // Blizzard spell id, NOT a local spells.id — the whole point of this table is to be
            // an independent ground-truth list to diff spells.spell_id against.
            $table->unsignedBigInteger('spell_id');
            $table->string('name');
            $table->enum('kind', ['spellbook', 'talent', 'pvp_talent']);
            // Build-specific ground truth (spec conditionals + talent modifications resolved).
            // Only ever read in the context of its own snapshot/loadout_string — never copied
            // onto spells or any general table (see spellbook-verifier.md).
            $table->text('resolved_description')->nullable();

            $table->index(['snapshot_id', 'kind']);
            $table->index('spell_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spellbook_snapshot_entries');
    }
};
