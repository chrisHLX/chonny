<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pvp_talents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spec_id')->constrained('specializations')->cascadeOnDelete();
            $table->foreignId('patch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spell_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('unlock_level')->default(0);
            // Blizzard's pvp talent id — the natural upsert key alongside patch_id, since a pvp
            // talent's spell can (rarely) change between patches while this id stays stable.
            $table->unsignedInteger('external_pvp_talent_id');
            $table->timestamps();

            $table->unique(['patch_id', 'external_pvp_talent_id']);
            $table->index('spec_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pvp_talents');
    }
};
