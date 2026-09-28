<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Was this guide accurate for 12.1?" — a one-second vote any reader can cast, account or not.
 *
 * WHY IT EXISTS (2026-09-29). Most readers read and leave; nothing on a guide asked them for
 * anything smaller than an account. A vote needs no account, so it breaks the passive read, and it
 * is a correction signal the machine guides can actually use: a guide voted inaccurate for the
 * current patch is one to re-check.
 *
 * - voter_key: "u:{user id}" for a signed-in reader, "s:{hash of the session id}" for a guest. One
 *   vote per voter per guide per patch; changing it replaces it.
 * - build_version: the patch the vote is about ("12.1"), so a guide's accuracy resets with a patch
 *   instead of carrying last season's verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The first run on MySQL created the table and then failed on the unique index's generated
        // name (over MySQL's 64 characters; SQLite, which the tests use, has no such limit). MySQL
        // does not roll DDL back, so that run left an empty, index-less table behind. It can hold
        // no votes (the feature never ran), so it is dropped and made properly.
        Schema::dropIfExists('user_guide_accuracy_votes');

        Schema::create('user_guide_accuracy_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_guide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('voter_key', 80);
            $table->string('build_version', 32);
            $table->boolean('accurate');
            $table->timestamps();

            // Named by hand: the generated names run past MySQL's 64-character limit.
            $table->unique(['user_guide_id', 'voter_key', 'build_version'], 'guide_accuracy_voter_unique');
            $table->index(['user_guide_id', 'build_version'], 'guide_accuracy_tally_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_guide_accuracy_votes');
    }
};
