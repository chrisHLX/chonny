<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ratings and comments — the signal that decides which guides surface, and the conversation that
 * makes them better.
 *
 * One rating per person per guide, enforced at the DB rather than in the application, because a
 * ranking that can be stuffed by re-rating is not a ranking. Re-rating updates the existing row.
 *
 * Comments are flat, not threaded. A guide's comment section is "does this still work in the
 * current patch" and "swap step 3 for X", which is a list, not a forum — and threading is
 * expensive to build well and easy to add later if it is ever actually wanted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_guide_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_guide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('value');
            $table->timestamps();

            $table->unique(['user_guide_id', 'user_id']);
        });

        Schema::create('user_guide_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_guide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // Read in one order only: a guide's comments, oldest first.
            $table->index(['user_guide_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_guide_comments');
        Schema::dropIfExists('user_guide_ratings');
    }
};
