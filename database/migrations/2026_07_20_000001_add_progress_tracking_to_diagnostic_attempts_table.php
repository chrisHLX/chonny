<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_attempts', function (Blueprint $table) {
            // 0-based index of the question the attempt was last known to be on — updated on every
            // question transition (see DiagnosticQuizRunner::recordAttemptProgress()), not just at
            // start/finish, so an abandoned attempt still shows exactly where the user got stuck.
            $table->unsignedInteger('last_question_index')->nullable()->after('completed_at');

            // Snapshotted at attempt start rather than derived from the module's current question
            // count, so drop-off analysis reflects the quiz length as it was at the time, unaffected
            // by questions added/removed later.
            $table->unsignedInteger('total_questions')->nullable()->after('last_question_index');
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_attempts', function (Blueprint $table) {
            $table->dropColumn(['last_question_index', 'total_questions']);
        });
    }
};
