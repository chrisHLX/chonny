<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // spec_id is nullable ('all specs of this class' — baseline, class-wide talents, hero
        // trees with no resolvable spec) and is part of the unique key below. MySQL treats NULLs
        // as distinct in a unique index, so the DB constraint alone would NOT dedupe repeated
        // null-spec_id rows on re-import — actual idempotency comes from ImportSpellData's
        // upsertTrack()/firstOrNew() doing an application-level `WHERE spec_id IS NULL` lookup
        // before insert (same pattern already relied on elsewhere in this schema). The DB index
        // is still worth having as a safety net for the non-null (pvp_talent, spec-tagged talent)
        // rows, where it enforces correctly.
        Schema::create('spell_class_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spell_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spec_id')->nullable()->constrained('specializations')->cascadeOnDelete();
            $table->enum('source', ['baseline', 'talent', 'pvp_talent']);
            $table->timestamps();

            $table->unique(['spell_id', 'class_id', 'spec_id', 'source'], 'spell_class_availability_unique');
            $table->index('class_id');
            $table->index(['class_id', 'spec_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spell_class_availability');
    }
};
