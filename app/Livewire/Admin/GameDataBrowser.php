<?php

namespace App\Livewire\Admin;

use App\Models\Game;
use App\Models\GameClass;
use App\Models\Patch;
use App\Models\PvpTalent;
use App\Models\Specialization;
use App\Models\Spell;
use App\Models\TalentTree;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Internal dev-only tool for visually inspecting the imported WoW reference data (games/patches/
 * classes/specializations/spells/talent_trees/... — see ImportSpellData) against what the class
 * actually looks like in-game, to catch import bugs before building anything on top of this
 * schema. Read-only — never writes. Deliberately does not expose Game/Patch selectors (only one
 * game/current-patch exists right now); always resolves the "wow" game's current patch.
 */
class GameDataBrowser extends Component
{
    public ?int $classId = null;

    public ?int $specId = null;

    public ?int $heroTreeId = null;

    public function updatedClassId(): void
    {
        $this->specId = null;
        $this->heroTreeId = null;
    }

    public function updatedSpecId(): void
    {
        // Hero trees are now spec-scoped (talent_tree_specializations — see game-data.md) —
        // clear the current selection if it isn't actually available to the newly selected
        // spec, rather than silently continuing to show hero talents from a tree the new spec
        // can't access. $this->heroTrees is computed fresh here since specId is already updated.
        if ($this->heroTreeId && !$this->heroTrees->contains('id', $this->heroTreeId)) {
            $this->heroTreeId = null;
        }
    }

    private function currentPatch(): ?Patch
    {
        return Game::where('slug', 'wow')->first()?->currentPatch;
    }

    public function getClassesProperty(): Collection
    {
        return GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')
            ->get();
    }

    public function getSelectedClassProperty(): ?GameClass
    {
        return $this->classId ? GameClass::find($this->classId) : null;
    }

    public function getSpecializationsProperty(): Collection
    {
        if (!$this->classId) {
            return collect();
        }

        return Specialization::where('class_id', $this->classId)->orderBy('name')->get();
    }

    public function getSelectedSpecProperty(): ?Specialization
    {
        return $this->specId ? Specialization::find($this->specId) : null;
    }

    public function getHeroTreesProperty(): Collection
    {
        $patch = $this->currentPatch();

        if (!$this->classId || !$this->specId || !$patch) {
            return collect();
        }

        // Hero trees are class-wide in talent_trees (spec_id is always null there) but
        // spec-restricted in reality (a hero tree belongs to exactly two specs by game
        // design) — talent_tree_specializations is the real source of that restriction,
        // since Blizzard's own Game Data API doesn't scope hero_talent_trees by spec at
        // all (confirmed live, 2026-07-25 — see game-data.md).
        $specId = $this->specId;

        return TalentTree::where('class_id', $this->classId)
            ->where('patch_id', $patch->id)
            ->where('type', 'hero')
            ->whereHas('specializations', fn ($q) => $q->where('specializations.id', $specId))
            ->orderBy('name')
            ->get();
    }

    public function getSelectedHeroTreeProperty(): ?TalentTree
    {
        return $this->heroTreeId ? TalentTree::find($this->heroTreeId) : null;
    }

    private function spellEagerLoads(): array
    {
        return ['effects', 'outgoingRelationships.targetSpell', 'incomingRelationships.sourceSpell', 'classAvailability'];
    }

    /**
     * Loads a tree's nodes -> entries -> spells, ordered by (pos_y, pos_x) to roughly mirror the
     * in-game visual layout (top-to-bottom, left-to-right) — matters for the "does this look
     * right" scanning use case this whole page exists for.
     */
    private function loadTreeNodes(?TalentTree $tree): Collection
    {
        if (!$tree) {
            return collect();
        }

        return $tree->nodes()
            ->with(['entries.spell' => fn ($q) => $q->with($this->spellEagerLoads())])
            ->orderBy('pos_y')
            ->orderBy('pos_x')
            ->get();
    }

    public function getBaselineSpellsProperty(): Collection
    {
        $patch = $this->currentPatch();

        if (!$this->classId || !$this->specId || !$patch) {
            return collect();
        }

        $specId = $this->specId;

        // Predates resolveBaselineSpecIds() (see game-data.md) — baseline.txt used to be
        // uniformly spec_id=null, so filtering on it here would've been a no-op. Now that
        // spec_id is refined per-record from the Class: field, this must actually filter by
        // it, the same way getTopCooldownSpellsProperty() already correctly does — otherwise
        // a spec-restricted baseline spell (e.g. Shadow-only Vampiric Embrace) shows under
        // every spec of the class regardless of the correct data underneath it.
        return Spell::where('patch_id', $patch->id)
            ->whereHas('classAvailability', function ($q) use ($specId) {
                $q->where('class_id', $this->classId)
                    ->where('source', 'baseline')
                    ->where(fn ($q2) => $q2->whereNull('spec_id')->orWhere('spec_id', $specId));
            })
            ->with($this->spellEagerLoads())
            ->orderBy('name')
            ->get();
    }

