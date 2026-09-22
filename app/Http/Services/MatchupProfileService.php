<?php

namespace App\Http\Services;

use App\Models\GameClass;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentBuild;
use App\Support\CooldownTabs;
use Illuminate\Support\Facades\File;

/**
 * The per-spec input to the Matchup Lab: a small, flat, committed artifact holding only the
 * terms `CooldownGraphService` needs to put two comps on one clock — offensive cooldowns, the
 * answers a player holds, the control they can apply, and their mobility. One file per spec at
 * data/matchup-profiles/{class}/{spec}.json, written by `wow:build-matchup-profiles`.
 *
 * WHY AN ARTIFACT AND NOT A LIVE READ. A matchup needs SIX specs at once. The existing per-spec
 * kit files (data/spell-kits/) average ~750KB, so reading six of them is ~4.5MB of JSON parse
 * plus a bulk rehydrate of every referenced Spell row, on a box whose single vCPU is already the
 * throughput ceiling (see CLAUDE.md). These profiles are ~5-10KB each and need no database at
 * all at render time. Same "bake what a page needs into a committed artifact" discipline
 * CLAUDE.md rule 14 requires of anything derived from the arena-log archive.
 *
 * WHY IT CARRIES EXTERNAL SPELL IDS. `spells.id` is internal, patch-scoped, and reassigned on
 * every rebuild — which is exactly why the committed spell-kit files are regenerated per
 * environment on deploy rather than trusted from git. A profile keyed on external `spell_id`
 * (Blizzard's) plus the resolved numbers is environment-independent, so the committed file is
 * the real artifact rather than a placeholder. See CLAUDE.md rule 2; this distinction has caused
 * at least three silent bugs that resolved cleanly while being wrong.
 *
 * NOTHING HERE IS RE-DERIVED. Every classification decision is delegated:
 *   - offensive / defensive membership -> App\Support\CooldownTabs, the single definition of
 *     "this belongs on the Offensive/Defensive Cooldowns list", which itself reads WowComps'
 *     reviewed floor and exception constants and the arena-log-verified classification.
 *   - talent-adjusted cooldowns and charges -> SpecKitComputer/ModuleSpellReferenceService,
 *     resolved against the spec's admin-default TalentBuild.
 *   - dr_category -> the kit entry's build-resolved value (SpellProfileBuilder::resolveDrCategory),
 *     never the raw column, because a conditional category is talent-gated.
 * A duplicated predicate here would drift, and the symptom would be the Matchup Lab disagreeing
 * with WoW Comps about what a cooldown is.
 *
 * WHICH DURATION COLUMN, AND WHY IT DIFFERS BY LIST. `spells.duration_seconds` is documented as
 * unreliable *for crowd control* — CcChainBuilder's docblock says so, and the reason is that the
 * column carries the PvE value (Blind reads 60s there, against a real 5s in arena). So control
 * entries take `pvp_duration_seconds` ONLY, the hand-curated column, and carry null when it is
 * absent rather than falling back to a number known to be wrong by an order of magnitude. Buff
 * and mitigation windows do not have that PvE/PvP split, so offensive and answer entries read
 * `duration_seconds` and are null where it is absent.
 */
class MatchupProfileService
{
    /**
     * Bumped when the SHAPE of a profile file changes (a new key, a changed filter). Stamped into
     * every file and checked on read, so a page never renders half-old, half-new profiles —
     * the failure mode CLAUDE.md rule 20 describes, where a cached payload's shape changes and
     * stale entries throw `Undefined array key`.
     *
     * Deliberately a LOCAL signature rather than a bumpSpellCacheVersion() call: that counter
     * keys all 40 precomputed kits, and bumping it to publish an artifact change drops WoW Comps
     * and Spell Explorer onto the slow path for nothing (rule 17).
     */
    public const PROFILE_SHAPE_VERSION = 1;

