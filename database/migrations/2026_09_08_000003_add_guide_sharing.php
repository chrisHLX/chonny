<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who can read a published guide, and the shareable URL it lives at.
 *
 * VISIBILITY IS A SEPARATE AXIS FROM STATUS, not more values on it. Status answers "has the author
 * finished with this" (draft / published); visibility answers "who may read it" (public / invited).
 * Folding them into one column would make "published to three teammates" and "published to the
 * world" the same kind of thing, and every future check would have to enumerate values instead of
 * asking one question. It also replaces the `unlisted` status, which was trying to be a visibility
 * value inside a status column.
 *
 * user_guide_viewers is the invite list for a private guide. It references a real users row rather
 * than storing an email string, so access cannot outlive an account and there is no pending-invite
 * state to reconcile — sharing with somebody who has not signed up is deliberately not supported
 * yet, and the UI says so rather than silently accepting an address that will never resolve.
 *
 * SLUGS BECOME UNIQUE PER AUTHOR, not globally. The public URL is /g/{username}/{slug}, so the
 * username already namespaces it — and a global unique meant the second person to write "RMD
 * opener" silently got "rmd-opener-1" because a stranger had taken the name. Per-author uniqueness
 * is both what the URL shape needs and what an author would expect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            // App\Enums\UserGuideVisibility — 'public' | 'invited'. Plain string, not a DB enum,
            // for the SQLite reason documented on the user_guides migration.
            $table->string('visibility')->default('invited')->after('status');
        });

        Schema::create('user_guide_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_guide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_guide_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropUnique('user_guides_slug_unique');
            $table->unique(['user_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slug']);
            $table->unique('slug', 'user_guides_slug_unique');
            $table->dropColumn('visibility');
        });

        Schema::dropIfExists('user_guide_viewers');
    }
};
