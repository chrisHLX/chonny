<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordered spell sequence for a cc_chain_exceptions row — e.g. Root -> Silence is two rows,
 * order 1 and 2. No dedicated pivot model, matching Question::concepts()/Module::tags()'s
 * existing bare-belongsToMany convention elsewhere in this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cc_chain_exception_spells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cc_chain_exception_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spell_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('order');
            $table->timestamps();

            $table->unique(['cc_chain_exception_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cc_chain_exception_spells');
    }
};
