<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The `type` column on `questions` is a MySQL ENUM.
// Laravel's Schema builder cannot extend an existing ENUM without listing all values explicitly,
// so we use a raw ALTER TABLE statement here.
// Current values: mcq, true_false, matching_pairs, ordering, open, diagnostic_mcq
// Added:          survey_mcq

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `questions` MODIFY COLUMN `type` ENUM('mcq','true_false','matching_pairs','ordering','open','diagnostic_mcq','survey_mcq') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `questions` MODIFY COLUMN `type` ENUM('mcq','true_false','matching_pairs','ordering','open','diagnostic_mcq') NOT NULL");
    }
};
