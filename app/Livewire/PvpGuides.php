<?php

namespace App\Livewire;

use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Specialization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "PvP Guides" - one page per class/spec, gathering the four things this site already knew about
 * a spec behind a tab bar instead of behind four separate nav links:
 *
 *   kit      -> App\Livewire\ClassGuide           (/class-guide,    "Class Kits" in the old nav)
 *   burst    -> App\Livewire\BurstGuideClassBlock (/burst-guides,   "Burst Guides")
 *   spells   -> App\Livewire\SpellExplorer        (/spells,         "Spells")
 *   counters -> App\Livewire\ClaudesCounters      (/spell-counters, "Spell Counters")
 *
 * Built 2026-09-07, direct request: all four pages are answers about a single class/spec, and
 * presenting them as four destinations made the viewer re-pick their spec on each one - through
 * three different pickers, at that (ClassGuide's inline pill list, SpellExplorer's grid modal,
 * and no picker at all on the other two, which listed every class at once).
 *
 * THIS COMPONENT OWNS SELECTION AND NOTHING ELSE. It resolves class/spec + the active tab, then
 * mounts the real page component for that tab as a lazy child. No panel's logic was copied here
 * and none was reimplemented - each of the four still computes exactly what it computed before,
 * and each still works standalone at its own URL (nothing was deleted or redirected away; those
 * routes are what an existing bookmark, the sitemap and every in-page link still resolve to).
 * What the panels gained is an $embedded flag that suppresses their own page header, their own
 * picker and their own copy of the shared spell-detail modal - this page supplies all three.
 *
 * WHY THE PANELS ARE LAZY: three of the four are genuinely expensive (SpellExplorer resolves a
 * spec's whole kit; ClassGuide reads and hydrates a playstyle sample; ClaudesCounters walks the
 * counter index), and only one is ever on screen. `lazy` on the child TAG rather than #[Lazy] on
 * the classes, deliberately - the class-level attribute would make the standalone pages lazy too,
 * changing their behaviour (and every existing Livewire::test() of them) for a reason that has
 * nothing to do with those pages. Same tag-level pattern burst-guides.blade.php already uses.
 *
 * WHY A SPEC CHANGE REDIRECTS rather than swapping state in place: a guide is a thing you send
 * someone a link to. ClassGuide already worked this way ("one guide per URL", plain wire:navigate
 * links) and keeping it means the URL always describes what is on screen - spec in the path, tab
 * in the query string - so back/forward and a pasted link both land exactly where the viewer was.
 */
class PvpGuides extends Component
{
    /**
     * tab key => [label, blurb]. Order is the tab bar's order, deliberately reading as a
     * narrowing funnel: what the spec is (kit), what it does with a cooldown window (burst),
     * every button it has (spells), and what answers its crowd control (counters).
     *
     * The keys are also the allowlist selectTab() validates against - an unknown tab falls back
     * to the default rather than rendering an empty page.
     */
    public const TABS = [
        'kit' => ['label' => 'Class Kit', 'blurb' => 'The talents top-rated players run, and which picks pull their weight.'],
        'burst' => ['label' => 'Offensive Kit', 'blurb' => 'How long your go lasts, how many globals fit in it, and the order to press them.'],
        'spells' => ['label' => 'Spells', 'blurb' => 'Every talent and PvP talent, with cooldowns adjusted for your build.'],
        'counters' => ['label' => 'Counters', 'blurb' => 'Your crowd control, and what the other team can use to answer it.'],
    ];

    public const DEFAULT_TAB = 'kit';

    /**
     * The spec an unnamed /pvp-guides lands on when it exists (2026-09-07, direct request:
     * "make the default starting class sub rogue"). Subtlety Rogue is a stable top-meta 3v3 spec
     * that has both an analysed playstyle sample and a burst guide on file, so every tab lands
     * populated. Falls back to firstSpecWithData() -> firstWowSpec() if this spec isn't seeded.
     */
    public const DEFAULT_CLASS_SLUG = 'rogue';

    public const DEFAULT_SPEC_SLUG = 'subtlety';

    public string $classSlug;

    public string $specSlug;

    /**
     * Carried in the query string (?tab=spells) so a tab is linkable and survives the redirect a
     * spec change performs. `except` keeps the default tab out of the URL entirely, so the
     * canonical link to a spec's guide stays clean.
     */
    #[Url(except: self::DEFAULT_TAB)]
    public string $tab = self::DEFAULT_TAB;

