<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the 1-5 star rating on user guides with a view count and a like.
 *
 * WHY, and it is not a style preference: this is a World of Warcraft arena site, where "rating"
 * already means your Current Rating — the number the entire game mode is organised around. A card
 * reading "Not rated yet" next to a 3v3 comp reads as a CR claim about the team, which is exactly
 * the wrong thing for it to say. Reported by the site owner from real use.
 *
 * Views and likes are also a better fit for what the signal has to do here. A guide needs to be
 * findable before it can be judged, and a 1-5 star scale asks a reader to form an opinion strong
 * enough to grade before they will click anything — so a new guide sits unrated indefinitely and
 * the sort has nothing to work with. A view is free and automatic, and a like is one click.
 *
 * DENORMALISED COUNTERS, not COUNT(*) at read time. Both numbers are read on every card of every
 * listing (browse, guild pages, the comp listing on /wow-comps) and written far less often, and
 * user_guides is already indexed on (comp_key, rating_avg) for exactly that kind of ordering.
 * like_count is kept in step with the user_guide_likes rows by UserGuide::syncLikeCount().
 *
 * The rating tables and columns are DROPPED rather than left inert. This project has had to clean
 * up dangling fields before (see CLAUDE.md's `recommended_module` removal, which was still being
 * generated and paid for long after nothing rendered it), and the rule that came out of it was to
 * remove rather than leave a second, contradicting source of truth in place. down() restores the
 * shape but not the ratings themselves — the rows are genuinely gone, which is the honest
 * consequence of the choice and is why this is called out here rather than buried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_guide_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_guide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One like per person per guide, enforced by the database rather than by the
            // application remembering to check — the same posture as user_guide_ratings had.
            $table->unique(['user_guide_id', 'user_id']);
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->unsignedInteger('view_count')->default(0)->after('summary');
            $table->unsignedInteger('like_count')->default(0)->after('view_count');

            // When this guide first became public, and therefore when its slug froze.
            //
            // The slug is generated at CREATE time, before the author has named the guide or
            // picked a comp, so every guide was getting a URL like /g/chris/untitled-guide and
            // keeping it — the slug deliberately never regenerates on rename, because moving a
            // shared URL breaks every existing link. That rule is right for a PUBLISHED guide and
            // pointless for a draft, which nobody has a link to yet. This column is how the two
            // are told apart: null means "still a draft, the slug is not load-bearing and can be
            // rebuilt from the real title and comp"; set means "someone may have this link, never
            // move it again". Also useful in its own right for ordering and "published 3 days ago".
            $table->timestamp('published_at')->nullable()->after('visibility');
        });

        // The old index led with comp_key and ordered by rating_avg; the column it ordered by is
        // about to disappear, so the index has to be replaced rather than just dropped — the comp
        // listing on /wow-comps still asks "best guides for this exact comp".
        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropIndex(['comp_key', 'rating_avg']);
            $table->index(['comp_key', 'like_count']);
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropColumn(['rating_avg', 'rating_count']);
        });

        Schema::dropIfExists('user_guide_ratings');
    }

    public function down(): void
    {
        Schema::create('user_guide_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_guide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('value');
            $table->timestamps();

            $table->unique(['user_guide_id', 'user_id']);
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->decimal('rating_avg', 3, 2)->nullable()->after('summary');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_avg');
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropIndex(['comp_key', 'like_count']);
            $table->index(['comp_key', 'rating_avg']);
        });

        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropColumn(['view_count', 'like_count', 'published_at']);
        });

        Schema::dropIfExists('user_guide_likes');
    }
};
