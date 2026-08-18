<?php

namespace App\Http\Services;

use App\Models\GameClass;
use App\Models\Module;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\SpellbookSnapshotEntry;
use App\Models\TalentBuild;
use App\Models\TalentBuildChoice;
use App\Models\TalentBuildPvpChoice;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The single place that resolves "what talents are selected", and reads/writes that selection. A
 * TalentBuild is the full loadout — PvE tree picks (talent_build_choices) plus PvP talent picks
 * (talent_build_pvp_choices) — scoped one of three ways: per (user_id, spec_id) (one saved build
 * per person per spec, reused across every module/page for that spec), as the spec-wide admin
 * default (is_default = true, user_id = null), or — added 2026-08-01 — per module_id (a specific
 * module's own curated talents, e.g. imported from a real Blizzard export string via
 * TalentSelector's import flow). Feeds ModuleSpellReferenceService, which needs to know which
 * talents are actually selected (not just "possible for this spec") to compute an effective
 * cooldown/charge count — and, since 2026-08-06, which *rank* of a multi-rank talent (see
 * selectedRanks()), since some talents' magnitude differs per rank while sharing one spell_id
 * across every rank. Resolution order for a given module (see
 * Modules\Show::initSelectedSpellIds()): that module's own linked build, if any and non-empty →
 * resolveActiveBuild() (user's own build → spec's admin default → empty).
 */
class TalentSelectionService
{
    private const SPELL_CACHE_VERSION_KEY = 'wow_spell_cache_version';

    /**
     * A coarse-grained version counter, not a per-key invalidation list — bumped whenever
     * something that affects SpellExplorer/WowComps's expensive per-spec computation changes:
     * an admin default-build talent pick, or a full spelldata re-import (ImportSpellData).
     * Personal (non-default) builds never bump this — invalidated instead via their own
     * updated_at timestamp baked into the cache key (see WowComps/SpellExplorer's own
     * Cache::remember key, which resolves the viewer's own build first, falling back to the
     * admin default — corrected 2026-08-10, this comment previously described stale pre-
     * "personal talent picker" behavior). Included as part of the Redis cache key rather than
     * driving an explicit per-spec Cache::forget() — a re-import can touch any/all specs at
     * once, so one global counter is simpler and correct without having to enumerate affected
     * specs.
     */
    public function spellCacheVersion(): int
    {
        return (int) Cache::get(self::SPELL_CACHE_VERSION_KEY, 1);
    }

    public function bumpSpellCacheVersion(): void
    {
        Cache::forever(self::SPELL_CACHE_VERSION_KEY, $this->spellCacheVersion() + 1);
    }

    /**
     * Resolution order: the user's own saved build for this spec, else the admin-curated
     * default (is_default = true) for this spec+patch, else an unsaved in-memory TalentBuild
     * with no choices — callers fall back to base/unmodified spell data in that case, same as
     * before this feature existed. Never persists anything itself (view-only resolution).
     */
    public function resolveActiveBuild(?User $user, int $specId, ?int $patchId = null): TalentBuild
    {
        $patchId ??= $this->currentPatchIdForSpec($specId);

        if ($user) {
            $userBuild = TalentBuild::where('user_id', $user->id)
                ->where('spec_id', $specId)
                ->first();

            if ($userBuild) {
                return $userBuild;
            }
        }

        if ($patchId) {
            $default = TalentBuild::where('spec_id', $specId)
                ->where('patch_id', $patchId)
                ->where('is_default', true)
                ->first();

            if ($default) {
                return $default;
            }
        }

        return new TalentBuild([
            'spec_id' => $specId,
            'patch_id' => $patchId,
            'name' => 'Unsaved selection',
        ]);
    }

    /** Flattens both PvE and PvP picks into one set of Spell ids — what gets fed into ModuleSpellReferenceService. */
    public function selectedSpellIds(TalentBuild $build): Collection
    {
        if (!$build->exists) {
            return collect();
        }

        $peSpellIds = $build->choices()->with('chosenEntry')->get()
            ->pluck('chosenEntry.spell_id')
            ->filter();

        $pvpSpellIds = $build->pvpChoices()->with('pvpTalent')->get()
            ->pluck('pvpTalent.spell_id')
            ->filter();

        return $peSpellIds->merge($pvpSpellIds)->unique()->values();
    }

    /**
     * spell_id => rank, for PvE picks only — the counterpart to selectedSpellIds() that keeps
     * the rank information that method deliberately flattens away. Needed because a multi-rank
     * talent's magnitude can differ per rank while sharing one spell_id across every rank (e.g.
     * Improved Fade: rank 1 = -5s, rank 2 = -10s, both spell_id 390670 — see
     * ModuleSpellReferenceService, which is the only consumer, and SpellDataFileParser's
     * rank_scaling capture for how the per-rank numbers themselves get imported). PvP talents
     * have no rank concept (a PvP talent slot is a flat pick, not a multi-rank node) so they're
     * not part of this map at all — a spell_id present here is always a PvE pick.
     *
     * @return Collection<int, int> spell_id => rank
     */
    public function selectedRanks(TalentBuild $build): Collection
    {
        if (!$build->exists) {
            return collect();
        }

        return $build->choices()->with('chosenEntry')->get()
            ->filter(fn (TalentBuildChoice $choice) => $choice->chosenEntry?->spell_id !== null)
            ->mapWithKeys(fn (TalentBuildChoice $choice) => [$choice->chosenEntry->spell_id => $choice->rank]);
    }

    /**
     * For every CHOICE-type talent node with a currently-selected entry, returns the spell_ids
     * of that node's OTHER option(s) — the one(s) NOT taken. Added 2026-08-06 so a page can show
     * "the road not taken" (e.g. Discipline Priest's Ultimate Penitence vs. Power Word: Barrier,
     * Feral Druid's Convoke the Spirits vs. Incarnation: Avatar of Ashamane) alongside the
     * selected pick, greyed out, rather than making it invisible just because it wasn't chosen —
     * a real, common shape: 695 CHOICE nodes exist across the current dataset, 655 of them a
     * clean two-option pair.
     *
     * Deliberately returns a SEPARATE collection from selectedSpellIds() rather than folding
     * siblings into it — a sibling is explicitly NOT selected, and must never be treated as if it
     * were for modifier-gating purposes (ModuleSpellReferenceService::modifiersFor() gates a
     * modifier's real-world effect on $selectedSpellIds; merging an unpicked sibling into that
     * set would make its modifiers look like they're actually applying). Callers that want to
     * *display* both options merge this into their own display-only id list, keeping the true
     * $selectedSpellIds untouched for every cooldown/modifier computation.
     *
     * @return Collection<int, int> spell_id
     */
    public function choiceSiblingSpellIds(Collection $selectedSpellIds): Collection
    {
        if ($selectedSpellIds->isEmpty()) {
            return collect();
        }

        $selectedChoiceNodeIds = TalentNodeEntry::whereIn('spell_id', $selectedSpellIds)
            ->whereHas('talentNode', fn ($q) => $q->where('type', 'CHOICE'))
            ->pluck('talent_node_id')
            ->unique();

        if ($selectedChoiceNodeIds->isEmpty()) {
            return collect();
        }

        return TalentNodeEntry::whereIn('talent_node_id', $selectedChoiceNodeIds)
            ->pluck('spell_id')
            ->unique()
            ->diff($selectedSpellIds)
            ->values();
    }

    /**
     * Every distinct spell_id reachable from any real talent-tree entry for this spec — the
     * spec's class tree + its own spec tree + every hero tree valid for it (via the
     * talent_tree_specializations pivot). Unlike choiceSiblingSpellIds(), this needs no
     * "already selected" input — it's the full static possibility space, not a function of
     * one build. Multi-rank entries (same spell_id at rank 1 and rank 2) collapse to one id;
     * rank only matters for magnitude computation (selectedRanks()), not availability.
     *
     * Mirrors BlizzardTalentStringCodec::orderedNodesForSpec()'s tree-resolution query
     * (class tree + spec's own tree + hero trees via the pivot) rather than re-deriving it —
     * that method's resolution was specifically bug-fixed 2026-08-01 to avoid the "class-tree
     * bloat" and "external_node_id collides across trees" issues; reusing the same shape
     * avoids reintroducing either.
     *
     * Built 2026-08-16 as the foundation for "always show every talent, tagged" — see
     * WowComps/SpellExplorer, which use this (plus allPvpTalentSpellIds()) as the base
     * display set instead of gating visibility on what one curated build happened to select.
     *
     * @return Collection<int, int> spell ids
     */
    public function allTalentSpellIds(int $specId): Collection
    {
        $spec = Specialization::find($specId);
        if (!$spec) {
            return collect();
        }

        $patchId = $this->currentPatchIdForSpec($specId);

        $treeIds = TalentTree::where('patch_id', $patchId)
            ->where(function ($q) use ($spec, $specId) {
                $q->where(fn ($q2) => $q2->where('class_id', $spec->class_id)->where('type', 'class'))
                    ->orWhere(fn ($q2) => $q2->where('spec_id', $specId)->where('type', 'spec'))
                    ->orWhere(fn ($q2) => $q2->where('type', 'hero')
                        ->whereHas('specializations', fn ($q3) => $q3->where('specializations.id', $specId)));
            })
            ->pluck('id');

        $spellIds = TalentNodeEntry::whereHas('talentNode', fn ($q) => $q->whereIn('talent_tree_id', $treeIds))
            ->pluck('spell_id')
            ->unique()
            ->values();

        // Narrows OUT the hand-verified spec-exclusion list — see excludedTalentSpellIds()'s
        // docblock. Deliberately applied here, not at each caller, so every consumer
        // (WowComps/SpellExplorer's computeSpellReferences*()) gets the correction automatically.
        return $spellIds->diff($this->excludedTalentSpellIds($spec));
    }

    /**
     * Hand-verified spell_ids that must NEVER show for this spec despite being structurally
     * reachable via allTalentSpellIds()'s shared class/hero-tree union — reads
     * data/spelldata/talent-spec-exclusions.txt (see that file's own header for the full
     * rationale: the inverse of baseline-spec-overrides.txt, narrowing rather than widening
     * availability). A spec with no exclusion lines simply returns an empty collection — not an
     * error state. Resolved by external spell_id in the file, converted to internal spell.id
     * here so the caller can diff() directly against allTalentSpellIds()'s own internal-id set.
     *
     * @return Collection<int, int> internal spell.id values
     */
    private function excludedTalentSpellIds(Specialization $spec): Collection
    {
        $path = base_path('data/spelldata/talent-spec-exclusions.txt');

        if (!File::exists($path)) {
            return collect();
        }

        $class = GameClass::find($spec->class_id);
        if (!$class) {
            return collect();
        }

        $patchId = $this->currentPatchIdForSpec($spec->id);
        $externalIds = [];

        foreach (File::lines($path) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3 || !ctype_digit($parts[0])) {
                continue;
            }

            [$externalSpellId, $classSlug, $excludedSpecSlug] = $parts;

            if ($classSlug === $class->slug && $excludedSpecSlug === $spec->slug) {
                $externalIds[] = (int) $externalSpellId;
            }
        }

        if ($externalIds === []) {
            return collect();
        }

        return Spell::where('patch_id', $patchId)->whereIn('spell_id', $externalIds)->pluck('id');
    }

    /**
     * Every PvP talent available to this spec — pvp_talents.spec_id is a direct, required FK
     * (see PvpTalent model / TalentSelectionService::syncPvpChoices()'s own docblock: PvP
     * talent "slots" carry no real per-slot restriction in this data, only a spec-level one),
     * so there's no slot-membership ambiguity to resolve, unlike allTalentSpellIds()'s
     * multi-tree resolution.
     *
     * @return Collection<int, int> spell ids
     */
    public function allPvpTalentSpellIds(int $specId): Collection
    {
        $patchId = $this->currentPatchIdForSpec($specId);

        return PvpTalent::where('spec_id', $specId)
            ->where('patch_id', $patchId)
            ->pluck('spell_id')
            ->unique()
            ->values();
    }

    /**
     * ⚠️ DO NOT WIRE THIS METHOD INTO ANY DISPLAY PAGE. ⚠️ Reverted 2026-08-06, the same day it
     * shipped, after it put Mind Sear (a Shadow-only spell) on Discipline Priest's kit — and a
     * dozen other cross-spec leaks alongside it. It is kept here, unused, purely as documented
     * research: the `(desc=...)` disambiguation-suffix filter below is a real, useful finding
     * and may be a building block for a real fix later, but the method as a whole is NOT safe to
     * call from WowComps/SpellExplorer/anywhere else. Read CLAUDE.md's "Baseline ability
     * display" section in full before touching this again — there is currently no reliable way
     * to resolve which spec a `spec_id = NULL` baseline spell actually belongs to, and every
     * attempt to guess it from this data alone produced confidently wrong output for a
     * meaningful fraction of spells (Mind Sear, Voidform, Dark Ascension, and more — see that
     * section for the full list and how they were found).
     *
     * Everything below this line describes what the method DOES, for whoever reads this next —
     * it does not mean the method is safe to use.
     *
     * Spells that are always part of a class/spec's kit regardless of which talents are
     * selected — never gated by a talent pick at all (Leg Sweep, Freezing Trap, Bestial Wrath,
     * ...), as opposed to selectedSpellIds()'s talent-derived set. Added 2026-08-06 after a
     * real report: WowComps (and, unnoticed until now, Spell Explorer — same underlying gap)
     * only ever displays selectedSpellIds()'s talent picks, so any ability that was never a
     * talent to begin with — the actual majority of a class's core kit — was silently absent.
     * `spell_class_availability.source = 'baseline'` is the right table for this, but that
     * bucket alone is far too noisy to use unfiltered: confirmed by hand (2026-08-06) that a
     * naive "all baseline spells with a cooldown" query for e.g. Hunter returns 99 rows, the
     * large majority of which are pet-family basic attacks/special abilities, Legion artifact
     * remnants, Shadowlands covenant abilities, and old rank duplicates — not anything a
     * current player actually has. The reliable signal turned out to already be present in the
     * imported data rather than needing to be invented: SimC's own raw dump appends a
     * "(desc=X)" disambiguation suffix directly onto `Name` whenever a spell needs
     * distinguishing from its real/primary/current counterpart (confirmed in
     * data/spelldata/filtered/hunter/baseline.txt — e.g. "Growl (desc=Basic Ability)",
     * "Windburst (desc=Artifact)", "Weapons of Order (desc=Kyrian)"). A full survey (2026-08-06)
     * of every distinct `(desc=...)` value in the current dataset found it's overwhelmingly
     * pet-ability tiers (Basic/Special/Exotic/Bonus/Command Pet Ability, Ferocity/Tenacity/
     * Cunning Ability), Dragonriding/racial color variants (Bronze/Black/Red/Blue/Green), old
     * expansion systems (Artifact, Kyrian/Venthyr/Necrolord/Night Fae), PvP Talent (already
     * covered via source='pvp_talent' elsewhere), and rank duplicates — never a real current
     * player-facing ability worth showing here, so excluding every `(desc=...)`-suffixed name
     * is a clean, data-grounded filter rather than a guessed keyword list. Combined with the
     * existing not_in_spellbook signal (same "flag, don't guess" precedent as preferVisible())
     * and requiring real cooldown/charge data (excludes passive-only/flavor entries), this
     * turns Hunter's 99 noisy candidates into a shortlist that's actually just Hunter's kit.
     *
     * Not expected to be perfect — see the two follow-up notes below for what's still known to
     * leak through and why, both found the same day this shipped.
     *
     * **Duplicate-name contamination, fixed 2026-08-06.** The `(desc=...)`/not_in_spellbook/
     * cooldown filter above narrows out most junk, but several real classes still have more
     * than one non-junk-looking spell_id row sharing one display name (the same "one ability,
     * several internal spell_id records" shape already documented for Penance/Ultimate
     * Penitence elsewhere in this codebase) — confirmed concretely: Beast Mastery Hunter's
     * "Freezing Trap" had 3 such rows pass the filter, "Harpoon" had 5, "Intimidation" had 2,
     * all rendering as literal duplicate rows on screen. Every OTHER spell-resolution path in
     * this service has one-representative-per-name disambiguation (resolveSpellByName()/
     * preferVisible()); this method didn't. Fixed below by grouping candidates by name and
     * keeping exactly one per group — same tiebreak spirit as resolveSpellByName(): prefer a
     * row whose availability explicitly names this spec (a stronger signal than the loose
     * null-spec fallback that got it into the candidate pool at all), else the lowest spell_id
     * as a deterministic, not-guaranteed-perfect fallback (matches resolveSpellByNameAnyClass()'s
     * own "not expected to be perfect, a wrong pick is a one-line fix" precedent).
     *
     * **Cross-spec leak, NOT fixed, confirmed unrecoverable from current data.** A baseline
     * spell tagged `spec_id = NULL` reads as "available to every spec of this class" by this
     * method — correct for a genuinely class-wide ability (Leg Sweep, all Monk specs) but wrong
     * for a spell that's actually spec-restricted in real play but never got an explicit
     * spec_id tag in the source data (confirmed concretely: Mind Sear — a Shadow Priest
     * signature spell — leaking onto a Discipline Priest's kit here). Checked whether this is
     * the same class of bug as the already-fixed Mind Blast case (spec-restricted via a
     * `free=(Discipline, Shadow)` annotation that ImportSpellData wasn't parsing yet) — it is
     * NOT: grepped Mind Sear's raw entries in data/spelldata/filtered/priest/baseline.txt
     * directly and confirmed there is no `free=(...)` tag or any other spec-qualifying field on
     * it at all, just a bare `Class: Priest` line. There is nothing in the imported data this
     * method (or a smarter query) could key off to exclude it — this is a genuine gap in what
     * the source data captures, not a parsing bug. Flagged rather than silently shipped or
     * silently reverted; see the caller-side discussion of what to do about it (a curated
     * denylist, tightening to explicit-spec-only and losing legitimate class-wide baseline
     * abilities along with it, or accepting occasional false positives) rather than deciding
     * unilaterally here.
     *
     * @return Collection<int, int> spell ids
     */
    public function alwaysAvailableAbilityIds(int $classId, ?int $specId): Collection
    {
        return Spell::whereHas('classAvailability', function ($q) use ($classId, $specId) {
            $q->where('class_id', $classId)
                ->where('source', 'baseline')
                ->where(fn ($q2) => $q2->whereNull('spec_id')->orWhere('spec_id', $specId));
        })
            ->where('is_passive', false)
            ->where('not_in_spellbook', false)
            ->where('name', 'not like', '%(desc=%')
            ->where(fn ($q) => $q->whereNotNull('cooldown_seconds')->orWhereNotNull('charges'))
            ->with('classAvailability')
            ->get()
            ->groupBy('name')
            ->map(function (Collection $group) use ($specId) {
                $explicitSpecMatch = $group->first(
                    fn (Spell $s) => $s->classAvailability->contains('spec_id', $specId)
                );

                return $explicitSpecMatch ?? $group->sortBy('spell_id')->first();
            })
            ->pluck('id')
            ->values();
    }

    /**
     * Manually-verified baseline (never-a-talent) abilities for a spec — Leg Sweep, Freezing
     * Trap, etc. Deliberately separate from the abandoned alwaysAvailableAbilityIds() (see
     * that method's "DO NOT WIRE IN" banner and CLAUDE.md's "Baseline ability display"
     * section) — this one is safe to call because it ONLY ever reads
     * `source = 'verified_override'` rows. spec_id is always explicit on those rows; this
     * method never touches the ambiguous `spec_id = NULL` bucket that caused the Mind Sear
     * leak. Grows only via hand-curated additions to data/spelldata/baseline-spec-overrides.txt
     * (imported by ImportSpellData::importBaselineSpecOverrides()) — never auto-derived, never
     * bulk-applied from a heuristic.
     *
     * @return Collection<int, int> spell ids
     */
    public function verifiedBaselineAbilityIds(int $specId): Collection
    {
        return Spell::whereHas('classAvailability', function ($q) use ($specId) {
            $q->where('spec_id', $specId)->where('source', 'verified_override');
        })->pluck('id');
    }

    /**
     * Baseline (non-talent) abilities that already carry an EXPLICIT spec_id on their
     * spell_class_availability row (source='baseline'), filtered to ones worth surfacing on
     * a kit-comparison page: a real cooldown of 10s+ (arena-relevant, excludes short-CD
     * fillers) or a Sleep/Disorient mechanic tag (an important CC to see regardless of
     * cooldown length). Added 2026-08-07 for baseline-heavy specs (Demon Hunter, Evoker)
     * whose signature kit is mostly never a talent pick.
     *
     * Deliberately does NOT touch the ambiguous spec_id = NULL bucket — every row this reads
     * already has spec attribution resolved correctly by the importer (a `free=(...)` tag or
     * a per-class Class: line matched to exactly one spec), so there is zero cross-spec
     * misattribution risk, unlike alwaysAvailableAbilityIds() (DO NOT WIRE IN — see that
     * method's docblock). The tradeoff, confirmed by hand 2026-08-07 for Demon Hunter: only
     * 16 of Devourer's 635 baseline spells have this explicit tag — headline kit like Eye
     * Beam has no `free=(...)`/Class: signal at all and sits in the NULL bucket, so this
     * method will NOT surface it. That's intentional, not a bug — see CLAUDE.md's "whole
     * specs whose core kit is almost entirely baseline" note for the full tradeoff
     * discussion and why the NULL-bucket alternative was rejected (it reproduces the Mind
     * Sear leak, just within one class instead of across two).
     *
     * Same dedup/junk hygiene as the abandoned alwaysAvailableAbilityIds(): excludes
     * not_in_spellbook and `(desc=...)`-suffixed rows, collapses duplicate-name rows to one
     * (lowest spell_id, deterministic) — safe to reuse here since it's applied after the
     * spec filter, not instead of it.
     *
     * @return Collection<int, int> spell ids
     */
    public function explicitBaselineCooldownAbilityIds(int $classId, int $specId): Collection
    {
        return Spell::whereHas('classAvailability', function ($q) use ($classId, $specId) {
            $q->where('class_id', $classId)
                ->where('spec_id', $specId)
                ->where('source', 'baseline');
        })
            ->where('is_passive', false)
            ->where('not_in_spellbook', false)
            ->where('name', 'not like', '%(desc=%')
            ->where(fn ($q) => $q->where('cooldown_seconds', '>=', 10)
                ->orWhereIn('mechanic', ['Sleep', 'Disorient']))
            ->get()
            ->groupBy('name')
            ->map(fn (Collection $group) => $group->sortBy('spell_id')->first())
            ->pluck('id')
            ->values();
    }

    /**
     * Collapses a fetched Spell collection to one entry per distinct name — the final pass
     * needed on top of alwaysAvailableAbilityIds()'s own internal dedup, added 2026-08-06 after
     * confirming its dedup alone wasn't enough: "Mindbender" and "Bestial Wrath" still rendered
     * twice on real pages, traced to a duplicate-name pair split *across* sources rather than
     * within one — one copy reached via an actual talent_build_choices pick ($selectedSpellIds),
     * a second, separately-named-but-identical-looking spell_id copy reached via
     * alwaysAvailableAbilityIds()'s own baseline query — so the per-source dedup upstream never
     * saw them together to collapse. $selectedSpellIds is preferred within a colliding group
     * when present (it reflects the actual chosen talent, the strongest signal), else lowest
     * spell_id, same deterministic fallback as alwaysAvailableAbilityIds().
     *
     * @param  Collection<int, Spell>  $spells
     * @return Collection<int, Spell>
     */
    public function preferSelectedPerName(Collection $spells, Collection $selectedSpellIds): Collection
    {
        return $spells->groupBy('name')
            ->map(fn (Collection $group) => $group->first(fn (Spell $s) => $selectedSpellIds->contains($s->id))
                ?? $group->sortBy('spell_id')->first())
            ->values();
    }

    /**
     * Real, resolved description text captured directly from the game client for this exact
     * build — the "Phase 2" piece of spellbook-verifier.md, closing the gap
     * ModuleSpellReferenceService::resolveDescription()'s template resolver structurally can't:
     * that resolver can substitute conditionals and evaluate plain arithmetic, but can never
     * produce a real damage/healing number, since those depend on the caster's actual stats
     * (Spell Power, versatility, etc.) which nothing on this site has — Penance's own
     * `spells.description` still reads `$<penancedamage> Holy damage`, an unresolved formula, no
     * matter how good the template engine gets. A `spellbook_snapshot_entries` row for the same
     * build, captured live from a real character's client, already has the resolved number.
     *
     * Only returns anything when $build.spellbook_snapshot_id is set — most builds (an admin's
     * hand-picked default, a personal build assembled by clicking through the picker) have no
     * corresponding real export and get an empty map back, meaning callers should keep using the
     * template resolver for those exactly as before. Never guesses across builds — a build only
     * ever gets descriptions from the one snapshot it was actually decoded from, set explicitly
     * (see TalentBuild.spellbook_snapshot_id), not "the newest snapshot for this spec" or similar.
     *
     * @return Collection<int, string> Blizzard spell_id => resolved_description
     */
    public function resolvedDescriptionsFor(TalentBuild $build): Collection
    {
        if (!$build->exists || !$build->spellbook_snapshot_id) {
            return collect();
        }

        return SpellbookSnapshotEntry::where('snapshot_id', $build->spellbook_snapshot_id)
            ->whereNotNull('resolved_description')
            ->pluck('resolved_description', 'spell_id');
    }

    /**
     * Lazily gets-or-creates a user's saved build for a spec — called the first time they
     * actually pick something, never just from viewing a page (avoids empty rows for every
     * visitor). $patchId defaults to the spec's current patch when not given.
     */
    /**
     * Root-caused 2026-08-10 from a real report: swapping one talent in the player-facing
     * picker (WowComps/SpellExplorer's `<livewire:talent-selector layout="grid">`, mounted
     * without isDefaultEditor — distinct from /admin/talent-builds) on a spec the viewer had
     * never personalized before made almost every other spell vanish from the page. Root cause
     * was NOT lost/dropped clicks — it was this method: the first click on that picker calls
     * this, which (before this fix) created a brand-new, completely EMPTY personal build via
     * plain firstOrCreate() — and resolveActiveBuild() always prefers an existing personal
     * build over the richer admin default the instant one exists, even with just one choice in
     * it. "Swap Cyclone for Soothe" therefore silently became "start a new build containing
     * only Soothe" for that viewer, for every future page load, until fixed. Now seeds a
     * brand-new personal build with a full copy of the spec's current admin-default choices
     * (if one exists) at creation time — so personalizing one talent starts from the meta
     * build's other 90+ picks intact, not from nothing. Existing already-broken personal
     * builds (created before this fix) are NOT retroactively repaired — deleting the affected
     * row lets it be recreated correctly on the next click.
     */
    public function getOrCreateUserBuild(User $user, int $specId, ?int $patchId = null): TalentBuild
    {
        $patchId ??= $this->currentPatchIdForSpec($specId);

        $existing = TalentBuild::where('user_id', $user->id)->where('spec_id', $specId)->first();
        if ($existing) {
            return $existing;
        }

        $build = TalentBuild::create([
            'user_id' => $user->id,
            'spec_id' => $specId,
            'patch_id' => $patchId,
            'name' => 'My Build',
            'share_slug' => (string) Str::uuid(),
        ]);

        $this->seedFromDefaultBuild($build, $specId, $patchId);

        return $build;
    }

    /** Copies the spec's current admin-default choices (PvE + PvP) onto a freshly-created build. No-op when no default exists yet — the new build just stays empty, same as before this fix. */
    private function seedFromDefaultBuild(TalentBuild $build, int $specId, ?int $patchId): void
    {
        $default = TalentBuild::where('spec_id', $specId)
            ->where('patch_id', $patchId)
            ->where('is_default', true)
            ->first();

        if (!$default) {
            return;
        }

        foreach ($default->choices as $choice) {
            TalentBuildChoice::create([
                'talent_build_id' => $build->id,
                'talent_node_id' => $choice->talent_node_id,
                'chosen_entry_id' => $choice->chosen_entry_id,
                'rank' => $choice->rank,
            ]);
        }

        foreach ($default->pvpChoices as $pvpChoice) {
            TalentBuildPvpChoice::create([
                'talent_build_id' => $build->id,
                'slot' => $pvpChoice->slot,
                'pvp_talent_id' => $pvpChoice->pvp_talent_id,
            ]);
        }
    }

    /**
     * Finds other ACTIVE-type nodes in the same tree occupying the identical (pos_x, pos_y) —
     * added 2026-08-10 after a real, now-confirmed-systemic report (Balance Druid's Moonkin
     * Form and Starsurge each showing two "duplicate" entries). Root cause: Blizzard's own
     * talent-tree data sometimes places two genuinely different ACTIVE nodes at the identical
     * grid position without ever formally marking them as a mutually-exclusive CHOICE node —
     * the same shape already found and flagged (not fixed at the time) for Hunter's
     * "Intimidation"/"Spotting Eagle" pair. Quantified before building this fix: 29 such
     * collision instances existed across nearly every class's admin-default builds, confirmed
     * NOT explained by the separate, already-documented "class-tree bloat" bug (checked and
     * ruled out — neither node's external_node_id duplicates into any spec/hero tree; these
     * are two genuinely distinct, correctly-imported nodes). Scoped to 'ACTIVE' only — a real
     * CHOICE node already has its own, correct mutual-exclusion handling via toggleEntry()'s
     * single-entry-per-node design; this only covers the gap Blizzard's data itself doesn't
     * formally express.
     *
     * @return Collection<int, int> other node ids at the same position
     */
    public function samePositionSiblingNodeIds(TalentNode $node): Collection
    {
        if ($node->type !== 'ACTIVE') {
            return collect();
        }

        return TalentNode::where('talent_tree_id', $node->talent_tree_id)
            ->where('pos_x', $node->pos_x)
            ->where('pos_y', $node->pos_y)
            ->where('id', '!=', $node->id)
            ->where('type', 'ACTIVE')
            ->pluck('id');
    }

    public function saveChoice(TalentBuild $build, TalentNode $node, TalentNodeEntry $entry): void
    {
        TalentBuildChoice::updateOrCreate(
            ['talent_build_id' => $build->id, 'talent_node_id' => $node->id],
            ['chosen_entry_id' => $entry->id, 'rank' => $entry->rank]
        );

        // Treats same-position ACTIVE node pairs as an informal mutually-exclusive choice —
        // see samePositionSiblingNodeIds()'s docblock. Picking one clears any sibling(s) at the
        // identical grid position, the same way a real CHOICE node already only ever holds one
        // entry per node. Safe to always run (a node with no same-position siblings is a no-op).
        $siblingIds = $this->samePositionSiblingNodeIds($node);
        if ($siblingIds->isNotEmpty()) {
            $build->choices()->whereIn('talent_node_id', $siblingIds)->delete();
        }

        // Child-row writes above don't touch the parent's own updated_at (Eloquent doesn't
        // cascade that automatically) — WowComps/SpellExplorer key their per-build spell-
        // reference cache off this timestamp for non-default (personal) builds, since those
        // never bump the global spellCacheVersion below. See TalentSelectionService's own
        // class docblock and CLAUDE.md's "Personal talent picker" section.
        $build->touch();

        if ($build->is_default) {
            $this->bumpSpellCacheVersion();
        }
    }

    /**
     * Removes same-position ACTIVE-node collisions (see samePositionSiblingNodeIds()'s
     * docblock) from EVERY existing TalentBuild — the retroactive counterpart to the guard
     * saveChoice() now applies going forward. Added 2026-08-10, shared by the standalone
     * `wow:fix-talent-collisions` command and — per the actual goal behind building this share
     * point — ImportSpellData's `handle()`, which now calls this unconditionally at the end of
     * every import (same "always run this defensive pass" precedent as
     * importBaselineSpecOverrides()). This means a plain `php artisan import:spelldata` on any
     * environment, including one still carrying pre-fix collision data, is enough on its own —
     * no separate command to remember. Deterministic, non-guessing tiebreaker: for each
     * collision group, keeps whichever choice was written most recently (highest updated_at,
     * tie-break highest id) and deletes the rest — never claims to know which talent is
     * game-balance-"correct". Safe to call repeatedly; a clean build is a no-op.
     *
     * @return array<int, array{build_id: int, is_default: bool, dropped_spell: string, dropped_node_id: int, kept_spell: string, kept_node_id: int}>
     */
    public function cleanupSamePositionCollisions(): array
    {
        $report = [];

        foreach (TalentBuild::all() as $build) {
            $choices = $build->choices()->with(['talentNode', 'chosenEntry.spell'])->get();

            $byPosition = $choices
                ->filter(fn (TalentBuildChoice $c) => $c->talentNode->type === 'ACTIVE')
                ->groupBy(fn (TalentBuildChoice $c) => $c->talentNode->talent_tree_id.':'.$c->talentNode->pos_x.':'.$c->talentNode->pos_y);

            foreach ($byPosition as $group) {
                if ($group->count() <= 1) {
                    continue;
                }

                $sorted = $group->sortByDesc(fn (TalentBuildChoice $c) => [$c->updated_at, $c->id])->values();
                $keep = $sorted->first();

                foreach ($sorted->slice(1) as $drop) {
                    $report[] = [
                        'build_id' => $build->id,
                        'is_default' => $build->is_default,
                        'dropped_spell' => $drop->chosenEntry->spell->name,
                        'dropped_node_id' => $drop->talent_node_id,
                        'kept_spell' => $keep->chosenEntry->spell->name,
                        'kept_node_id' => $keep->talent_node_id,
                    ];
                    $drop->delete();
                }
            }
        }

        return $report;
    }

    /**
     * Deletes stale class-tree TalentNode rows whose linked spell ALSO has a genuine entry in
     * one of that class's own spec trees — the DB-level recurrence of the "class-tree bloat" bug
     * (see CLAUDE.md's `data/talenttrees/{class}.json class-tree bloat` section — found and
     * supposedly fixed 2026-08-02 in fetch-talent-trees.php's fetch-time filter). A class-wide
     * talent, by Blizzard's own design, can never legitimately also appear in a spec's own tree
     * — so "same spell reachable via both a class tree and a spec tree for the same class" is an
     * unambiguous, safe signature for stale/bloated data, never a judgment call.
     *
     * Confirmed 2026-08-17 via a real user report (Berserk/Solar Beam showing on Restoration
     * Druid, Cauterize on Frost Mage — cross-spec leakage through allTalentSpellIds()'s class-
     * tree branch) that the live DB still carried ~2,650 such stale entries across all 13
     * classes, despite the on-disk data/talenttrees/*.json files (re-fetched 2026-08-12, well
     * after the original fix) being correctly filtered — import:spelldata's upsertTrack()
     * pattern only creates/updates, never deletes, so a stale node created by an older/re-
     * bloated import is never cleaned up just because a later fetch happens to be clean.
     *
     * Deleting a class-tree TalentNode cascades (talent_node_entries.talent_node_id,
     * talent_node_edges.from_node_id/to_node_id, and talent_build_choices.talent_node_id are all
     * cascadeOnDelete()) — a build that had double-picked the same talent via both the stale
     * class node and the legitimate spec node loses only the redundant stale pick, keeping its
     * real one intact (confirmed on 5 real admin-default builds during this fix).
     *
     * Runs unconditionally on every import:spelldata run (same "always run this defensive pass"
     * precedent as cleanupSamePositionCollisions() above), so the DB self-heals on every future
     * re-import regardless of whether that particular run's fetched JSON happens to be clean.
     *
     * @return array<int, array{class: string, node_id: int, spell: string}> what was removed
     */
    public function cleanupClassTreeBloat(): array
    {
        $report = [];

        foreach (GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))->get() as $class) {
            $classTree = TalentTree::where('class_id', $class->id)->where('type', 'class')->first();

            if (!$classTree) {
                continue;
            }

            $specTreeIds = TalentTree::where('class_id', $class->id)->where('type', 'spec')->pluck('id');

            $specSpellIds = TalentNodeEntry::whereHas('talentNode', fn ($q) => $q->whereIn('talent_tree_id', $specTreeIds))
                ->pluck('spell_id')
                ->unique();

            $staleNodes = TalentNode::where('talent_tree_id', $classTree->id)
                ->whereHas('entries', fn ($q) => $q->whereIn('spell_id', $specSpellIds))
                ->with('entries.spell')
                ->get();

            foreach ($staleNodes as $node) {
                $report[] = [
                    'class' => $class->name,
                    'node_id' => $node->id,
                    'spell' => $node->entries->pluck('spell.name')->filter()->unique()->implode(', '),
                ];
                $node->delete();
            }
        }

        return $report;
    }

    /**
     * Merges same-position ACTIVE-node duplicates that share the identical talent NAME within
     * one tree — a broader, name-based generalization that also subsumes the plain "identical
     * spell_id" case (identical spell_id always implies identical name). Found 2026-08-17 via a
     * real user report: "Intimidation" showing both its BM/Survival and Marksmanship copies on
     * Beast Mastery, plus Moonkin Form/Starsurge/Starfire each showing 2-3 times on every Druid
     * spec, despite cleanupClassTreeBloat() and cleanupSamePositionCollisions() (above) already
     * existing. Neither of those two covers this shape: cleanupClassTreeBloat() only strips a
     * class-tree node when the SAME spell_id also has a genuine spec-tree entry (none of these
     * do — Balance's own spec tree has zero entries for any of the three Druid abilities,
     * confirmed directly), and cleanupSamePositionCollisions() only removes redundant SELECTIONS
     * from a build, never the underlying duplicate TalentNode rows themselves — so even a build
     * with no double-pick still displays both copies, since the 2026-08-16 "always show every
     * talent" rework reads every talent_node_entries row regardless of selection.
     *
     * Verified by reading each pair's own raw description text (data/talenttrees/{class}.json)
     * before writing this, not guessed: Moonkin Form's two copies are byte-identical; Starsurge/
     * Starfire's differ only in a tuning number (804 vs 1,386 damage; 458/317 vs 355/252) — the
     * same shape as every other "one real ability, multiple spell_id records" case already
     * documented throughout this codebase (Penance, Angelic Bulwark, Living Flame, etc.), just
     * manifesting as duplicate talent NODES here instead of duplicate hidden spell rows.
     *
     * KNOWN, VERIFIED EXCEPTION: "Intimidation" is excluded — its two spell_ids ARE genuinely
     * different abilities per spec (19577 "Commands your pet to intimidate the target" for
     * BM/Survival vs 474421 "Your Spotting Eagle descends from the skies" for Marksmanship,
     * confirmed via description text 2026-08-14 — see cc-synergies-overrides.txt's own note).
     * Merging these would silently delete a real, distinct ability. Do not remove this exclusion
     * without re-verifying via description text first, same discipline as every other "same
     * name, different spell_id" disambiguation in this codebase. This method does NOT fix why
     * Intimidation's Marksmanship copy still shows on Beast Mastery/Survival after the redundant
     * BM/Survival duplicate is merged away — both genuinely sit in the shared Hunter class tree,
     * and nothing in Blizzard's raw data (no locked_by, no spec field on a class-tree node) says
     * which spec a same-position class-tree node belongs to when the content genuinely differs
     * per spec. That is a separate, harder, unsolved problem — the same category of gap as
     * spell_class_availability's ambiguous spec_id=NULL bucket — flagged in CLAUDE.md, not
     * solved here. A same-shaped duplicate-plus-real-variant trio also exists for Hunter's
     * Muzzle/Counter Shot and Demon Hunter's Chaos Nova/Void Nova and Felblade/Voidblade — this
     * method safely reduces each trio's redundant duplicate pair down to one copy (they don't
     * share a name with the genuine variant, so no exclusion entry is needed for those), leaving
     * the same unsolved per-spec-visibility gap Intimidation has.
     *
     * Survivor tiering mirrors ModuleSpellReferenceService::resolveSpellByName()'s own
     * established disambiguation chain (not a new invented rule): prefer a spell with
     * not_in_spellbook=false, then prefer one with real cooldown/charges data, then prefer
     * whichever node already has the most TalentBuildChoice references (preserves existing
     * real-world curation over an arbitrary pick), tie-break lowest node id. Any existing choice
     * on a "losing" node is re-pointed onto the survivor's own entry — or dropped if the build
     * already has a choice on the survivor (the same redundant-choice case
     * cleanupSamePositionCollisions() already handles) — never silently deleted without an
     * attempt to preserve it first.
     *
     * Scoped to single-entry, single-rank nodes only (no CHOICE-shaped or multi-rank nodes) —
     * every duplicate found so far fits this shape; a node with more than one entry or a
     * mismatched rank/max_ranks against its same-named sibling is left alone rather than guessed
     * at. Runs unconditionally on every import:spelldata run (same "always run this defensive
     * pass" precedent as the other two cleanup methods above), so the DB self-heals regardless
     * of what a future re-fetch's raw JSON happens to contain.
     *
     * @return array<int, array{tree: string, kept: string, kept_node_id: int, dropped: string, dropped_node_id: int}>
     */
    public function cleanupDuplicateSpellNodes(): array
    {
        $report = [];

        // Verified, manually-checked exceptions — see docblock above. Add a name here ONLY
        // after confirming via each copy's own description text that they're genuinely
        // different abilities, not a data duplicate.
        $excludedNames = ['Intimidation'];

        $nodesByTree = TalentNode::where('type', 'ACTIVE')->get()->groupBy('talent_tree_id');

        foreach ($nodesByTree as $treeId => $treeNodes) {
            $tree = TalentTree::find($treeId);
            $byPosition = $treeNodes->groupBy(fn (TalentNode $n) => $n->pos_x.'|'.$n->pos_y);

            foreach ($byPosition as $group) {
                if ($group->count() < 2) {
                    continue;
                }

                $entriesByNode = $group->mapWithKeys(
                    fn (TalentNode $n) => [$n->id => TalentNodeEntry::where('talent_node_id', $n->id)->with('spell')->get()]
                );

                // Only single-entry nodes — see docblock.
                if ($entriesByNode->contains(fn ($entries) => $entries->count() !== 1)) {
                    continue;
                }

                $byName = $group->groupBy(fn (TalentNode $n) => $entriesByNode[$n->id]->first()->spell->name);

                foreach ($byName as $name => $sameNameNodes) {
                    if ($sameNameNodes->count() < 2 || in_array($name, $excludedNames, true)) {
                        continue;
                    }

                    // Require identical rank/max_rank across the group too — extra safety
                    // beyond the name match, in case a future duplicate shape isn't a clean
                    // single-rank node like every case found so far.
                    $rankShapes = $sameNameNodes->map(fn (TalentNode $n) => $entriesByNode[$n->id]->first()->rank.':'.$entriesByNode[$n->id]->first()->max_rank)->unique();
                    if ($rankShapes->count() > 1) {
                        continue;
                    }

                    $survivor = $sameNameNodes->sort(function (TalentNode $a, TalentNode $b) use ($entriesByNode) {
                        $spellA = $entriesByNode[$a->id]->first()->spell;
                        $spellB = $entriesByNode[$b->id]->first()->spell;

                        $visibleA = !$spellA->not_in_spellbook;
                        $visibleB = !$spellB->not_in_spellbook;
                        if ($visibleA !== $visibleB) {
                            return $visibleA ? -1 : 1;
                        }

                        $hasDataA = $spellA->cooldown_seconds !== null || $spellA->charges !== null;
                        $hasDataB = $spellB->cooldown_seconds !== null || $spellB->charges !== null;
                        if ($hasDataA !== $hasDataB) {
                            return $hasDataA ? -1 : 1;
                        }

                        $choicesA = TalentBuildChoice::where('talent_node_id', $a->id)->count();
                        $choicesB = TalentBuildChoice::where('talent_node_id', $b->id)->count();
                        if ($choicesA !== $choicesB) {
                            return $choicesB <=> $choicesA;
                        }

                        return $a->id <=> $b->id;
                    })->first();

                    $survivorEntry = $entriesByNode[$survivor->id]->first();

                    foreach ($sameNameNodes as $node) {
                        if ($node->id === $survivor->id) {
                            continue;
                        }

                        $loserEntry = $entriesByNode[$node->id]->first();

                        foreach (TalentBuildChoice::where('talent_node_id', $node->id)->get() as $choice) {
                            $existing = TalentBuildChoice::where('talent_build_id', $choice->talent_build_id)
                                ->where('talent_node_id', $survivor->id)
                                ->exists();

                            if ($existing) {
                                $choice->delete();

                                continue;
                            }

                            $choice->update([
                                'talent_node_id' => $survivor->id,
                                'chosen_entry_id' => $survivorEntry->id,
                            ]);
                        }

                        $report[] = [
                            'tree' => $tree->name,
                            'kept' => $survivorEntry->spell->name,
                            'kept_node_id' => $survivor->id,
                            'dropped' => $loserEntry->spell->name,
                            'dropped_node_id' => $node->id,
                        ];

                        $node->delete();
                    }
                }
            }
        }

        return $report;
    }

    /**
     * Replaces the build's whole PvP-talent selection in one go. PvP talent "slots" carry no
     * real per-slot restriction in this data (pvp_talents has no slot column at all —
     * Blizzard's compatible_slots just means "any of the player's slots", not a fixed
     * assignment) — a slot number is only bookkeeping to store N simultaneous picks, so a full
     * replace-not-append sync (same precedent as RoadmapService::persistStagesForUser) is
     * simpler and more correct than trying to track which slot an individual talent occupies.
     *
     * @param  array<int, int>  $pvpTalentIds  ordered list, becomes slots 1..count()
     */
    public function syncPvpChoices(TalentBuild $build, array $pvpTalentIds): void
    {
        $build->pvpChoices()->delete();

        foreach (array_values($pvpTalentIds) as $index => $pvpTalentId) {
            TalentBuildPvpChoice::create([
                'talent_build_id' => $build->id,
                'slot' => $index + 1,
                'pvp_talent_id' => $pvpTalentId,
            ]);
        }

        $build->touch();

        if ($build->is_default) {
            $this->bumpSpellCacheVersion();
        }
    }

    /** Clears a single node's pick — the counterpart to saveChoice() for TalentSelector::toggleEntry()'s "click the chosen entry again" case. Kept in the service (rather than a bare `$build->choices()->...->delete()` at the call site) so the touch()/cache-invalidation behavior stays in one place. */
    public function deleteChoice(TalentBuild $build, int $nodeId): void
    {
        $build->choices()->where('talent_node_id', $nodeId)->delete();

        $build->touch();

        if ($build->is_default) {
            $this->bumpSpellCacheVersion();
        }
    }

    /**
     * The build linked to one specific module, if any — takes priority over a viewer's own
     * build and the spec's admin default (see Modules\Show::initSelectedSpellIds()). Returns
     * null both when no such build exists yet and when one exists but has no choices at all (an
     * empty linked build is equivalent to "not linked" for resolution purposes — same "empty
     * shell falls back" behavior resolveActiveBuild() already has for the unsaved-shell case).
     */
    public function resolveBuildForModule(Module $module): ?TalentBuild
    {
        $build = TalentBuild::where('module_id', $module->id)->first();

        if (!$build || ($build->choices()->doesntExist() && $build->pvpChoices()->doesntExist())) {
            return null;
        }

        return $build;
    }

    /** Lazily gets-or-creates the module-linked build — used when curating a module's talents (e.g. importing a Blizzard string into it), never just from a viewer loading the page. */
    public function getOrCreateModuleBuild(Module $module, int $specId, ?int $patchId = null): TalentBuild
    {
        $patchId ??= $this->currentPatchIdForSpec($specId);

        return TalentBuild::firstOrCreate(
            ['module_id' => $module->id],
            ['spec_id' => $specId, 'patch_id' => $patchId, 'name' => $module->name, 'share_slug' => (string) Str::uuid()]
        );
    }

    /** Finds or creates the (user_id = null, is_default = true) build for a spec+patch — the admin-curated "meta" loadout editors write to via TalentBuildEditor/TalentSelector's isDefaultEditor mode. */
    public function getOrCreateDefaultBuild(int $specId, ?int $patchId = null): TalentBuild
    {
        $patchId ??= $this->currentPatchIdForSpec($specId);

        return TalentBuild::firstOrCreate(
            ['spec_id' => $specId, 'patch_id' => $patchId, 'is_default' => true],
            ['name' => 'Default Build', 'share_slug' => (string) Str::uuid()]
        );
    }

    /** Deletes any saved PvE choices for the given node ids — used when switching hero tree, so a choice from the previously-selected hero tree doesn't keep silently counting as "selected" after the UI stops showing it. */
    public function pruneNodeChoices(TalentBuild $build, array $nodeIds): void
    {
        if (!$build->exists || $nodeIds === []) {
            return;
        }

        $build->choices()->whereIn('talent_node_id', $nodeIds)->delete();

        $build->touch();

        if ($build->is_default) {
            $this->bumpSpellCacheVersion();
        }
    }

    /**
     * Marks $build as the default for its (spec_id, patch_id), deactivating any prior default
     * first — service-layer uniqueness (see the talent_builds migration: a DB unique index here
     * would also have to reject multiple *non*-default rows per spec/patch, which isn't the
     * intent), same "replace not append" precedent as RoadmapService::persistStagesForUser().
     */
    public function setDefault(TalentBuild $build): void
    {
        TalentBuild::where('spec_id', $build->spec_id)
            ->where('patch_id', $build->patch_id)
            ->where('id', '!=', $build->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $build->update(['is_default' => true]);

        $this->bumpSpellCacheVersion();
    }

    private function currentPatchIdForSpec(int $specId): ?int
    {
        $gameId = Specialization::find($specId)?->game()?->id;

        if (!$gameId) {
            return null;
        }

        return Patch::where('game_id', $gameId)->where('is_current', true)->value('id');
    }
}
