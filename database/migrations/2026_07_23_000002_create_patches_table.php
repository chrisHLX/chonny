<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('build_version');
            $table->timestamp('released_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['game_id', 'build_version']);
            $table->index('game_id');
        });

        // Functional unique index: evaluates to game_id when is_current is true, else NULL.
        // MySQL excludes NULLs from uniqueness, so this enforces "at most one current patch
        // per game" at the DB level while still allowing any number of non-current patches.
        // MySQL-only syntax (functional key parts, MySQL 8.0.13+) — this app's test suite runs
        // on sqlite (phpunit.xml), which doesn't support this construct, so the index is skipped
        // there. Patch::markCurrent() (app/Models/Patch.php) is the enforcement that runs
        // regardless of driver; this index is a MySQL-only extra safety net on top of it.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE patches ADD UNIQUE INDEX patches_one_current_per_game ((CASE WHEN is_current THEN game_id END))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('patches');
    }
};
