<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training (2026-10-23): reasoning lessons with notes that come back for a revisit. Ported from the
 * steersman prototype. Vocabularies (note kind, revisit outcome, claim outcome) are plain strings
 * governed by PHP enums or constants, never DB enums (the migration trap in CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A highlight or note on a passage of a lesson, anchored by the quoted text plus a little
        // context either side, so it survives edits that move the passage (W3C text-quote selector).
        Schema::create('training_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('lesson_slug')->index();
            $table->string('block_id');
            $table->text('quote');
            $table->string('prefix', 64)->default('');
            $table->string('suffix', 64)->default('');
            $table->unsignedInteger('start_offset')->default(0);
            $table->string('kind', 20);
            $table->text('body')->nullable();
            $table->string('status', 20)->default('open');
            $table->date('revisit_on')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'lesson_slug']);
        });

        Schema::create('training_note_revisits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_note_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 20);
            $table->text('reflection')->nullable();
            $table->timestamps();
        });

        // Choice answers keep every attempt (the first decides mastery); written answers keep the latest.
        Schema::create('training_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('lesson_slug');
            $table->string('exercise', 20);
            $table->unsignedTinyInteger('choice')->nullable();
            $table->text('body')->nullable();
            $table->boolean('correct')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'lesson_slug', 'exercise']);
        });

        Schema::create('training_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('claim');
            $table->string('source')->nullable();
            $table->unsignedTinyInteger('confidence_before');
            $table->text('known')->nullable();
            $table->text('alternatives')->nullable();
            $table->text('would_change')->nullable();
            $table->text('if_acted')->nullable();
            $table->unsignedTinyInteger('confidence_after')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->text('outcome_note')->nullable();
            $table->date('revisit_on')->nullable();
            $table->timestamps();
        });

        // The reasoning check: form A before the lessons, form B on different problems later.
        Schema::create('training_check_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('form', 1);
            $table->string('item');
            $table->unsignedTinyInteger('choice');
            $table->boolean('correct');
            $table->timestamps();
            $table->unique(['user_id', 'form', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_check_answers');
        Schema::dropIfExists('training_claims');
        Schema::dropIfExists('training_responses');
        Schema::dropIfExists('training_note_revisits');
        Schema::dropIfExists('training_notes');
    }
};
