<?php

namespace App\Livewire;

use App\Models\Spell;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Read-only data-review tool for the Synergies-tab curation work (see CLAUDE.md's "Synergies
 * tab" section) — shows every spell currently tagged with dr_category, grouped by class then
 * spec, so the bulk-applied taxonomy can be visually spot-checked against the game. Not the
 * Synergies tab itself (no chain-building, no chain_target grouping) — just a flat review grid.
 *
 * A spell shows once per (class, spec) SpellClassAvailability row it has — a spec_id=NULL row
 * (class-wide) renders under a "(all specs)" pseudo-group for that class rather than being
 * duplicated into every real spec, so it's visually distinct from a spec-specific tag.
 */
class CcReview extends Component
{
    /**
     * @return Collection<string, array{classId: ?int, specs: Collection<string, array{specId: ?int, spells: Collection<int, Spell>}>}>
     */
    public function getGroupedSpellsProperty(): Collection
    {
        $spells = Spell::whereHas('patch', fn ($q) => $q->where('is_current', true))
            ->whereNotNull('dr_category')
            ->with(['classAvailability.gameClass', 'classAvailability.specialization'])
            ->orderBy('dr_category')
            ->orderBy('name')
            ->get();

        // Built as plain nested arrays, not a Collection — Collection's ArrayAccess doesn't
        // support chained nested mutation ($grouped[$a][$b][$c]->push(...) silently no-ops,
        // "Indirect modification of overloaded element" — confirmed the hard way). Converted to
        // Collections only once, at the very end, after all mutation is done.
        $grouped = [];

        foreach ($spells as $spell) {
            if ($spell->classAvailability->isEmpty()) {
                $grouped['(unassigned)']['classId'] ??= null;
                $grouped['(unassigned)']['specs']['(no class availability)']['specId'] ??= null;
                $grouped['(unassigned)']['specs']['(no class availability)']['spells'][] = $spell;

                continue;
            }

            foreach ($spell->classAvailability as $availability) {
                $className = $availability->gameClass->name ?? '(unknown class)';
                $specName = $availability->specialization->name ?? '(all specs)';

                $grouped[$className]['classId'] ??= $availability->class_id;
                $grouped[$className]['specs'][$specName]['specId'] ??= $availability->spec_id;
                $grouped[$className]['specs'][$specName]['spells'][] = $spell;
            }
        }

        ksort($grouped);

        return collect($grouped)->map(function (array $classGroup) {
            ksort($classGroup['specs']);

            return [
                'classId' => $classGroup['classId'],
                'specs' => collect($classGroup['specs'])->map(fn (array $specGroup) => [
                    'specId' => $specGroup['specId'],
                    'spells' => collect($specGroup['spells']),
                ]),
            ];
        });
    }

    public function render()
    {
        return view('livewire.cc-review', [
            'grouped' => $this->groupedSpells,
        ])->layout('layouts.app');
    }
}
