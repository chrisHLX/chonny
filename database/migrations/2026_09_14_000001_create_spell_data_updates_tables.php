<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of what an import:spelldata run actually changed, so a game-data update can appear in
 * the Home feed ("Pain Suppression: cooldown 180s → 150s") instead of only in the importer's
 * end-of-run summary table, which counted updated rows and threw the detail away.
 *
 * One spell_data_updates row per import run that changed anything a player can press; one
 * spell_changes row per changed field. Only rows that EXISTED before the run are recorded — a
 * newly created spell is not a change, and recording creations would make a fresh environment's
 * first import look like the biggest patch in history.
 *
 * old_value / new_value are text, not numeric: description is one of the tracked fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spell_data_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('build_version', 40)->nullable();
            $table->unsignedInteger('changed_spell_count')->default(0);
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('spell_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spell_data_update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spell_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['spell_data_update_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spell_changes');
        Schema::dropIfExists('spell_data_updates');
    }
};
