<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ordered, typed units a user guide is composed from — the block model the codebase did not
 * previously have anywhere. ModulePage, the closest existing authored content, is a single
 * longText markdown blob; there was nothing to reuse.
 *
 * THE ONE RULE THAT GOVERNS EVERY PAYLOAD: store references and free text, never resolved game
 * data. No spell names, no cooldowns, no durations, no prose copied out of a tooltip. This is the
 * same discipline the Spells reference section already follows, and for the same reason — prose
 * goes stale the moment a patch changes a number, with nothing to catch it, whereas a reference
 * resolved live at render time simply shows the new value. A guide that froze "Kidney Shot, 6s"
 * into its payload would keep saying 6s forever after a balance change, and would look perfectly
 * healthy while doing it.
 *
 * A SPELL BLOCK STORES BLIZZARD'S EXTERNAL spell_id, NOT spells.id. This is the sharpest edge in
 * the whole feature and it is worth being blunt about: spells.id is an internal auto-increment
 * key, it is patch-scoped, and it is reassigned when a patch row is created. This codebase has
 * already been bitten by exactly that collision twice — fetch-spell-icons.php queried
 * spells.spell_id with values that were really spells.id and silently matched ~50 rows out of
 * ~3,400, and conditional_dr_gating_spell_id had to be documented as external precisely because
 * the two id spaces are trivially confusable. Both times the failure was silent: wrong data
 * resolved cleanly with no error anywhere. A guide is long-lived content that must survive patch
 * bumps, so the payload key is named `external_spell_id` rather than `spell_id` to make an
 * incorrect write obvious at the call site instead of at render time.
 *
 * `position` carries the order and is deliberately NOT uniquely constrained per guide. Reordering
 * rewrites the whole run of positions for a guide in one go (the cheapest correct way to persist a
 * drag-and-drop result), and a unique index would reject that mid-rewrite on the first transient
 * collision unless every save were shuffled through a temporary offset. An index for ordered reads
 * is all this needs.
 *
 * block_type is a plain string, not a DB enum, for the same SQLite reason as user_guides.type —
 * see that migration's docblock. Values are governed by App\Enums\UserGuideBlockType.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_guide_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_guide_id')->constrained()->cascadeOnDelete();

            // Sort order within the guide. Not unique — see the class docblock.
            $table->unsignedInteger('position')->default(0);

            // App\Enums\UserGuideBlockType — 'spell' | 'phase' | 'heading' | 'note'.
            $table->string('block_type');

            // References and free text only. Spell blocks carry `external_spell_id`, never
            // spells.id, and never a denormalised name, cooldown or duration.
            $table->json('payload');

            $table->timestamps();

            // The only read this table ever really does: a guide's blocks, in order.
            $table->index(['user_guide_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_guide_blocks');
    }
};
