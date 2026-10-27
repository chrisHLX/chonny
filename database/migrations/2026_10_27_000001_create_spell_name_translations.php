<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A spell's name as another language's game client logs it, to its English name (2026-10-27,
 * docs/international.md). A Chinese log writes 激流 where an English one writes Riptide; the stored
 * rounds keep the log's name, and every icon and arena frame looks spells up by the English one.
 *
 * Learned from each uploaded round's own lines (id and name side by side) and, for rounds stored
 * before this, from Blizzard's localized names (wow:translate-spell-names). `spell_id` is Blizzard's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spell_name_translations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191)->unique();
            $table->string('english', 191);
            $table->unsignedInteger('spell_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spell_name_translations');
    }
};
