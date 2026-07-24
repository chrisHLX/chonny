<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deviates from the originally sketched (id, spec_id, patch_id) shape: real talent data
        // has trees that are NOT spec-scoped (the shared class tree, and hero trees that are
        // shared across a subset of specs within a class), so spec_id must be nullable and the
        // tree needs a class_id + type to stay identifiable/upsertable without one.
        Schema::create('talent_trees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spec_id')->nullable()->constrained('specializations')->cascadeOnDelete();
            $table->enum('type', ['class', 'spec', 'hero']);
            $table->string('name');
            // Blizzard's talent_tree_id (class trees) or hero tree id — nullable since not
            // every source records one uniformly across tree types.
            $table->unsignedInteger('external_tree_id')->nullable();
            $table->timestamps();

            $table->unique(['patch_id', 'class_id', 'type', 'name']);
            $table->index('spec_id');
            $table->index('patch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_trees');
    }
};
