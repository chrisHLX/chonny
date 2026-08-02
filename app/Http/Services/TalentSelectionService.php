<?php

namespace App\Http\Services;

use App\Models\Module;
use App\Models\Patch;
use App\Models\Specialization;
use App\Models\TalentBuild;
use App\Models\TalentBuildChoice;
use App\Models\TalentBuildPvpChoice;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\User;
use Illuminate\Support\Collection;
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
 * cooldown/charge count. Resolution order for a given module (see
 * Modules\Show::initSelectedSpellIds()): that module's own linked build, if any and non-empty →
 * resolveActiveBuild() (user's own build → spec's admin default → empty).
 */
class TalentSelectionService
{
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
     * Lazily gets-or-creates a user's saved build for a spec — called the first time they
     * actually pick something, never just from viewing a page (avoids empty rows for every
     * visitor). $patchId defaults to the spec's current patch when not given.
     */
    public function getOrCreateUserBuild(User $user, int $specId, ?int $patchId = null): TalentBuild
    {
        $patchId ??= $this->currentPatchIdForSpec($specId);

        return TalentBuild::firstOrCreate(
            ['user_id' => $user->id, 'spec_id' => $specId],
            ['patch_id' => $patchId, 'name' => 'My Build', 'share_slug' => (string) Str::uuid()]
        );
    }

    public function saveChoice(TalentBuild $build, TalentNode $node, TalentNodeEntry $entry): void
    {
        TalentBuildChoice::updateOrCreate(
            ['talent_build_id' => $build->id, 'talent_node_id' => $node->id],
            ['chosen_entry_id' => $entry->id, 'rank' => $entry->rank]
        );
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
