<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records whether a page view was a crawler, and where it came from.
 *
 * Measured against nginx's logs on 2026-09-24: 46% of real served pages over a fortnight came
 * from self-declared bots, and because a full-page Livewire component renders on a plain GET,
 * every one of those was already in this table. Half of every usage number was crawler.
 *
 * `is_bot` IS NULLABLE AND OLD ROWS STAY NULL. The user agent was never stored, so history
 * cannot be reclassified — a backfill would be a guess dressed as data. Null therefore means
 * "collected before this was measured", and Admin\PageUsage says so rather than silently mixing
 * the two. That is also why nothing is deleted: the old rows are the only record of what
 * happened, and this project has already lost 500 arena matches to a cull that looked tidy at
 * the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_view_events', function (Blueprint $table) {
            $table->boolean('is_bot')->nullable()->after('user_id');
            $table->string('referrer_host')->nullable()->after('is_bot');

            // The query Admin\PageUsage runs on every load: one page, real visitors, recent.
            $table->index(['is_bot', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('page_view_events', function (Blueprint $table) {
            $table->dropIndex(['is_bot', 'created_at']);
            $table->dropColumn(['is_bot', 'referrer_host']);
        });
    }
};
