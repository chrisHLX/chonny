<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_node_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spell_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rank');
            $table->unsignedInteger('max_rank');
            // Blizzard's talent_id (distinct from spell_id) — kept for traceability back to the
            // source record; not used for any relationship in this schema.
            $table->unsignedInteger('external_talent_id')->nullable();
            $table->timestamps();

            // A CHOICE node has multiple entries at the same rank (one per alternative spell);
            // a regular node has multiple entries only when max_ranks > 1 (one per rank tier).
            $table->unique(['talent_node_id', 'rank', 'spell_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_node_entries');
    }
};