    /**
     * Top 10 highest-cooldown spells available to the selected class/spec, from baseline or
     * talent sources only (pvp_talent excluded — those already have their own section below).
     * A spell_class_availability row's spec_id is null for class-wide availability (baseline,
     * class talents, hero talents — classifyFileSource() assigns all three null) or set to a
     * specific spec for spec-file talents (e.g. discipline.txt) — both are included here, which
     * is also why the source label below can only say "Baseline" or "Talent": the schema doesn't
     * distinguish class/hero/spec talents from each other once spec_id is null.
     *
     * @return Collection<int, array{spell: Spell, source: string}>
     */
    public function getTopCooldownSpellsProperty(): Collection
    {
        $patch = $this->currentPatch();

        if (!$this->classId || !$this->specId || !$patch) {
            return collect();
        }

        $classId = $this->classId;
        $specId = $this->specId;

        return Spell::where('patch_id', $patch->id)
            ->whereNotNull('cooldown_seconds')
            ->whereHas('classAvailability', function ($q) use ($classId, $specId) {
                $q->where('class_id', $classId)
                    ->whereIn('source', ['baseline', 'talent'])
                    ->where(fn ($q2) => $q2->whereNull('spec_id')->orWhere('spec_id', $specId));
            })
            ->with($this->spellEagerLoads())
            ->orderByDesc('cooldown_seconds')
            ->limit(10)
            ->get()
            ->map(fn (Spell $spell) => [
                'spell' => $spell,
                'source' => $spell->classAvailability->contains('source', 'baseline') ? 'Baseline' : 'Talent',
            ]);
    }

    public function getClassTalentTreeProperty(): ?TalentTree
    {
        $patch = $this->currentPatch();

        if (!$this->classId || !$patch) {
            return null;
        }

        return TalentTree::where('class_id', $this->classId)
            ->where('patch_id', $patch->id)
            ->where('type', 'class')
            ->first();
    }

    public function getClassTalentNodesProperty(): Collection
    {
        return $this->loadTreeNodes($this->classTalentTree);
    }

    public function getSpecTalentTreeProperty(): ?TalentTree
    {
        $patch = $this->currentPatch();

        if (!$this->specId || !$patch) {
            return null;
        }

        return TalentTree::where('spec_id', $this->specId)
            ->where('patch_id', $patch->id)
            ->where('type', 'spec')
            ->first();
    }

    public function getSpecTalentNodesProperty(): Collection
    {
        return $this->loadTreeNodes($this->specTalentTree);
    }

    public function getHeroTalentNodesProperty(): Collection
    {
        return $this->loadTreeNodes($this->selectedHeroTree);
    }

    public function getPvpTalentsProperty(): Collection
    {
        if (!$this->specId) {
            return collect();
        }

        return PvpTalent::where('spec_id', $this->specId)
            ->with(['spell' => fn ($q) => $q->with($this->spellEagerLoads())])
            ->orderBy('unlock_level')
            ->get();
    }

    /**
     * Best-effort only — the schema has no explicit passive/cast-time flag anywhere (the talent
     * JSON's cast_time field was never persisted, and baseline .txt "[...Passive...]" name
     * annotations are stripped by SpellDataFileParser's Name regex). This just scans already-
     * loaded descriptions for the literal word "passive". Expect false negatives; not a source
     * of truth, which is exactly why it's labelled "(if available)" in the UI.
     *
     * @return Collection<int, array{spell: Spell, source: string}>
     */
    public function getPassiveLikeSpellsProperty(): Collection
    {
        $tagged = collect();

        foreach ($this->baselineSpells as $spell) {
            $tagged->push(['spell' => $spell, 'source' => 'Baseline']);
        }

        foreach ($this->classTalentNodes as $node) {
            foreach ($node->entries as $entry) {
                if ($entry->spell) {
                    $tagged->push(['spell' => $entry->spell, 'source' => 'Class Talent']);
                }
            }
        }

        foreach ($this->specTalentNodes as $node) {
            foreach ($node->entries as $entry) {
                if ($entry->spell) {
                    $tagged->push(['spell' => $entry->spell, 'source' => 'Spec Talent']);
                }
            }
        }

        foreach ($this->heroTalentNodes as $node) {
            foreach ($node->entries as $entry) {
                if ($entry->spell) {
                    $tagged->push(['spell' => $entry->spell, 'source' => 'Hero Talent']);
                }
            }
        }

        foreach ($this->pvpTalents as $pvpTalent) {
            if ($pvpTalent->spell) {
                $tagged->push(['spell' => $pvpTalent->spell, 'source' => 'PvP Talent']);
            }
        }

        return $tagged
            ->unique(fn ($t) => $t['spell']->id)
            ->filter(fn ($t) => str_contains(strtolower($t['spell']->description ?? ''), 'passive'))
            ->values();
    }

    public function render()
    {
        return view('livewire.admin.game-data-browser', [
            'classes' => $this->classes,
            'selectedClass' => $this->selectedClass,
            'specializations' => $this->specializations,
            'selectedSpec' => $this->selectedSpec,
            'heroTrees' => $this->heroTrees,
            'selectedHeroTree' => $this->selectedHeroTree,
            'baselineSpells' => $this->baselineSpells,
            'topCooldownSpells' => $this->topCooldownSpells,
            'classTalentTree' => $this->classTalentTree,
            'classTalentNodes' => $this->classTalentNodes,
            'specTalentTree' => $this->specTalentTree,
            'specTalentNodes' => $this->specTalentNodes,
            'heroTalentNodes' => $this->heroTalentNodes,
            'pvpTalents' => $this->pvpTalents,
            'passiveLikeSpells' => $this->passiveLikeSpells,
        ])->layout('layouts.app');
    }
}
