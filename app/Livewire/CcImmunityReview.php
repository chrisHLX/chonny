<?php

namespace App\Livewire;

use App\Http\Services\ModuleSpellReferenceService;
use App\Models\Spell;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Read-only lookup for "can this be cast through CC / does it bypass dodge-parry-block /
 * does it grant a CC-immunity window" — see CLAUDE.md's investigation write-up (2026-09-02,
 * prompted by a real Dark Pact / Cloak of Shadows / Storm Bolt / Phase Shift report) for the
 * full mechanism trace. Same grouping pattern as CcReview (class -> spec, a spec_id=NULL row
 * renders under a "(all specs)" pseudo-group), but scoped to a different, broader set: any
 * spell with usable_while_cc, bypasses_active_defense, or cc_immunity_note set, which is NOT
 * the same set as CcReview's dr_category-tagged spells (a spell can be usable-while-stunned
 * without itself being a CC ability, e.g. Death Grip, Dark Pact).
 *
 * usable_while_cc/bypasses_active_defense are auto-derived at import time from real
 * SpellDataFileParser-captured Attribute flags — nothing here is hand-curated except
 * cc_immunity_note (see data/spelldata/cc-immunity-overrides.txt), which exists specifically
 * for PvP-talent-only facts with zero structured backing anywhere in this pipeline.
 */
class CcImmunityReview extends Component
{
    /**
     * @return Collection<string, array{classId: ?int, specs: Collection<string, array{specId: ?int, spells: Collection<int, Spell>}>}>
     */
    public function getGroupedSpellsProperty(): Collection
    {
        $spells = Spell::whereHas('patch', fn ($q) => $q->where('is_current', true))
            ->where(function ($q) {
                $q->whereNotNull('usable_while_cc')
                    ->orWhere('bypasses_active_defense', true)
                    ->orWhereNotNull('cc_immunity_note')
                    ->orWhereHas('effects', fn ($eq) => $eq->where('type', 'Mechanic Immunity')->whereNotNull('misc_value'));
            })
            ->with(['classAvailability.gameClass', 'classAvailability.specialization', 'effects'])
            ->orderBy('name')
            ->get();

        $service = app(ModuleSpellReferenceService::class);
        foreach ($spells as $spell) {
            $spell->setAttribute('grantsCcImmunity', $service->ccImmunityGrantedBy($spell));
        }

        // Same "plain nested arrays, not a Collection" discipline as CcReview::getGroupedSpellsProperty()
        // — Collection's ArrayAccess doesn't support chained nested mutation.
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
        return view('livewire.cc-immunity-review', [
            'grouped' => $this->groupedSpells,
        ])->layout('layouts.app');
    }
}