    /**
     * The PvP trinket. Every class and spec has it, it is the single most-tracked answer in
     * arena, and it is a manual spell row (data/spelldata/manual-spells.txt) because SimC's class
     * dumps only carry class spells. It is classified Defensive by a HAND entry in
     * data/arena-logs/spell-classification/defensive-cooldowns.json — see CLAUDE.md rule 11: when
     * that file is re-promoted from script output, the hand-written entries must be kept or the
     * trinket silently drops out of every defensive list on the site, including this one.
     *
     * Appended explicitly below rather than relied on to arrive through the kit, because whether
     * it does depends on it being picked up as an explicit-spec baseline ability, and an answer
     * pool missing the trinket is not a slightly-wrong model — it is the wrong model. Part 2 of
     * arena-structure.md is largely about this one button.
     */
    public const PVP_TRINKET_SPELL_ID = 336126;

    /**
     * Hard control denies globals; soft control does not, on its own. Part 5 of
     * arena-structure.md scores a go by how many enemy players are left with a free global, and
     * a slowed player still has every one of theirs.
     *
     * Disarm sits in the soft list despite being a real answer — it removes a melee's damage
     * without removing their globals, which is precisely why Part 19.2's cascade ranks it as a
     * cheap answer to a threat rather than as a term in a go.
     */
    public const HARD_CONTROL_CATEGORIES = ['Stun', 'Incapacitate', 'Disorient', 'Silence'];

    /**
     * Reads a committed profile. Returns null when the file is missing or its shape version does
     * not match — callers surface that as an honest "this spec has no profile yet" rather than
     * rendering a partial matchup, because a comp missing one member's answers reads as a comp
     * with a shorter answer pool, which is a wrong answer rather than a missing one.
     */
    public function read(string $classSlug, string $specSlug): ?array
    {
        $path = $this->pathFor($classSlug, $specSlug);

        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);

        if (! is_array($decoded) || ($decoded['shapeVersion'] ?? null) !== self::PROFILE_SHAPE_VERSION) {
            return null;
        }

