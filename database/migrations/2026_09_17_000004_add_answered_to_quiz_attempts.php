<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many questions have been answered in an attempt, finished or not. Stored rather than counted
 * from the `answers` JSON so the class quiz leaderboard (most questions answered) is a plain SUM
 * that works the same on MySQL and on the SQLite the tests run on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->unsignedTinyInteger('answered')->default(0)->after('answers');
        });

        DB::table('quiz_attempts')->orderBy('id')->each(function ($row) {
            $answered = count(json_decode($row->answers ?? '[]', true) ?: []);
            DB::table('quiz_attempts')->where('id', $row->id)->update(['answered' => $answered]);
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('answered');
        });
    }
};
