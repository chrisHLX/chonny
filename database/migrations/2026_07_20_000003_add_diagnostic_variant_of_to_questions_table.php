<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            // Nullable self-reference: a context-tagged diagnostic_mcq scenario variant points
            // back to the generic (root) question it's a class/race-specific rewrite of. This is
            // what makes context routing a *replacement* (variant shown instead of root) rather
            // than an addition (both shown) — see DiagnosticQuizRunner::applyContextRouting().
            $table->foreignId('diagnostic_variant_of')->nullable()->after('id')
                ->constrained('questions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diagnostic_variant_of');
        });
    }
};
