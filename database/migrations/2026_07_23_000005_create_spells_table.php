<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patch_id')->constrained()->cascadeOnDelete();
            // The game's own numeric spell identifier (e.g. Blizzard spell id) — NOT this
            // table's PK, since the same external id can legitimately reappear across patches.
            $table->unsignedBigInteger('spell_id');
            $table->string('name');
            $table->string('school')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['patch_id', 'spell_id']);
            $table->index('spell_id');
            $table->index('patch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spells');
    }
};
