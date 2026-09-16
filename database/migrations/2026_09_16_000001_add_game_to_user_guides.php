<?php

use App\Models\Game;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which game a guide is about.
 *
 * WHY IT WAS MISSING, AND WHY THAT IS A BUG RATHER THAN A GAP. `games` is already a real table and
 * `classes` and `patches` are already scoped to it, so a guide's game was technically derivable:
 * guide -> user_guide_members -> specializations -> classes -> game_id. Three joins for a fact that
 * never changes, and — the part that actually breaks — only derivable AT ALL when the guide has a
 * roster. A guide with no members has no game, and that is not a hypothetical shape: a draft has
 * none until its author picks one, and a prose-only strategy document (the input the Training
 * synthesis work is designed around) never gets one.
 *
 * So the moment a second game exists, "list the guides for this game" either cannot answer for
 * those guides or has to guess. Adding the column now, while one game exists and the answer is
 * unambiguous, costs one backfill; adding it later costs a backfill plus deciding what to do with
 * every roster-less guide written in the meantime.
 *
 * NULLABLE, and nulled rather than cascaded on delete, for the same reason patch_id is: deleting a
 * reference row must never destroy somebody's writing. Null means "not yet known", which is the
 * honest state for a guide whose author has not picked anything yet.
 *
 * NOT a replacement for the roster. The roster still says which specs; this says which game, which
 * is a different question and the only one that can be asked before a roster exists. UserGuide
 * keeps them in step — see resolveGame().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guides', function (Blueprint $table) {
            $table->foreignId('game_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            // The listing query this exists for: this game's guides, newest first.
            $table->index(['game_id', 'status']);
        });

        // Backfill. Every existing guide is a WoW guide — it is the only seeded game, and every
        // spec, spell and talent the builder can reach belongs to it.
        $wow = Game::where('slug', 'wow')->first();

        if ($wow) {
            DB::table('user_guides')->whereNull('game_id')->update(['game_id' => $wow->id]);
        }
    }

    public function down(): void
    {
        // Order matters on MySQL: the foreign key depends on the index that leads with its column,
        // so dropping the index first fails with "needed in a foreign key constraint". Constraint,
        // then index, then column.
        Schema::table('user_guides', function (Blueprint $table) {
            $table->dropForeign(['game_id']);
            $table->dropIndex(['game_id', 'status']);
            $table->dropColumn('game_id');
        });
    }
};
