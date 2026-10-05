<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The desktop app's key to a player's account: it uploads their games to /api/coach. Only the
 * SHA-256 of the token is kept; the token itself is shown once, on /wow/coach, when it is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('coach_token_hash', 64)->nullable()->unique();
            $table->timestamp('coach_token_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['coach_token_hash']);
            $table->dropColumn(['coach_token_hash', 'coach_token_at']);
        });
    }
};
