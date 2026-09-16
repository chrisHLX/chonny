<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest plans: a visitor can try the builder before signing up.
 *
 * A guest plan is a normal user_guides row with no user_id and a random guest_token instead. The
 * visitor's browser holds the same token in a cookie (see GuestPlanService), and that cookie is
 * the only thing that can open it. When they sign up or sign in, the row moves onto their account.
 * Rows nobody claims are deleted after a few days.
 *
 * Stored for real rather than kept in the browser because the whole builder (roster, sections,
 * steps, talent builds, DR maths) reads from these tables. A second in-memory copy of it would
 * drift from the real one with every change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('guest_token', 64)->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        \App\Models\UserGuide::whereNull('user_id')->delete();

        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropIndex(['guest_token']);
            $table->dropColumn('guest_token');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
