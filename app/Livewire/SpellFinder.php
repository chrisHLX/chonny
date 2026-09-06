<?php

namespace App\Livewire;

use App\Models\GameClass;
use App\Models\Spell;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Structured, safe query builder over the "spell shape" columns materialized by
 * ImportSpellData::materializeSpellShape() and the various hand-curated spell fields (dr_category,
 * chain_target, is_peel, is_interrupt, is_mobility, usable_while_cc, bypasses_active_defense,
 * silence_immune_by_school) — see CLAUDE.md's 2026-09-03 "spell shape" discussion for how each
 * of these came to exist. Every filter here is applied via parameterized Eloquent, never raw SQL
 * built from user input — deliberately chosen over a free-text SQL box (see that discussion)
 * since this page has no auth gate, same as /cc-review and /cc-immunity-review.
 *
 * Two kinds of filter, applied differently:
 *  - Spell-level facts (category, dr_category, chain_target, usable_while_cc, etc.) are plain
 *    WHERE clauses directly on `spells` — no spec dimension, can't produce duplicate rows.
 *  - Spec-scoped facts (class/spec themselves, is_priority, is_offensive_cooldown,
 *    is_defensive_cooldown) are applied via whereHas('classAvailability', ...) so a spell still
 *    only ever appears ONCE in the results even if several of its class/spec rows match — the
 *    matching class/spec combos are then shown as a separate "Available to" badge list per row
 *    (loaded via preloadedAvailability()), not by duplicating the spell's own row.
 */
class SpellFinder extends Component
{
    private const RESULT_LIMIT = 200;

    private const CATEGORIES = ['Offensive', 'Defensive', 'Crowd Control', 'Mobility', 'Utility', 'Other'];

    private const CHAIN_TARGETS = ['kill_target', 'healer', 'both'];

    private const CC_TOKENS = [
        'stun' => 'Stunned',
        'fear' => 'Feared',
        'flee' => 'Fleeing',
        'confuse' => 'Confused',
        'charm' => 'Charmed',
        'horror' => 'Horror-stunned',
    ];

    public ?int $classId = null;

    public ?int $specId = null;

    public string $category = '';

    public string $drCategory = '';

    public string $chainTarget = '';

    /** @var array<int, string> */
    public array $usableWhileCc = [];

    public bool $bypassesActiveDefense = false;

    public bool $silenceImmuneBySchool = false;

    public bool $isMobility = false;

    public bool $isPeel = false;

    public bool $isInterrupt = false;

    public bool $isPriority = false;

    public bool $isOffensiveCooldown = false;

    public bool $isDefensiveCooldown = false;

    public ?float $cooldownMin = null;

    public ?float $cooldownMax = null;

    public string $nameSearch = '';

    public function updatedClassId(): void
    {
        // A spec that no longer belongs to the newly-picked class would silently filter to
        // nothing (and confusingly look like "no results" rather than "stale spec selected") —
        // clear it the same way SubjectContextForm clears a stale child selection on parent change.
        if ($this->classId !== null
            && $this->specId !== null
            && !\App\Models\Specialization::where('id', $this->specId)->where('class_id', $this->classId)->exists()) {
            $this->specId = null;
        }
    }

    public function resetFilters(): void
    {
        $this->reset([
            'classId', 'specId', 'category', 'drCategory', 'chainTarget', 'usableWhileCc',
            'bypassesActiveDefense', 'silenceImmuneBySchool', 'isMobility', 'isPeel', 'isInterrupt',
            'isPriority', 'isOffensiveCooldown', 'isDefensiveCooldown', 'cooldownMin', 'cooldownMax',
            'nameSearch',
        ]);
    }

    /** @return Collection<int, GameClass> */
    public function getClassesProperty(): Collection
    {
        return GameClass::orderBy('name')->get();
    }

    /** @return Collection<int, \App\Models\Specialization> */
    public function getSpecsProperty(): Collection
    {
        if ($this->classId === null) {
            return collect();
        }

        return \App\Models\Specialization::where('class_id', $this->classId)->orderBy('name')->get();
    }

    /** @return Collection<int, string> real dr_category values actually in use, not a hardcoded guess */
    public function getDrCategoriesProperty(): Collection
    {
        return Spell::whereHas('patch', fn ($q) => $q->where('is_current', true))
            ->whereNotNull('dr_category')
            ->distinct()
            ->orderBy('dr_category')
            ->pluck('dr_category');
    }

    public function getCategoriesProperty(): array
    {
        return self::CATEGORIES;
    }

    public function getChainTargetsProperty(): array
    {
        return self::CHAIN_TARGETS;
    }

    public function getCcTokensProperty(): array
    {
        return self::CC_TOKENS;
    }

    /**
     * @return array{spells: Collection<int, Spell>, total: int, truncated: bool}
     */
    public function getResultsProperty(): array
    {
        $query = Spell::query()
            ->whereHas('patch', fn ($q) => $q->where('is_current', true));

        if ($this->category !== '') {
            $query->where('category', $this->category);
        }
        if ($this->drCategory !== '') {
            $query->where('dr_category', $this->drCategory);
        }
        if ($this->chainTarget !== '') {
            $query->where('chain_target', $this->chainTarget);
        }
        foreach ($this->usableWhileCc as $token) {
            $query->where('usable_while_cc', 'like', "%{$token}%");
        }
        if ($this->bypassesActiveDefense) {
            $query->where('bypasses_active_defense', true);
        }
        if ($this->silenceImmuneBySchool) {
            $query->where('silence_immune_by_school', true);
        }
        if ($this->isMobility) {
            $query->where('is_mobility', true);
        }
        if ($this->isPeel) {
            $query->where('is_peel', true);
        }
        if ($this->isInterrupt) {
            $query->where('is_interrupt', true);
        }
        if ($this->cooldownMin !== null) {
            $query->where('cooldown_seconds', '>=', $this->cooldownMin);
        }
        if ($this->cooldownMax !== null) {
            $query->where('cooldown_seconds', '<=', $this->cooldownMax);
        }
        if (trim($this->nameSearch) !== '') {
            $query->where('name', 'like', '%'.trim($this->nameSearch).'%');
        }

        $needsAvailabilityFilter = $this->classId !== null
            || $this->specId !== null
            || $this->isPriority
            || $this->isOffensiveCooldown
            || $this->isDefensiveCooldown;

        if ($needsAvailabilityFilter) {
            $query->whereHas('classAvailability', function ($q) {
                if ($this->classId !== null) {
                    $q->where('class_id', $this->classId);
                }
                if ($this->specId !== null) {
                    $q->where('spec_id', $this->specId);
                }
                if ($this->isPriority) {
                    $q->where('is_priority', true);
                }
                if ($this->isOffensiveCooldown) {
                    $q->where('is_offensive_cooldown', true);
                }
                if ($this->isDefensiveCooldown) {
                    $q->where('is_defensive_cooldown', true);
                }
            });
        }

        $total = $query->count();
        $spells = $query->orderBy('name')->limit(self::RESULT_LIMIT)->get();
        $spells->load(['classAvailability.gameClass', 'classAvailability.specialization']);

        return [
            'spells' => $spells,
            'total' => $total,
            'truncated' => $total > self::RESULT_LIMIT,
        ];
    }

    public function render()
    {
        return view('livewire.spell-finder', [
            'classesList' => $this->classes,
            'specsList' => $this->specs,
            'drCategoriesList' => $this->drCategories,
            'categoriesList' => $this->categories,
            'chainTargetsList' => $this->chainTargets,
            'ccTokensList' => $this->ccTokens,
            'results' => $this->results,
        ])->layout('layouts.app');
    }
}
