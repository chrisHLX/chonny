<?php

namespace App\Livewire\Admin;

use App\Models\PageViewEvent;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Usage analytics for every page tracked in PAGES below — the whole WoW-facing surface (WoW
 * Comps, Spell Explorer, Top Burst Windows, PvP Guides and its four embedded panels, Top 10 CC
 * Chains, the per-spell detail page, etc.) — sourced from PageViewEvent (see that model and
 * CLAUDE.md's "diagnostic quiz analytics" investigation — same session this was built in). The
 * summary / top-classes / top-specs sections all iterate PAGES, so a new slug added there
 * appears in every section automatically.
 *
 * A row with class_id null is a bare page view; a row with class_id set is a real class/spec
 * selection. This split matters: SpellExplorer::mount() always lands on the alphabetically-first
 * class by default, so attributing that landing view to a class would make it look artificially
 * popular regardless of real interest — same mistake Admin\DiagnosticStats::getSummaryProperty()
 * had before this session's fix, deliberately avoided here from the start rather than repeated.
 * Only an explicit pick (SpellExplorer::updatedClassId()/updatedSpecId(),
 * WowComps::logSlotSelection(), TopDamageRotations::selectSpec()) is counted toward "most viewed
 * classes/specs".
 */
class PageUsage extends Component
{
    /**
     * Every page slug tracked here — keys are the exact `$page` string passed to
     * `PageViewEvent::log()`, values are the display label used by the admin dashboard. Any new
     * user-facing route/page must add an entry here (see CLAUDE.md's "any new route or page must
     * be tracked as a page view" rule) — logging events that never appear in this list is
     * equivalent to not tracking them at all, confirmed as a real gap: `top_damage_rotations`
     * (the "Top Burst Windows" page) was already calling PageViewEvent::log() from day one but was
     * never added here, so its views were being recorded with nowhere to see them.
     */
    private const PAGES = [
        'spell_detail' => 'Spell Detail',
        'spell_explorer' => 'Spell Explorer',
        'wow_comps' => 'WoW Comps',
        'top_damage_rotations' => 'Top Burst Windows',
        'burst_window_talents' => 'Burst Window Talent View',
        'class_guide' => 'Class Guide',
        'top_cc_chains' => 'Top 10 CC Chains',
        'claudes_guides' => "Claude's Guides",
        'claudes_counters' => 'Spell Counters',
        'burst_guides' => 'Burst Guides',
        'pvp_guides' => 'PvP Guides',
        'guides_index' => 'My Guides',
        'guide_builder' => 'Guide Builder',
        'guide_show' => 'Shared Guide (read)',
        'guides_browse' => 'Player Guides (browse)',
        'guilds_index' => 'Guilds',
        'guild_show' => 'Guild (read)',
        'battlenet_characters' => 'Your Characters',
        'battlenet_character' => 'Character Detail',
    ];

    /**
     * PvP Guides tab keys (the `page_view_events.slot` value on a 'pvp_guides_tab' row, written
     * by PvpGuides::selectTab()) => display label. Same not-a-PAGES-entry reasoning as
     * WOW_COMPS_TAB_LABELS below: 'pvp_guides_tab' is not a standalone page, and folding it into
     * PAGES would give the summary a second, double-counted row for the same page. Surfaced by
     * getPvpGuidesTabBreakdownProperty() + its own blade section instead.
     *
     * Unlike WoW Comps' tabs (an Alpine-only bar needing a fetch() beacon), these rows DO carry
     * class_id/spec_id — a PvP Guides tab switch is a real Livewire round trip on a page that
     * already knows which spec is open, so which spec a tab is opened for is recorded for free.
     */
    private const PVP_GUIDES_TAB_LABELS = [
        'kit' => 'Class Kit',
        'burst' => 'Burst Guide',
        'spells' => 'Spells',
        'counters' => 'Counters',
    ];

    /**
     * WoW Comps tab keys (the `page_view_events.slot` value on a 'wow_comps_tab' row, written
     * by TrackController::wowCompsTab()) => display label. Deliberately NOT a PAGES entry:
     * 'wow_comps_tab' isn't a standalone page, and folding it into PAGES would make the
     * summary / top-classes / top-specs loops all render empty rows for it (those rows carry
     * no class_id/spec_id). Surfaced instead by getTabBreakdownProperty() + its own blade
     * section, the same way getSlotBreakdownProperty() is a WowComps-specific extra outside
     * the PAGES loop.
     */
    private const WOW_COMPS_TAB_LABELS = [
        'offensive' => 'Offensive Cooldowns',
        'defensive' => 'Defensive Cooldowns',
        'synergies' => 'Crowd Control',
        'pvptalents' => 'PvP Talents',
        'active' => 'Active Abilities',
        'passive' => 'Buffs & Passives',
        'rotation' => 'Burst Window',
    ];

    /**
     * WoW Comps "Common picks" preset keys (the `page_view_events.slot` value on a
     * 'wow_comps_preset' row, written by WowComps::applyPreset()) => display label. Same
     * not-a-PAGES-entry reasoning as WOW_COMPS_TAB_LABELS above — surfaced by
     * getPresetBreakdownProperty() + its own blade section. A preset click also logs the
     * normal per-slot attributed rows, so it already shows up in top classes/specs/slot
     * breakdown; this is the extra "which preset" dimension.
     */
    private const WOW_COMPS_PRESET_LABELS = [
        'rmp' => 'RMD',
        'jungle' => 'Jungle',
        'turbo' => 'Turbo Cleave',
    ];

    public function getSummaryProperty(): array
    {
        return collect(array_keys(self::PAGES))->mapWithKeys(function (string $page) {
            return [$page => [
                'views' => PageViewEvent::where('page', $page)->whereNull('class_id')->count(),
                'selections' => PageViewEvent::where('page', $page)->whereNotNull('class_id')->count(),
            ]];
        })->all();
    }

    /**
     * Top classes by real selection count, per page — @return array<string, Collection>
     */
    public function getTopClassesProperty(): array
    {
        return collect(array_keys(self::PAGES))->mapWithKeys(fn (string $page) => [
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
        return collect(array_keys(self::PAGES))->mapWithKeys(fn (string $page) => [
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
                'top' => $rows->sortByDesc('count')->take(5)->values(),
            ]);
    }

    /**
     * WoW Comps only — how often each tab in the (Alpine-only, non-round-tripping) tab bar is
     * opened, from the fire-and-forget beacon in wow-comps.blade.php's selectTab(). Counts an
     * actual switch INTO a tab, not re-clicks of the already-active one (selectTab() no-ops
     * those) and not the default tab landed on at page load.
     */
    public function getTabBreakdownProperty(): Collection
    {
        $labels = self::WOW_COMPS_TAB_LABELS;

        return PageViewEvent::query()
            ->selectRaw('slot as tab, count(*) as count')
            ->where('page', 'wow_comps_tab')
            ->whereNotNull('slot')
            ->groupBy('slot')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => (object) [
                'tab' => $row->tab,
                'label' => $labels[$row->tab] ?? $row->tab,
                'count' => (int) $row->count,
            ]);
    }

    /**
     * WoW Comps only — how often each "Common picks" preset button is used, from the
     * 'wow_comps_preset' row WowComps::applyPreset() logs alongside the normal per-slot
     * attributed rows.
     */
    public function getPresetBreakdownProperty(): Collection
    {
        $labels = self::WOW_COMPS_PRESET_LABELS;

        return PageViewEvent::query()
            ->selectRaw('slot as preset, count(*) as count')
            ->where('page', 'wow_comps_preset')
            ->whereNotNull('slot')
            ->groupBy('slot')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => (object) [
                'preset' => $row->preset,
                'label' => $labels[$row->preset] ?? $row->preset,
                'count' => (int) $row->count,
            ]);
    }

    /**
     * Claude's Counters / Spell Counters — which matchup got picked most, from the `slot` column
     * the page's old two-spec matchup picker used to write. Frozen/historical only as of
     * 2026-09-04: that picker (and the attributed log call behind it) was removed when the page
     * was rewritten to a plain per-class counters list with no matchup concept at all — this
     * still reads real past data correctly, it just won't gain any new rows going forward. Left
     * in rather than deleted since the history itself is still real and accurate.
     */
    public function getCountersMatchupBreakdownProperty(): \Illuminate\Support\Collection
    {
        return PageViewEvent::query()
            ->selectRaw('slot as matchup, count(*) as count')
            ->where('page', 'claudes_counters')
            ->whereNotNull('slot')
            ->groupBy('slot')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * PvP Guides only — how often each tab is opened, and for which spec. Counts a real switch
     * INTO a tab (PvpGuides::selectTab() no-ops a re-click of the active tab and nothing logs
     * the tab landed on at page load), so this reads as "which question people actually came
     * with" rather than raw clicks.
     */
    public function getPvpGuidesTabBreakdownProperty(): Collection
    {
        $labels = self::PVP_GUIDES_TAB_LABELS;

        return PageViewEvent::query()
            ->selectRaw('slot as tab, count(*) as count')
            ->where('page', 'pvp_guides_tab')
            ->whereNotNull('slot')
            ->groupBy('slot')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => (object) [
                'tab' => $row->tab,
                'label' => $labels[$row->tab] ?? $row->tab,
                'count' => (int) $row->count,
            ]);
    }

    public function render()
    {
        return view('livewire.admin.page-usage', [
            'pages' => self::PAGES,
            'pvpGuidesTabBreakdown' => $this->pvpGuidesTabBreakdown,
            'summary' => $this->summary,
            'topClasses' => $this->topClasses,
            'topSpecs' => $this->topSpecs,
            'slotBreakdown' => $this->slotBreakdown,
            'tabBreakdown' => $this->tabBreakdown,
            'presetBreakdown' => $this->presetBreakdown,
            'countersMatchupBreakdown' => $this->countersMatchupBreakdown,
        ])->layout('layouts.app');
    }
}
