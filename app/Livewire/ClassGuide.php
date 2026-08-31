<?php

namespace App\Livewire;

use App\Http\Services\ArenaLogService;
use App\Models\GameClass;
use App\Models\PageViewEvent;
use App\Models\Patch;
use App\Models\Spell;
use App\Models\Specialization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Component;

/**
 * "How this spec plays" — the reading view over the playstyle-analysis data layer (see
 * app/Http/Services/playstyle-analysis.md). Pulls together, for one class/spec:
 *   - the talent-convergence roll-up from data/arena-logs/playstyle/{class}/{spec}.json
 *     (how many of the sampled top-rated players took each talent, and how many actually
 *     got value from it — the core-pick vs common-mispick signal)
 *   - the peak burst window from ArenaLogService::rotationForSpec()
 *
 * Read-only, public, no auth. The talent *grid* itself lives on the sibling BurstWindowTalents
 * page (the read-only talent calculator) — this page links to it rather than re-rendering it.
 *
 * Only specs that have been through `wow:analyze-spec-playstyle` have the playstyle file; the
 * page degrades to "burst window only" (and a note) for the rest rather than 404ing.
 *
 * The "situational"/"rarely paid off" band and the buff/proc web (both computed from the same
 * playstyle file) were removed from this page 2026-09-01, direct instruction — the situational
 * band's framing ("a habit worth questioning") didn't fit a fundamentally reactive/conditional
 * pick like Evasion, where "didn't need it this game" is the correct outcome, not a mistake; see
 * playstyle-analysis.md for what the underlying data still supports if a better framing for it
 * is designed later. getTalentBandsProperty() still computes a 'situational' key internally
 * (harmless, unused) — only this page's rendering of it was removed, not the data layer.
 */
class ClassGuide extends Component
{
    public string $classSlug;

    public string $specSlug;

