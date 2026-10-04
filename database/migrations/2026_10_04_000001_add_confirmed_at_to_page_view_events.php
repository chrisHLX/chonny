<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a browser confirmed a page view by running the page's script (TrackController::seen).
 *
 * A scraper that sends a browser user agent, and on 2-3 Oct 2026 a fake Google referrer too, was
 * counted as a visitor: 154 Alibaba Cloud addresses, not one of which loaded the site's CSS or
 * JavaScript. A browser runs the page; a script fetching HTML does not. Null for every view logged
 * before this, and for any the beacon never reached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_view_events', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('referrer_host');
        });
    }

    public function down(): void
    {
        Schema::table('page_view_events', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
