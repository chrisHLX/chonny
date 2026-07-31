<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_build_pvp_choices', function (Blueprint $table) {
            $table->id();
            // PvP talents have no tree/node structure of their own (see pvp_talents table) — just
            // 4 flat slots (pvptalents.json's compatible_slots) — so this can't reuse
            // talent_build_choices, which is keyed on talent_node_id.
            $table->foreignId('talent_build_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('slot');
            $table->foreignId('pvp_talent_id')->constrained('pvp_talents')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['talent_build_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_build_pvp_choices');
    }
};
