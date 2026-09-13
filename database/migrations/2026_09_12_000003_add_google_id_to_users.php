<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google's stable account id (the OpenID `sub`) for "Continue with Google".
 *
 * Keyed on this, never on email: a person can change the email on their Google account, and a
 * later sign-in must still land on the same MindCollector account. Unique so one Google account
 * can never be attached to two MindCollector accounts.
 *
 * Battle.net sign-in needs no column here — it resolves through battlenet_accounts.battlenet_id,
 * which is already unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id', 64)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
    }
};
