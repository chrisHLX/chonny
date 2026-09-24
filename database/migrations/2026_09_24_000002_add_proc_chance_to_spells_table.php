<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fourth token the dump answers and this schema did not: $h, the proc chance, 139 occurrences
 * in descriptions across the 12.1.0.69933 dumps ("Your Overpower has a $h% chance to reset the
 * cooldown of Mortal Strike").
 *
 * Split from 2026_09_24_000001 because that one had already run here; there is nothing else
 * separating them.
 *
 * CHECKED BEFORE TRUSTING IT, because the column's overall distribution looks wrong at a glance:
 * 2,295 spells carry "Proc Chance : 101%" and 1,515 carry 100%, which would read as nonsense in
 * prose. None of those spells use $h. Every spell that DOES use $h carries a real figure —
 * Battlelord 40%, Amplifying Poison 30%, Burst of Power 15% — and Battlelord's own tooltip in
 * game reads "40% chance". So the odd values are on records whose description never asks for the
 * number, and the ones that ask are sound. Stored as the dump gives it; no clamping, which would
 * quietly rewrite the source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->decimal('proc_chance', 6, 2)->nullable()->after('max_stacks');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('proc_chance');
        });
    }
};