        return $decoded;
    }

    public function pathFor(string $classSlug, string $specSlug): string
    {
        return base_path("data/matchup-profiles/{$classSlug}/{$specSlug}.json");
    }

    /**
     * Builds one spec's profile from its real kit.
     *
     * $entries is SpecKitComputer's own output (SpellProfile objects) — passed in rather than
     * computed here so the command can decide between the precomputed file and a live compute,
     * and so this method stays a pure shaping step with nothing expensive in it.
     *
     * @param  array<int, mixed>  $entries
     */
    public function build(Specialization $spec, GameClass $class, array $entries, ?TalentBuild $build): array
    {
        $offensive = [];
        $answers = [];
        $control = [];
        $mobility = [];
        $interrupts = [];

        foreach ($entries as $entry) {
            $spell = $entry['spell'];

            // A spec's real pressable buttons only. `isSelected` is what the admin-default build
            // actually took (a kit deliberately renders every talent, chosen or not — see
            // SpecKitComputer's docblock); passives and not_in_spellbook rows are internal copies
            // and set-bonus records that no player has a keybind for. CLAUDE.md rule 3: one
            // visible ability is often several internal spell_id copies, and the pressable one is
            // the one a plan can reference.
            if (! ($entry['isSelected'] ?? false) || $spell->is_passive || $spell->not_in_spellbook) {
                continue;
            }

            $row = [
                'spellId' => (int) $spell->spell_id,
                'name' => $spell->display_name,
                'icon' => $spell->icon_name,
                'cooldown' => $this->seconds($entry['cooldown']['seconds'] ?? null),
                'charges' => $entry['charges']['charges'] ?? null,
            ];

            if (CooldownTabs::isEntry($entry, 'offensive')) {
                $offensive[] = $row + ['duration' => $this->seconds($spell->duration_seconds)];
            }

            if (CooldownTabs::isEntry($entry, 'defensive')) {
                $answers[] = $row + [
                    'duration' => $this->seconds($spell->duration_seconds),
                    'kind' => $this->answerKind($spell),
                ];
            }

            $drCategory = $entry['drCategory'] ?? $spell->dr_category;

            if ($drCategory) {
                $control[] = $row + [
                    'drCategory' => $drCategory,
                    'hard' => in_array($drCategory, self::HARD_CONTROL_CATEGORIES, true),
                    // pvp_duration_seconds ONLY — see the class docblock.
                    'duration' => $this->seconds($spell->pvp_duration_seconds),
                    'isPeel' => (bool) $spell->is_peel,
                    'chainTarget' => $spell->chain_target,
                ];
            }

            if ($spell->is_mobility) {
                $mobility[] = $row;
            }

            if ($spell->is_interrupt) {
                $interrupts[] = $row;
            }
        }

        $answers = $this->withPvpTrinket($answers);

        usort($offensive, fn ($a, $b) => ($b['cooldown'] ?? 0) <=> ($a['cooldown'] ?? 0));
        usort($answers, fn ($a, $b) => ($b['cooldown'] ?? 0) <=> ($a['cooldown'] ?? 0));
        usort($control, fn ($a, $b) => [$b['hard'], $a['cooldown'] ?? 9999] <=> [$a['hard'], $b['cooldown'] ?? 9999]);

        return [
            'shapeVersion' => self::PROFILE_SHAPE_VERSION,
            'generatedAt' => now()->toAtomString(),
            'class' => $class->slug,
            'className' => $class->name,
            'spec' => $spec->slug,
            'specName' => $spec->name,
            'buildName' => $build?->name,
            'offensive' => array_values($offensive),
            'answers' => array_values($answers),
            'control' => array_values($control),
            'mobility' => array_values($mobility),
            'interrupts' => array_values($interrupts),
        ];
    }

    /**
     * The kind of answer this is, for Part 19.2's cascade.
     *
     * Only the two distinctions the DATA can actually make are recorded. `immunity` comes from
     * the curated grants_cc_immunity / grants_school_immunity columns; `trinket` is the one
     * known id. Everything else is `cooldown`.
     *
     * WHAT IS DELIBERATELY NOT HERE: the personal-versus-external split. Nothing in the schema
     * says who a spell may be cast on — the same gap that let Banish and Shackle Horror ship in
     * published guides as control on players (see knowledge-gaps.md, 2026-09-18, and
     * arena-structure.md Part 19.6). A guessed split would put a healer's externals on a DPS's
     * own pool, which is exactly the error Part 2 warns about: "their healer holding three
     * externals does not help a target the healer cannot reach." Flag it, do not guess it.
     */
    private function answerKind(Spell $spell): string
    {
        if ((int) $spell->spell_id === self::PVP_TRINKET_SPELL_ID) {
            return 'trinket';
        }

        if ($spell->grants_cc_immunity || $spell->grants_school_immunity) {
            return 'immunity';
        }

        return 'cooldown';
    }

    /**
     * @param  array<int, array>  $answers
     * @return array<int, array>
     */
    private function withPvpTrinket(array $answers): array
    {
        foreach ($answers as $answer) {
            if ($answer['spellId'] === self::PVP_TRINKET_SPELL_ID) {
                return $answers;
            }
        }

        $trinket = Spell::where('patch_id', Patch::where('is_current', true)->value('id'))
            ->where('spell_id', self::PVP_TRINKET_SPELL_ID)
            ->first();

        if (! $trinket) {
            return $answers;
        }

        $answers[] = [
            'spellId' => self::PVP_TRINKET_SPELL_ID,
            'name' => $trinket->display_name,
            'icon' => $trinket->icon_name,
            'cooldown' => $this->seconds($trinket->cooldown_seconds),
            'charges' => null,
            'duration' => null,
            'kind' => 'trinket',
        ];

        return $answers;
    }

    private function seconds(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }
}
