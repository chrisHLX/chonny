<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spells.school_immunity_override` — a hand-curated, authoritative "Affected School(s)" list for
 * a spell whose effect-derived school immunity is wrong. Null for almost every spell; only the
 * handful of lines in data/spelldata/school-immunity-overrides.txt ever set it.
 *
 * WHY THIS IS NEEDED, measured 2026-09-09 rather than assumed. Cloak of Shadows was reported as
 * showing up as an immunity to physical abilities when it is magic-only, and the data genuinely
 * says otherwise: its hidden 1-second aura (35729, `Triggered By: Cloak of Shadows (31224)`,
 * attribute `Immunity Purges Effect (47)`) carries TWO School Immunity effects —
 *
 *     #1  Misc 0x7e  Affected School(s): Arcane, Fire, Frost, Holy, Nature, Shadow
 *     #2  Misc 0x1   Affected School(s): Physical
 *
 * — because that 1s aura is how Blizzard implements Cloak's debuff PURGE, not a defensive window.
 * The lasting magic immunity a player actually experiences lives on the pressable copy 31224 as
 * `Modify Attacker Spell Hit Chance: -200`, and 31224's only Physical-flavoured effect is a
 * `Modify Damage Taken%` of base 0 — i.e. nothing. Effect #2 is an implementation artifact.
 *
 * The effect of getting this wrong was real and large: Cloak claimed to counter 38 Physical-school
 * CC abilities (Kidney Shot, Cheap Shot, Blind, Sap, Gouge, Mighty Bash, Shockwave, ...) in
 * spell_counters, all of which a player would rightly call nonsense.
 *
 * WHY A CURATED OVERRIDE AND NOT A HEURISTIC. Every structural signal was checked and none
 * separates effect #1 from effect #2 — same spell, same duration, same target, same aura type;
 * only the school mask differs, and both masks are individually legitimate. A duration rule
 * ("a <=1s school immunity is a purge artifact") WOULD catch effect #2, but it also catches
 * effect #1 on the very same aura, which would delete Cloak's real and important magic immunity
 * against Fear/Polymorph. Structurally Cloak is indistinguishable from Ice Block (Physical + All)
 * and Divine Shield (All + magic), which are genuinely immune to everything. This is a game-
 * knowledge fact, so it is curated one verified line at a time — the same discipline as
 * baseline-spec-overrides.txt, cc-synergies-overrides.txt and cc-immunity-overrides.txt.
 *
 * cc-immunity-overrides.txt already anticipated this exact gap: it rejects Peaceweaver (Mistweaver)
 * with "Needs a school-immunity override, which this file does not yet support." Cloak is the
 * second real case, and this column is that support.
 *
 * Plain nullable string, deliberately NOT an enum — same reason spell_counters.mechanism is one:
 * a MySQL `ALTER TABLE ... MODIFY COLUMN` on an enum is invalid on SQLite, and phpunit.xml runs
 * the whole suite against in-memory SQLite. Values are validated at import instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->string('school_immunity_override')->nullable()->after('grants_school_immunity');
        });
    }

    public function down(): void
    {
        Schema::table('spells', function (Blueprint $table) {
            $table->dropColumn('school_immunity_override');
        });
    }
};
