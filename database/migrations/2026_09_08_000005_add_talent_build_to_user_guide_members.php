<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a guide say WHICH TALENTS ITS COMP IS PLAYING, rather than silently describing whatever the
 * admin-curated default build for each spec happens to be today.
 *
 * WHY PER MEMBER RATHER THAN PER AUTHOR. talent_builds already supports a personal build keyed
 * (user_id, spec_id) — uniquely constrained, so one per user per spec — and reusing that would
 * have been free. It is the wrong shape here: a guide is a document about one specific setup, and
 * two guides for the same spec are routinely about different builds (a Kyrian-flavoured opener and
 * a Rider-flavoured one, a 2v2 build and a 3v3 build). Sharing one personal build across them
 * would mean editing talents in one guide silently rewrote every other guide the author had
 * written for that spec — the "two sources of truth" trap this codebase keeps paying for.
 *
 * The build a member points at is created with user_id NULL and is_default FALSE, exactly like the
 * module-linked builds that already exist (see TalentSelectionService::getOrCreateModuleBuild).
 * That combination is invisible to resolveActiveBuild()'s personal and admin-default lookups, so a
 * guide's own build can never leak onto WoW Comps, Spell Explorer, or another author's guide.
 *
 * NULLABLE, and null is the normal state. A member with no build resolves exactly as it did
 * before — through the spec's admin default — so every existing guide keeps working untouched and
 * authors only opt in when they care.
 *
 * nullOnDelete rather than cascade: losing the build must never delete the comp slot, let alone
 * the steps hanging off it. Same reasoning as removeMember() keeping its authored steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_guide_members', function (Blueprint $table) {
            $table->foreignId('talent_build_id')->nullable()->after('spec_id')
                ->constrained('talent_builds')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_guide_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('talent_build_id');
        });
    }
};
