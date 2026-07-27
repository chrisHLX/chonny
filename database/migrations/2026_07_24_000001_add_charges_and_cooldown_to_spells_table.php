<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            // Both nullable and scalar: SimC's dump has at most one Charges-or-Cooldown line per
            // spell record (never both, never more than one of either), so this is a 1:1
            // attribute of the spell, not a table of its own. Null means "not present in the
            // dump" (e.g. passive/non-castable spells), not "zero".
            $table->unsignedInteger('charges')->nullable()->after('description');
            $table->decimal('cooldown_seconds', 8, 2)->nullable()->after('charges');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn(['charges', 'cooldown_seconds']);
        });
    }
};
