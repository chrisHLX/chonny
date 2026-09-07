<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-authored burst guides and CC chains — the hand-written counterpart to the guides this site
 * derives from the match corpus.
 *
 * WHY A TABLE AT ALL. The derived guides have no database representation whatsoever: a burst guide
 * is one JSON file per spec under data/claudes-guides/burst-guides/, a CC chain is an array of
 * timestamped steps under data/arena-logs/cc-chains/, and both are regenerated wholesale by
 * wow:refresh-match-derived. There is no row, no id, no owner, no title and no draft state to
 * extend. So this is not "letting users edit burst guides" — it is a parallel, owned, persisted
 * artifact type that happens to render through the same spell components. The derived files stay
 * read-only and machine-generated; nothing in this feature writes to them.
 *
 * TRUST TIER. Everything else on this site earns its credibility from being derived from real
 * match evidence and never guessed (curation files, verified_override rows, the standing
 * flag-don't-guess rule). A player-written guide is the opposite tier by construction, so it must
 * never be presentable as a derived one. That separation is a routing and presentation concern
 * rather than a schema one, but it is the reason this table exists apart from the corpus instead
 * of alongside it.
 *
 * STRING COLUMNS, NOT DB ENUMS, for `type` and `status`. This repeats a rule the codebase already
 * learned the hard way: a MySQL `ALTER TABLE ... MODIFY COLUMN` on an enum is invalid on SQLite,
 * and phpunit.xml runs the whole suite against in-memory SQLite via RefreshDatabase — extending
 * the `source` enum on spell_class_availability that way broke every test in the suite on
 * 2026-08-06. spell_counters.mechanism made the same call for the same reason. Values are governed
 * by App\Enums\UserGuideType and App\Enums\UserGuideStatus, cast on the model.
 *
 * PATCH SCOPING is deliberately looser here than on talent_builds, which cascade-deletes with its
 * patch. patch_id records what the author was looking at when they wrote the guide; it is NOT how
 * the guide's abilities are resolved (blocks store Blizzard's external spell id precisely so they
 * survive a patch bump — see the user_guide_blocks migration). A guide therefore outlives its
 * patch, and patch_id nulls out rather than destroying user content.
 *
 * class_id and spec_id are BOTH nullable, and that is not laziness. A burst guide is meaningless
 * without one owning spec — globals, GCD and go length are all per-spec facts — but a real CC
 * chain routinely spans a whole comp (the corpus records distinctCasters and a per-step
 * sourceSpecSlug for exactly this reason). Requiring a spec at the schema level would make the
 * more interesting half of the feature unrepresentable. UserGuideType::requiresSpec() carries the
 * per-type rule, enforced in the application layer where it can actually be conditional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // App\Enums\UserGuideType — 'burst' | 'cc_chain'.
            $table->string('type');
            // App\Enums\UserGuideStatus — 'draft' | 'published' | 'unlisted'.
            $table->string('status')->default('draft');

            // Nullable by design — see the class docblock. A burst guide needs a spec; a CC chain
            // may span a comp and have neither.
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('spec_id')->nullable()->constrained('specializations')->nullOnDelete();

            // What the author wrote this against. Informational: never used to resolve abilities.
            $table->foreignId('patch_id')->nullable()->constrained('patches')->nullOnDelete();

            $table->string('title');
            // Route key, following the Module convention of binding on slug rather than id.
            $table->string('slug')->unique();
            $table->text('summary')->nullable();

            $table->timestamps();

            // Author's own listing ("my guides"), newest first.
            $table->index(['user_id', 'updated_at']);
            // Public browse: guides for a given spec, filtered by status.
            $table->index(['spec_id', 'status']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_guides');
    }
};
