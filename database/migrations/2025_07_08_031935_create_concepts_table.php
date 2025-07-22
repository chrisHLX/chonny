<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('concepts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', [
                'strategy',
                'tactic',
                'economic',
                'composition',
                'micro',
                'map control',
                'timing',
                'transition',
                'defensive',
                'harassment',
                'offensive',
                'scouting',
                'unit control',
                'resource management',
                'positioning',
                'build order',
                'macro',
                'adaptation',
                'psychological',
                'meta',
                'other'
            ]);
            $table->text('description')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('concepts');
    }
};
