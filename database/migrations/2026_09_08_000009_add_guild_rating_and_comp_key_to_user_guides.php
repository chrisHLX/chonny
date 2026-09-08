<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a guide needs to be findable and judged: a guild it belongs to, a rating, and a comp key.
 *
 * COMP_KEY is the one that is not obvious. "Show the best guides for THIS comp" on /wow-comps has
 * to match a set of three specs against every guide's roster, and doing that as a join over
 * user_guide_members would mean grouping and counting per guide on every page load of the site's
 * heaviest page. Instead the roster is denormalised to a sorted, hyphen-joined list of spec ids
 * ("3-17-24") the moment it changes, so the lookup is one indexed equality test. Sorted so that
 * the same three specs always produce the same key regardless of which slot each sits in — a comp
 * is a set, not an ordering.
 *
 * RATING_AVG / RATING_COUNT are denormalised for the same reason: the whole point of the feature
 * is "the best ones, not all of them", which means ORDER BY on a value that must not require
 * aggregating a ratings table per candidate. user_guide_ratings stays the source of truth and
 * these are recomputed from it on every write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->foreignId('guild_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            // Set null rather than cascade: deleting a guild must not delete its members' work.
            // The guide falls back to private, which is handled in isReadableBy().

            $table->decimal('rating_avg', 3, 2)->nullable()->after('summary');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_avg');
            $table->string('comp_key', 64)->nullable()->after('rating_count');

            $table->index('comp_key');
            // Composite, because the listing always asks both questions at once: which comp, and
            // which of those are any good.
            $table->index(['comp_key', 'rating_avg']);
        });
    }

    public function down(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropIndex(['comp_key', 'rating_avg']);
            $table->dropIndex(['comp_key']);
            $table->dropConstrainedForeignId('guild_id');
            $table->dropColumn(['rating_avg', 'rating_count', 'comp_key']);
        });
    }
};