    public function mount(?string $classSlug = null, ?string $specSlug = null): void
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = self::DEFAULT_TAB;
        }

        if (! $classSlug || ! $specSlug) {
            // Prefer Subtlety Rogue (see DEFAULT_CLASS_SLUG/DEFAULT_SPEC_SLUG), then fall back to
            // "first spec that actually has an analysed match sample + a burst guide", then to the
            // first WoW spec of any kind - so a first-time visitor always lands on a populated page
            // rather than on whichever spec happens to sort first alphabetically.
            $default = $this->preferredDefaultSpec()
                ?? $this->firstSpecWithData()
                ?? $this->firstWowSpec();

            abort_if($default === null, 404, 'No WoW specs available.');

            $this->redirectRoute('pvp-guides', [
                'classSlug' => $default->gameClass->slug,
                'specSlug' => $default->slug,
                'tab' => $this->tab === self::DEFAULT_TAB ? null : $this->tab,
            ], navigate: true);

            return;
        }

        $this->classSlug = $classSlug;
        $this->specSlug = $specSlug;

        abort_unless($this->spec, 404, "Unknown spec {$classSlug}/{$specSlug}.");

        // Attributed on landing, unlike SpellExplorer's bare log: this page's URL names a real
        // class/spec, so every view of it IS a view of that spec - there is no "landed on the
        // alphabetically-first class by default" case to guard against here, since the only
        // unnamed entry point redirects above before ever reaching this line.
        PageViewEvent::log('pvp_guides', $this->class?->id, $this->spec?->id);
    }

    /* ------------------------------------------------------------------ */

    public function getClassProperty(): ?GameClass
    {
        return GameClass::where('slug', $this->classSlug)->first();
    }

    public function getSpecProperty(): ?Specialization
    {
        return $this->class
            ? Specialization::where('class_id', $this->class->id)->where('slug', $this->specSlug)->first()
            : null;
    }

    /** Every WoW class with its specs, for the picker modal. @return Collection<int, GameClass> */
    public function getClassSpecsProperty(): Collection
    {
        return GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->with(['specializations' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Which tabs have real data for this spec, so a tab that would open onto an empty panel is
     * marked before it is clicked rather than silently disappointing. Cheap file-existence checks
     * only - never the panel's own computation, which is the whole reason the panels are lazy.
     *
     * 'spells' and 'counters' are always true: every spec has a talent tree, and the counters
     * panel is class-scoped rather than spec-scoped, so both always have something to show.
     */
    public function getTabHasDataProperty(): array
    {
        return [
            'kit' => File::exists(base_path("data/arena-logs/playstyle/{$this->classSlug}/{$this->specSlug}.json")),
            'burst' => File::exists(base_path("data/claudes-guides/burst-guides/{$this->classSlug}/{$this->specSlug}.json")),
            'spells' => true,
            'counters' => true,
        ];
    }

    /* ------------------------------------------------------------------ */

    public function selectTab(string $tab): void
    {
        if (! isset(self::TABS[$tab]) || $tab === $this->tab) {
            return;
        }

        $this->tab = $tab;

        // Same shape as WowComps' tab tracking (TrackController::wowCompsTab()): the tab name
        // rides in `slot` under its OWN page slug, partitioned from real 'pvp_guides' rows so it
        // can never be mistaken for a page view or inflate the selection count. Deliberately not
        // a PAGES entry - see Admin\PageUsage::PVP_GUIDES_TAB_LABELS. Logged only on a real
        // switch INTO a tab, never on a re-click and never for the tab landed on at page load.
        PageViewEvent::log('pvp_guides_tab', $this->class?->id, $this->spec?->id, slot: $tab);
    }

    /**
     * The picker modal's click target - the same single-call signature
     * SpellExplorer::selectSpec() and WowComps::selectSpec() already use, so the shared picker
     * markup behaves identically here. Redirects rather than mutating in place; see the class
     * docblock for why. A class/spec pair that does not actually match is ignored rather than
     * redirected to a 404.
     */
    public function selectSpec(int $classId, int $specId): void
    {
        $spec = Specialization::with('gameClass')
            ->where('id', $specId)
            ->where('class_id', $classId)
            ->first();

        if (! $spec || ! $spec->gameClass) {
            return;
        }

        $this->redirectRoute('pvp-guides', [
            'classSlug' => $spec->gameClass->slug,
            'specSlug' => $spec->slug,
            'tab' => $this->tab === self::DEFAULT_TAB ? null : $this->tab,
        ], navigate: true);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The configured preferred landing spec (Subtlety Rogue), or null if it isn't seeded. Kept
     * separate from firstSpecWithData() so the "which spec has data" fallback still exists if the
     * preferred spec is ever removed or renamed.
     */
    private function preferredDefaultSpec(): ?Specialization
    {
        return Specialization::with('gameClass')
            ->whereHas('gameClass', fn ($q) => $q->where('slug', self::DEFAULT_CLASS_SLUG))
            ->where('slug', self::DEFAULT_SPEC_SLUG)
            ->first();
    }

    /**
     * Fallback for when the preferred default spec (see preferredDefaultSpec()) isn't seeded.
     * Prefers a spec with BOTH an analysed playstyle sample and a burst guide on file, so a
     * first-time visitor still opens on a page where every tab has something in it.
     *
     * ClassGuide's own same-named default only requires a playstyle file, and requiring both
     * here is a deliberate difference rather than an oversight: with four tabs sharing one
     * landing, the first impression is of all four at once.
     */
    private function firstSpecWithData(): ?Specialization
    {
        $fallback = null;

        foreach (glob(base_path('data/arena-logs/playstyle/*/*.json')) as $path) {
            $classSlug = basename(dirname($path));
            $specSlug = basename($path, '.json');

            $spec = Specialization::with('gameClass')
                ->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug))
                ->where('slug', $specSlug)
                ->first();

            if (! $spec) {
                continue;
            }

            if (File::exists(base_path("data/claudes-guides/burst-guides/{$classSlug}/{$specSlug}.json"))) {
                return $spec;
            }

            $fallback ??= $spec;
        }

        return $fallback;
    }

    private function firstWowSpec(): ?Specialization
    {
        return Specialization::with('gameClass')
            ->whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')
            ->first();
    }

    public function render()
    {
        $name = $this->spec && $this->class ? "{$this->spec->name} {$this->class->name}" : 'Class Guides';
        $tabLabel = self::TABS[$this->tab]['label'] ?? '';

        return view('livewire.pvp-guides', [
            'class' => $this->class,
            'spec' => $this->spec,
            'classSpecs' => $this->classSpecs,
            'tabs' => self::TABS,
            'tabHasData' => $this->tabHasData,
        ])->layout('layouts.app', [
            'title' => "{$name} Class Guide - {$tabLabel} | MindCollector",
            'description' => "{$name} arena guide: the talents top players run, how to line up your burst, your full spell kit with build-adjusted cooldowns, and what counters your crowd control.",
        ]);
    }
}
