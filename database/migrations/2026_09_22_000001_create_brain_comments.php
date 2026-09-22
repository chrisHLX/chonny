<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reader comments on the MindCollector Brain — the public statement of this project's arena model.
 *
 * A SEPARATE TABLE FROM `user_guide_comments` ON PURPOSE. Those hang off a `user_guide_id` and
 * cascade with the guide. The brain document is a committed file with no row to hang from, and
 * its comments must outlive any number of rewrites of the prose. Forcing it into the guide
 * comment table would have meant a nullable guide id on every guide comment, which makes the
 * cascade and every existing query wrong to save one table.
 *
 * `section_key` is the explicit `{#id}` written in `data/brain/brain.md`, NOT a slug of the
 * heading — headings get reworded and comments must not orphan when they do. A NULL section_key
 * is a comment on the document as a whole. It is a plain string with no FK because the thing it
 * points at is a file, so nothing at the DB level can enforce it; a comment whose section has
 * genuinely been deleted is still readable in the admin/export path rather than silently gone.
 *
 * The body renders as PLAIN TEXT, never markdown — same reasoning as UserGuideComment. It is
 * written by anyone, so the safest render is the one with no markup surface at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_comments', function (Blueprint $table) {
            $table->id();
            $table->string('section_key')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // Read in one order only: a section's comments, oldest first.
            $table->index(['section_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_comments');
    }
};
