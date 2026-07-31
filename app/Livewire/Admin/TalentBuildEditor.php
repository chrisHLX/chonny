<?php

namespace App\Livewire\Admin;

use App\Models\GameClass;
use App\Models\Specialization;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Thin admin wrapper around <livewire:talent-selector> for authoring each spec's admin-curated
 * "default" (meta) talent build — the fallback TalentSelectionService::resolveActiveBuild()
 * applies when a viewer has no saved build of their own. Sourcing which talents count as "meta"
 * (e.g. top-rated players' loadouts) is the admin's own manual curation task — this page only
 * provides the tool to enter and store the result, reusing the exact same picker built for the
 * player-facing TalentSelector (isDefaultEditor=true) rather than a separate authoring UI.
 */
class TalentBuildEditor extends Component
{
    public ?int $classId = null;

    public ?int $specId = null;

    public function updatedClassId(): void
    {
        $this->specId = null;
    }

    public function getClassesProperty(): Collection
    {
        return GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')
            ->get();
    }

    public function getSpecializationsProperty(): Collection
    {
        if (!$this->classId) {
            return collect();
        }

        return Specialization::where('class_id', $this->classId)->orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.admin.talent-build-editor', [
            'classes' => $this->classes,
            'specializations' => $this->specializations,
        ])->layout('layouts.app');
    }
}
