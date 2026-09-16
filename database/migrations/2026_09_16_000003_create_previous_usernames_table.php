<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handles a player has changed away from.
 *
 * A handle is in every shared guide URL (/g/{username}/{slug}), so changing it must not break links
 * people already have. Each old handle is kept here and redirects to the player's current one, and
 * it stays reserved to that player: if someone else could take it, every old link would quietly
 * start pointing at a stranger. The owner can take an old handle back (the row is deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('previous_usernames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('previous_usernames');
    }
};
