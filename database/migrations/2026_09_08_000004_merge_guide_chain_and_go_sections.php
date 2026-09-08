<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapses the retired 'chain' and 'go' section kinds into the single 'sequence' kind — see
 * App\Enums\UserGuideSectionKind's docblock for why they were the same thing.
 *
 * Safe as a plain UPDATE precisely because `kind` is a string column rather than a DB enum (the
 * create_user_guide_sections_table migration explains that choice); no schema change is needed, so
 * this cannot fail differently on MySQL and the SQLite the test suite runs against.
 *
 * NOTHING IS LOST. The two kinds differed only in which half of the kit their palette offered, and
 * the merged palette is a superset of both — so every existing section keeps every step it had and
 * gains access to abilities it previously could not reach. What a section IS was already carried
 * by its author-written title, which this does not touch.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_guide_sections')
            ->whereIn('kind', ['chain', 'go'])
            ->update(['kind' => 'sequence']);
    }

    /**
     * Irreversible by design, and it says so rather than guessing. Rolling back would have to
     * decide which of the two retired kinds each section had been, and that information is gone —
     * inventing it would silently narrow some author's palette back down. A guide written against
     * the merged kind is also not expressible in the old vocabulary at all once it mixes control
     * and damage in one section, which was the whole point of merging them.
     */
    public function down(): void
    {
        // Deliberately empty.
    }
};
