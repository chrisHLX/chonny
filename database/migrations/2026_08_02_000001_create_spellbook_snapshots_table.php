<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spellbook_snapshots', function (Blueprint $table) {
            $table->id();
            // Raw addon-exported identity — deliberately NOT resolved to classes/specializations
            // FKs at import time (see wow:import-spellbook), so a snapshot always imports even
            // when local reference data hasn't caught up to a new patch yet. wow:diff-spellbook
            // resolves class/spec at diff time instead.
            $table->string('class');
            $table->unsignedInteger('spec_id');
            $table->string('client_build');
            // The build's canonical identity — the snapshot is meaningless without it.
            $table->text('loadout_string');
            $table->timestamp('exported_at');
            $table->string('source_file_hash');
            $table->timestamp('created_at')->nullable();

            $table->unique('source_file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spellbook_snapshots');
    }
};
