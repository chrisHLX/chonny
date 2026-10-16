<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tell me when it's ready" on a coming game's page (2026-10-17): who asked, for which game. A
 * signed-in player is kept by their account, a visitor by the email they gave. `game` is the slug in
 * config/platform.php, a plain string rather than an enum (the vocabulary grows with each game).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_interests', function (Blueprint $table) {
            $table->id();
            $table->string('game', 20);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['game', 'user_id']);
            $table->unique(['game', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_interests');
    }
};
