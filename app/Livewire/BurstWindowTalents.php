<?php

namespace App\Livewire;

use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use Illuminate\Support\Facades\File;
use Livewire\Component;

/**
 * Read-only view of the real talent build embedded in one Burst Windows length bracket (see
 * TopDamageRotations/EnrichRotationTalents) — "link the talents to our own talent calculator
 * that we use in the admin but not as something we can change, just like something to look at"
 * (2026-08-28 direct request). Reuses <livewire:talent-selector layout="grid" :read-only="true">
 * — the exact same grid/tooltip/lock-badge rendering Admin\TalentBuildEditor uses — seeded from
 * this window's own resolved `talentBuild.talents`/`.pvpTalents` (their `nodeId`/`entryId`/
 * `pvpTalentId` fields, added specifically so a caller doesn't have to re-derive them) rather
 * than a persisted TalentBuild row. See TalentSelector's own $readOnly docblock for why nothing
 * on this page can ever write to talent_builds.
 *
 * Deliberately its own route/page, not a modal on top of TopDamageRotations — the grid layout
 * (3 trees, each its own scrollable positional canvas) is real page content, not something that
 * fits comfortably inside a small popup.
 */
class BurstWindowTalents extends Component
{
    public string $classSlug;

    public string $specSlug;

    public int $length;

    public function mount(string $classSlug, string $specSlug, int $length): void
    {
        $this->classSlug = $classSlug;
        $this->specSlug = $specSlug;
        $this->length = $length;

        PageViewEvent::log('burst_window_talents', $this->class?->id, $this->spec?->id);
    }

    public function getClassProperty(): ?GameClass
    {
        return GameClass::where('slug', $this->classSlug)->first();
    }

    public function getSpecProperty(): ?Specialization
    {
        $class = $this->class;

        return $class ? Specialization::where('class_id', $class->id)->where('slug', $this->specSlug)->first() : null;
    }

    /**
     * Reads the same promoted rotation file TopDamageRotations does, straight off disk (no
     * caching needed — this is a rarely-hit page, and the file is small) rather than going
     * through ArenaLogService::rotationForSpec() a second time for one field.
     *
     * @return ?array{talents: array, pvpTalents: array}
     */
    public function getTalentBuildProperty(): ?array
    {
        $path = base_path("data/arena-logs/rotations/{$this->classSlug}/{$this->specSlug}.json");

        if (!File::exists($path)) {
            return null;
        }

        $data = json_decode(File::get($path), true);

        return $data['topDpsWindowsByLength'][$this->length]['talentBuild'] ?? null;
    }

    /** @return array<int,int> talent_node_id => chosen talent_node_entry id, for TalentSelector's readOnly preset. */
    public function getPresetChosenEntriesProperty(): array
    {
        $talents = $this->talentBuild['talents'] ?? [];

        $preset = [];
        foreach ($talents as $t) {
            if (!empty($t['nodeId']) && !empty($t['entryId'])) {
                $preset[$t['nodeId']] = $t['entryId'];
            }
        }

        return $preset;
    }

    /** @return array<int,int> pvp_talents.id list, for TalentSelector's readOnly preset. */
    public function getPresetPvpTalentIdsProperty(): array
    {
        return collect($this->talentBuild['pvpTalents'] ?? [])
            ->pluck('pvpTalentId')
            ->filter()
            ->values()
            ->all();
    }

    public function render()
    {
        $class = $this->class;
        $spec = $this->spec;
        $title = $spec && $class ? "{$spec->name} {$class->name} — Talent Build" : 'Talent Build';

        return view('livewire.burst-window-talents', [
            'class' => $class,
            'spec' => $spec,
            'talentBuild' => $this->talentBuild,
            'presetChosenEntries' => $this->presetChosenEntries,
            'presetPvpTalentIds' => $this->presetPvpTalentIds,
        ])->layout('layouts.app', [
            'title' => "{$title} | MindCollector",
            'description' => 'A real talent build pulled directly from an archived arena match — view only, shown in our talent calculator.',
        ]);
    }
}
