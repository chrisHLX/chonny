<?php

namespace App\Livewire\Admin;

use App\Models\PageViewEvent;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Usage analytics for WoW Comps and Spell Explorer, sourced from PageViewEvent (see that model
 * and CLAUDE.md's "diagnostic quiz analytics" investigation — same session this was built in).
 *
 * A row with class_id null is a bare page view; a row with class_id set is a real class/spec
 * selection. This split matters: SpellExplorer::mount() always lands on the alphabetically-first
 * class by default, so attributing that landing view to a class would make it look artificially
 * popular regardless of real interest — same mistake Admin\DiagnosticStats::getSummaryProperty()
 * had before this session's fix, deliberately avoided here from the start rather than repeated.
 * Only an explicit pick (SpellExplorer::updatedClassId()/updatedSpecId(),
 * WowComps::logSlotSelection()) is counted toward "most viewed classes/specs".
 */
class PageUsage extends Component
{
    private const PAGES = ['spell_explorer', 'wow_comps'];

    public function getSummaryProperty(): array
    {
        return collect(self::PAGES)->mapWithKeys(function (string $page) {
            return [$page => [
                'views'      => PageViewEvent::where('page', $page)->whereNull('class_id')->count(),
                'selections' => PageViewEvent::where('page', $page)->whereNotNull('class_id')->count(),
            ]];
        })->all();
    }

    /**
     * Top classes by real selection count, per page — @return array<string, Collection>
     */
    public function getTopClassesProperty(): array
    {
        return collect(self::PAGES)->mapWithKeys(fn (string $page) => [
            $page => PageViewEvent::query()
                ->selectRaw('classes.name as name, count(*) as count')
                ->join('classes', 'classes.id', '=', 'page_view_events.class_id')
                ->where('page_view_events.page', $page)
                ->groupBy('classes.id', 'classes.name')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
        ])->all();
    }

    /**
     * Top class+spec combos by real selection count, per page — @return array<string, Collection>
     */
    public function getTopSpecsProperty(): array
    {
        return collect(self::PAGES)->mapWithKeys(fn (string $page) => [
            $page => PageViewEvent::query()
                ->selectRaw('classes.name as class_name, specializations.name as spec_name, count(*) as count')
                ->join('classes', 'classes.id', '=', 'page_view_events.class_id')
                ->join('specializations', 'specializations.id', '=', 'page_view_events.spec_id')
                ->where('page_view_events.page', $page)
                ->groupBy('classes.id', 'classes.name', 'specializations.id', 'specializations.name')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
        ])->all();
    }

    /**
     * WowComps only — which slot (Healer / DPS 1 / DPS 2) a spec gets picked into most, since a
     * spec's popularity in this comp-building context is partly about role fit, not just overall
     * interest. Mirrors WowComps::$slots's own index order (0 = Healer, 1/2 = DPS) — cosmetic
     * labels only, disambiguating the two same-named "DPS" slots for display.
     */
    private const SLOT_LABELS = [0 => 'Healer', 1 => 'DPS 1', 2 => 'DPS 2'];

    public function getSlotBreakdownProperty(): Collection
    {
        $labels = self::SLOT_LABELS;

        return PageViewEvent::query()
            ->selectRaw('page_view_events.slot as slot, classes.name as class_name, specializations.name as spec_name, count(*) as count')
            ->join('classes', 'classes.id', '=', 'page_view_events.class_id')
            ->join('specializations', 'specializations.id', '=', 'page_view_events.spec_id')
            ->where('page_view_events.page', 'wow_comps')
            ->whereNotNull('page_view_events.slot')
            ->groupBy('page_view_events.slot', 'classes.id', 'classes.name', 'specializations.id', 'specializations.name')
            ->orderByDesc('count')
            ->get()
            ->groupBy('slot')
            ->map(fn (Collection $rows, string $slot) => [
                'label' => $labels[(int) $slot] ?? "Slot {$slot}",
                'top'   => $rows->sortByDesc('count')->take(5)->values(),
            ]);
    }

    public function render()
    {
        return view('livewire.admin.page-usage', [
            'summary'       => $this->summary,
            'topClasses'    => $this->topClasses,
            'topSpecs'      => $this->topSpecs,
            'slotBreakdown' => $this->slotBreakdown,
        ])->layout('layouts.app');
    }
}