    public function mount(?string $classSlug = null, ?string $specSlug = null): void
    {
        if (! $classSlug || ! $specSlug) {
            $default = $this->firstSpecWithData() ?? $this->firstWowSpec();

            if (! $default) {
                abort(404, 'No WoW specs available.');
            }

            $this->redirectRoute('class-guide', [
                'classSlug' => $default->gameClass->slug,
                'specSlug' => $default->slug,
            ], navigate: true);

            return;
        }

        $this->classSlug = $classSlug;
        $this->specSlug = $specSlug;

        abort_unless($this->spec, 404, "Unknown spec {$classSlug}/{$specSlug}.");

        PageViewEvent::log('class_guide', $this->class?->id, $this->spec?->id);
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

    /** Every WoW class + its specs, for the picker. @return Collection<int, GameClass> */
    public function getPickerProperty(): Collection
    {
        return GameClass::whereHas('game', fn ($q) => $q->where('slug', 'wow'))
            ->with(['specializations' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')->get();
    }

    /** The promoted playstyle file for this spec, or null. */
    public function getPlaystyleProperty(): ?array
    {
        $path = base_path("data/arena-logs/playstyle/{$this->classSlug}/{$this->specSlug}.json");

        return File::exists($path) ? (json_decode(File::get($path), true) ?: null) : null;
    }

    /**
     * The talentSummary rows split into reading bands + hydrated with a Spell (icon, tree) and
     * the single most common verdict. Bands:
     *   core        — taken by (almost) everyone AND used by (almost) everyone
     *   situational — taken often but flagged (no benefit seen) in a meaningful share of games
     *   rest        — everything else that was taken by a real chunk of the sample
     *
     * @return array{sample:int, core:array, situational:array, rest:array}
     */
    public function getTalentBandsProperty(): array
    {
        $ps = $this->playstyle;

        if (! $ps) {
            return ['sample' => 0, 'core' => [], 'situational' => [], 'rest' => []];
        }

        $sample = (int) $ps['sampleSize'];
        $rows = collect($ps['talentSummary']);

        // Hydrate names -> Spell (for icon + tree), via each talent's spellId in match analyses.
        $spellIdByTalent = collect($ps['matches'])
            ->flatMap(fn ($m) => $m['talentAnalysis'])
            ->reject(fn ($r) => empty($r['spellId']))
            ->groupBy('talent')->map(fn ($g) => $g->first()['spellId']);

        $sourceByTalent = collect($ps['matches'])
            ->flatMap(fn ($m) => $m['talentAnalysis'])
            ->groupBy('talent')->map(fn ($g) => $g->first()['source']);

        $spells = Spell::where('patch_id', Patch::where('is_current', true)->value('id'))
            ->whereIn('spell_id', $spellIdByTalent->values()->unique())
            ->get()->keyBy('spell_id');

        $hydrate = fn ($r) => [
            'talent' => $r['talent'],
            'took' => $r['took'],
            'used' => $r['used'],
            'flagged' => $r['flagged'],
            'passive' => $r['passive'],
            'source' => $sourceByTalent[$r['talent']] ?? 'talent',
            'topVerdict' => collect($r['verdicts'])->sortDesc()->keys()->first(),
            'spell' => $spells->get($spellIdByTalent[$r['talent']] ?? null),
        ];

        $near = max(1, (int) ceil($sample * 0.8));

        $core = $rows->filter(fn ($r) => $r['took'] >= $near && $r['used'] >= $near)
            ->map($hydrate)->sortByDesc('used')->values()->all();

        $coreNames = collect($core)->pluck('talent')->flip();

        $situational = $rows
            ->reject(fn ($r) => $coreNames->has($r['talent']))
            ->filter(fn ($r) => $r['took'] >= max(2, $sample * 0.4) && $r['flagged'] >= max(2, $r['took'] * 0.5))
            ->map($hydrate)->sortByDesc('flagged')->values()->all();

        $shown = collect($core)->pluck('talent')->merge(collect($situational)->pluck('talent'))->flip();

        $rest = $rows
            ->reject(fn ($r) => $shown->has($r['talent']))
            ->filter(fn ($r) => $r['took'] >= max(2, $sample * 0.5))
            ->map($hydrate)->sortByDesc('took')->values()->all();

        return compact('sample', 'core', 'situational', 'rest');
    }

    /**
     * The 12s burst window, with each step's spellId resolved to a real Spell (via
     * ArenaLogService::resolveWindowSteps() — see that method's docblock) so the page can render
     * the patch-data `displayName` instead of the step's raw `name`, which is in whichever locale
     * the source match was logged in (confirmed real: a zh-CN-logged match leaves Chinese step
     * names on an otherwise-English page). Same resolution TopDamageRotations/WowComps already
     * apply to their own burst-window steps — this page just wasn't wired up to it yet.
     */
    public function getBurstWindowProperty(): ?array
    {
        $rotation = app(ArenaLogService::class)->rotationForSpec($this->classSlug, $this->specSlug);

        if ($rotation === null || empty($rotation['topDpsWindow']['steps']) || ! $this->spec) {
            return $rotation;
        }

        $rotation['topDpsWindow']['steps'] = app(ArenaLogService::class)->resolveWindowSteps(
            $rotation['topDpsWindow']['steps'],
            $this->spec->id,
            app(\App\Http\Services\TalentSelectionService::class)
        );

        return $rotation;
    }

    /* ------------------------------------------------------------------ */

    private function firstSpecWithData(): ?Specialization
    {
        foreach (glob(base_path('data/arena-logs/playstyle/*/*.json')) as $path) {
            $specSlug = basename($path, '.json');
            $classSlug = basename(dirname($path));
            $spec = Specialization::with('gameClass')
                ->whereHas('gameClass', fn ($q) => $q->where('slug', $classSlug))
                ->where('slug', $specSlug)->first();

            if ($spec) {
                return $spec;
            }
        }

        return null;
    }

    private function firstWowSpec(): ?Specialization
    {
        return Specialization::with('gameClass')
            ->whereHas('gameClass.game', fn ($q) => $q->where('slug', 'wow'))
            ->orderBy('name')->first();
    }

    public function render()
    {
        $title = $this->spec && $this->class
            ? "{$this->spec->name} {$this->class->name} — Class Guide"
            : 'Class Guide';

        return view('livewire.class-guide', [
            'class' => $this->class,
            'spec' => $this->spec,
            'playstyle' => $this->playstyle,
            'bands' => $this->talentBands,
            'burst' => $this->burstWindow,
            'picker' => $this->picker,
        ])->layout('layouts.app', [
            'title' => "{$title} | MindCollector",
            'description' => 'How a WoW arena spec actually plays — the talents top-rated players converge on, which of them earn their slot, and the burst window, all from real archived matches.',
        ]);
    }
}
