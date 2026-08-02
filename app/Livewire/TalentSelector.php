<?php

namespace App\Livewire;

use App\Http\Services\BlizzardTalentStringCodec;
use App\Http\Services\TalentSelectionService;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\TalentBuild;
use App\Models\TalentNode;
use App\Models\TalentNodeEntry;
use App\Models\TalentTree;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Player-facing talent picker — the interactive counterpart to
 * ModuleSpellReferenceService::effectiveCooldown()/modifiersFor()'s selection gate. Renders the
 * class/spec/hero PvE trees plus the PvP talent slots for one specialization and lets the viewer
 * click through picks, dispatching 'talents-changed' after every change so a parent component
 * (Modules\Show) can recompute the Spells section.
 *
 * Persistence: authenticated users auto-save on every click (TalentSelectionService, one saved
 * build per user+spec — see the "Talent-aware spell data" plan). Guests get component-state-only
 * selection, reset on reload, never written to talent_builds. isDefaultEditor=true (admin
 * authoring the spec's meta/default build) reuses the exact same picker against the
 * (user_id=null, is_default=true) build instead of the visitor's own.
 *
 * Deliberately not built: talent-tree spend-order/point-budget validation — any entry in any
 * node is selectable regardless of prerequisites (see the plan's explicit deferrals).
 */
class TalentSelector extends Component
{
    public int $specId;

    public bool $isDefaultEditor = false;

    /**
     * A module's own declared hero tree (ModuleGameBuild::hero_talent_tree_id), when this
     * selector is mounted from a specific module's page — see Modules\Show::show.blade.php.
     * Added 2026-08-01: a canonical module's prose only ever covers ONE hero tree (e.g. the
     * Discipline Priest Oracle module explicitly says "Void Weaver isn't detailed here") — making
     * the viewer manually pick it from the dropdown before the page's own content/Spells section
     * became accurate was pure friction with no real choice behind it (there is no other correct
     * answer for this module), and the picker interaction that friction required was also the
     * repro path for a client-side Alpine/Livewire DOM-morph bug (the "Build/Path Identity" tab
     * content visually disappearing on a hero-tree change — confirmed server-side HTML is correct
     * before AND after the change, so the bug is in the browser's morph/paint, not a data or
     * Blade issue). Removing the interaction removes the trigger. View-only default — mount()
     * only assigns $heroTreeId in-memory below, it is never written to talent_builds, so it can
     * never silently overwrite a viewer's own saved cross-module hero-tree preference (e.g. a
     * Voidweaver main browsing this Oracle-specific guide keeps their own saved pick if they have
     * one; this default only fills in when they have none).
     */
    public ?int $moduleHeroTreeId = null;

    public ?int $heroTreeId = null;

    /** @var array<int, int> talent_node_id => chosen talent_node_entry id */
    public array $chosenEntries = [];

    /** @var array<int, int> ordered pvp_talent ids currently selected */
    public array $chosenPvpTalentIds = [];

    /** Paste target for a Blizzard "Export" talent loadout string (PvE tree only — see BlizzardTalentStringCodec). */
    public string $importString = '';

    /** @var ?array<int, array{nodeId: int, entryId: int, spellName: string, treeType: string}> Decoded but not-yet-applied import — nothing is written to talent_builds until applyImport(). */
    public ?array $importPreview = null;

    /** @var array<int, string> */
    public array $importWarnings = [];

    public ?string $importError = null;

    private const MAX_PVP_TALENTS = 3;

    public function mount(int $specId, bool $isDefaultEditor = false, ?int $moduleHeroTreeId = null): void
    {
        $this->specId = $specId;
        $this->isDefaultEditor = $isDefaultEditor;
        $this->moduleHeroTreeId = $moduleHeroTreeId;

        $service = app(TalentSelectionService::class);
        $user = $isDefaultEditor ? null : auth()->user();
        $build = $service->resolveActiveBuild($user, $specId);

        if (!$build->exists) {
            $this->heroTreeId = $moduleHeroTreeId;

            return;
        }

        $this->chosenEntries = $build->choices()->pluck('chosen_entry_id', 'talent_node_id')->all();
        $this->chosenPvpTalentIds = $build->pvpChoices()->orderBy('slot')->pluck('pvp_talent_id')->values()->all();

        if ($this->chosenEntries !== []) {
            $heroEntry = TalentNodeEntry::whereIn('id', array_values($this->chosenEntries))
                ->whereHas('talentNode.talentTree', fn ($q) => $q->where('type', 'hero'))
                ->with('talentNode')
                ->first();

            $this->heroTreeId = $heroEntry?->talentNode->talent_tree_id;
        }

        // No hero-tree pick in the resolved build (a brand-new saved build, or the unsaved-shell
        // fallback that already set nothing above) — fall back to the module's own declared tree
        // rather than leaving the picker unset. See the $moduleHeroTreeId docblock above.
        $this->heroTreeId ??= $moduleHeroTreeId;
    }

    public function updatedHeroTreeId($value): void
    {
        $staleNodeIds = TalentNode::whereHas(
            'talentTree',
            fn ($q) => $q->where('type', 'hero')->when($value, fn ($q2) => $q2->where('id', '!=', $value))
        )->pluck('id');

        $removed = false;
        foreach ($staleNodeIds as $nodeId) {
            if (isset($this->chosenEntries[$nodeId])) {
                unset($this->chosenEntries[$nodeId]);
                $removed = true;
            }
        }

        if ($removed) {
            $this->persistIfAuthenticated(
                fn (TalentSelectionService $service, TalentBuild $build) => $service->pruneNodeChoices($build, $staleNodeIds->all())
            );
        }

        $this->dispatch('talents-changed', selectedSpellIds: $this->selectedSpellIds->all());
    }

    /** Handles both "pick this entry for this node" and "click the already-chosen entry again to clear it". */
    public function toggleEntry(int $nodeId, int $entryId): void
    {
        if (($this->chosenEntries[$nodeId] ?? null) === $entryId) {
            unset($this->chosenEntries[$nodeId]);

            $this->persistIfAuthenticated(
                fn (TalentSelectionService $service, TalentBuild $build) => $build->choices()->where('talent_node_id', $nodeId)->delete()
            );
        } else {
            $this->chosenEntries[$nodeId] = $entryId;

            $this->persistIfAuthenticated(
                fn (TalentSelectionService $service, TalentBuild $build) => $service->saveChoice(
                    $build,
                    TalentNode::findOrFail($nodeId),
                    TalentNodeEntry::findOrFail($entryId)
                )
            );
        }

        $this->dispatch('talents-changed', selectedSpellIds: $this->selectedSpellIds->all());
    }

    public function togglePvpTalent(int $pvpTalentId): void
    {
        if (in_array($pvpTalentId, $this->chosenPvpTalentIds, true)) {
            $this->chosenPvpTalentIds = array_values(array_diff($this->chosenPvpTalentIds, [$pvpTalentId]));
        } elseif (count($this->chosenPvpTalentIds) < self::MAX_PVP_TALENTS) {
            $this->chosenPvpTalentIds[] = $pvpTalentId;
        } else {
            return;
        }

        $this->persistIfAuthenticated(
            fn (TalentSelectionService $service, TalentBuild $build) => $service->syncPvpChoices($build, $this->chosenPvpTalentIds)
        );

        $this->dispatch('talents-changed', selectedSpellIds: $this->selectedSpellIds->all());
    }

    /**
     * Decodes a pasted Blizzard talent string into a human-readable list of resolved spell
     * names — deliberately does NOT touch $chosenEntries or talent_builds yet. Two of the
     * codec's own resolution steps (choice-index ordering, hero-tree node handling) are
     * documented assumptions rather than proven facts — see BlizzardTalentStringCodec's class
     * docblock — so nothing is applied until a human confirms this preview looks right.
     */
    public function previewImport(): void
    {
        $this->importError = null;
        $this->importPreview = null;
        $this->importWarnings = [];

        $spec = $this->specialization;
        $patchId = $this->currentPatchId();
        $importString = trim($this->importString);

        if ($importString === '') {
            $this->importError = 'Paste a talent string first.';

            return;
        }

        if (!$spec || !$patchId) {
            $this->importError = 'No current patch data is imported for this specialization yet.';

            return;
        }

        try {
            $result = app(BlizzardTalentStringCodec::class)->decode($importString, $spec, $patchId);
        } catch (\InvalidArgumentException $e) {
            $this->importError = $e->getMessage();

            return;
        }

        $this->importWarnings = $result['warnings'];
        $this->importPreview = $result['selections']->map(fn (array $row) => [
            'nodeId' => $row['node']->id,
            'entryId' => $row['entry']->id,
            'spellName' => $row['entry']->spell?->name ?? 'Unknown spell',
            'treeType' => $row['node']->talentTree->type,
        ])->values()->all();
    }

    /**
     * Applies a previewed import — a full-loadout replacement for every node the string
     * resolved (overwrites any existing pick for those specific nodes; nodes not mentioned in
     * the string are left untouched). Reuses the exact same per-node persistence path a manual
     * click uses (TalentSelectionService::saveChoice()) — an import is just a bulk source of the
     * same $chosenEntries state, not a separate mechanism.
     */
    public function applyImport(): void
    {
        if (!$this->importPreview) {
            return;
        }

        foreach ($this->importPreview as $row) {
            $this->chosenEntries[$row['nodeId']] = $row['entryId'];
        }

        $heroRow = collect($this->importPreview)->firstWhere('treeType', 'hero');
        if ($heroRow) {
            $heroEntry = TalentNodeEntry::with('talentNode')->find($heroRow['entryId']);
            $this->heroTreeId = $heroEntry?->talentNode->talent_tree_id;
        }

        $this->persistIfAuthenticated(function (TalentSelectionService $service, TalentBuild $build) {
            foreach ($this->importPreview as $row) {
                $service->saveChoice($build, TalentNode::findOrFail($row['nodeId']), TalentNodeEntry::findOrFail($row['entryId']));
            }
        });

        $this->importPreview = null;
        $this->importWarnings = [];
        $this->importString = '';

        $this->dispatch('talents-changed', selectedSpellIds: $this->selectedSpellIds->all());
    }

    public function discardImport(): void
    {
        $this->importPreview = null;
        $this->importWarnings = [];
        $this->importError = null;
    }

    private function persistIfAuthenticated(\Closure $callback): void
    {
        if (!$this->isDefaultEditor && !auth()->check()) {
            return;
        }

        $service = app(TalentSelectionService::class);
        $build = $this->isDefaultEditor
            ? $service->getOrCreateDefaultBuild($this->specId)
            : $service->getOrCreateUserBuild(auth()->user(), $this->specId);

        $callback($service, $build);
    }

    public function getSelectedSpellIdsProperty(): Collection
    {
        $peSpellIds = TalentNodeEntry::whereIn('id', array_values($this->chosenEntries))->pluck('spell_id');
        $pvpSpellIds = PvpTalent::whereIn('id', $this->chosenPvpTalentIds)->pluck('spell_id');

        return $peSpellIds->merge($pvpSpellIds)->unique()->values();
    }

    public function getSpecializationProperty(): ?Specialization
    {
        return Specialization::find($this->specId);
    }

    private function currentPatchId(): ?int
    {
        return $this->specialization?->game()?->currentPatch?->id;
    }

    private function loadTreeNodes(?TalentTree $tree): Collection
    {
        if (!$tree) {
            return collect();
        }

        return $tree->nodes()
            ->with('entries.spell')
            ->orderBy('pos_y')
            ->orderBy('pos_x')
            ->get();
    }

    public function getClassTalentTreeProperty(): ?TalentTree
    {
        $spec = $this->specialization;
        $patchId = $this->currentPatchId();

        if (!$spec || !$patchId) {
            return null;
        }

        return TalentTree::where('class_id', $spec->class_id)
            ->where('patch_id', $patchId)
            ->where('type', 'class')
            ->first();
    }

    public function getClassTalentNodesProperty(): Collection
    {
        return $this->loadTreeNodes($this->classTalentTree);
    }

    public function getSpecTalentTreeProperty(): ?TalentTree
    {
        $patchId = $this->currentPatchId();

        if (!$patchId) {
            return null;
        }

        return TalentTree::where('spec_id', $this->specId)
            ->where('patch_id', $patchId)
            ->where('type', 'spec')
            ->first();
    }

    public function getSpecTalentNodesProperty(): Collection
    {
        return $this->loadTreeNodes($this->specTalentTree);
    }

    public function getHeroTreesProperty(): Collection
    {
        $spec = $this->specialization;
        $patchId = $this->currentPatchId();

        if (!$spec || !$patchId) {
            return collect();
        }

        return TalentTree::where('class_id', $spec->class_id)
            ->where('patch_id', $patchId)
            ->where('type', 'hero')
            ->whereHas('specializations', fn ($q) => $q->where('specializations.id', $this->specId))
            ->orderBy('name')
            ->get();
    }

    public function getSelectedHeroTreeProperty(): ?TalentTree
    {
        return $this->heroTreeId ? TalentTree::find($this->heroTreeId) : null;
    }

    public function getHeroTalentNodesProperty(): Collection
    {
        return $this->loadTreeNodes($this->selectedHeroTree);
    }

    public function getPvpTalentsProperty(): Collection
    {
        $patchId = $this->currentPatchId();

        if (!$patchId) {
            return collect();
        }

        return PvpTalent::where('spec_id', $this->specId)
            ->where('patch_id', $patchId)
            ->with('spell')
            ->orderBy('unlock_level')
            ->get();
    }

    public function render()
    {
        return view('livewire.talent-selector', [
            'specialization' => $this->specialization,
            'classTalentNodes' => $this->classTalentNodes,
            'specTalentNodes' => $this->specTalentNodes,
            'heroTrees' => $this->heroTrees,
            'selectedHeroTree' => $this->selectedHeroTree,
            'heroTalentNodes' => $this->heroTalentNodes,
            'pvpTalents' => $this->pvpTalents,
        ]);
    }
}
