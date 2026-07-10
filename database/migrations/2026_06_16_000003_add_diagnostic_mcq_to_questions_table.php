<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The `type` column on `questions` is a MySQL ENUM (see create_questions_table migration).
// Laravel's Schema builder cannot extend an existing ENUM without listing all values explicitly,
// so we use a raw ALTER TABLE statement here on MySQL. SQLite (the test-suite driver, see
// phpunit.xml) has no MODIFY COLUMN syntax — Schema::table()->change() (via doctrine/dbal)
// rebuilds the column there instead, to the same end state.
// Current values: mcq, true_false, matching_pairs, ordering, open
// Added:          diagnostic_mcq

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('questions', function (Blueprint $table) {
                $table->enum('type', ['mcq', 'true_false', 'matching_pairs', 'ordering', 'open', 'diagnostic_mcq'])->change();
            });
            return;
        }

        DB::statement("ALTER TABLE `questions` MODIFY COLUMN `type` ENUM('mcq','true_false','matching_pairs','ordering','open','diagnostic_mcq') NOT NULL");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('questions', function (Blueprint $table) {
                $table->enum('type', ['mcq', 'true_false', 'matching_pairs', 'ordering', 'open'])->change();
            });
            return;
        }

        DB::statement("ALTER TABLE `questions` MODIFY COLUMN `type` ENUM('mcq','true_false','matching_pairs','ordering','open') NOT NULL");
    }
};
