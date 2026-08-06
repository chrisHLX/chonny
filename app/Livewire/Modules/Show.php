<?php

namespace App\Livewire\Modules;

use Livewire\Component;
use App\Http\Services\ModuleSpellReferenceService;
use App\Http\Services\TalentSelectionService;
use App\Models\Module;
use App\Models\Pipeline;
use App\Models\Spell;
use App\Models\SubjectContent;
use App\Models\TalentBuild;
use App\Models\UserAxisMastery;
use App\Models\UserConceptMastery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Show extends Component
{
    public Module $module;
    public bool $enrolled = false;
    public ?array $userModule = null;
    public ?int $activePipelineId = null;

    /**
     * Which talents are currently selected — feeds getModuleSpellReferencesProperty()'s
     * effective-cooldown/charges computation. Seeded once from the resolved active build on
     * mount and static for the page load (a saved build is a cross-module preference, edited
     * elsewhere — see TalentSelectionService — not something this page has a live picker for
     * as of 2026-08-01, when the embedded <livewire:talent-selector> was removed). The
     * 'talents-changed' listener that used to keep this reactive was removed alongside it —
     * nothing on this page dispatches that event anymore.
     */
    public array $selectedSpellIds = [];

    /** spell_id => rank, the counterpart to $selectedSpellIds for multi-rank talents whose magnitude differs per rank — see TalentSelectionService::selectedRanks(). */
    public array $selectedRanks = [];

    /**
     * The TalentBuild resolved alongside $selectedSpellIds (see initSelectedSpellIds()) — kept
     * as just the id (Livewire-serializable, same pattern as $activePipelineId above) so
     * getModuleSpellReferencesProperty() can look up TalentSelectionService::resolvedDescriptionsFor()
     * without re-resolving which build applies a second time.
     */
    public ?int $resolvedTalentBuildId = null;

    public function mount(Module $module): void
    {
        // A guest hitting an unpublished module (e.g. the "View Module" link in
        // NextStepModuleAssigned — those modules start unpublished by default) used to hard-404
        // here, unlike ModuleQuizController::show()'s equivalent guest guard, which correctly
        // sends them to log in instead. An assigned recipient clicking the email link from a
        // logged-out browser would see a dead link with no path forward. Remember the intended
        // URL (same mechanism Laravel's own `redirect()->guest()` uses) so login sends them back
        // here, matching AuthenticatedSessionController::store()'s redirect()->intended() call.
        if (!$module->published && !Auth::check()) {
            session()->put('url.intended', request()->fullUrl());
            $this->redirect(route('login'));
            return;
        }

        $this->module = $module;
        $this->refreshEnrollment();

        $this->activePipelineId = Pipeline::where('module_id', $module->id)
            ->whereIn('status', ['running', 'pending'])
            ->latest('id')
            ->first()?->id;

        $this->initSelectedSpellIds();
    }

    /**
     * Seeds $selectedSpellIds so the page already reflects the right talents with no picker on
     * this page anymore (see the 2026-08-01 removal note above `render()`). Resolution order:
     * this module's own linked build (TalentSelectionService::resolveBuildForModule() — a
     * content author's deliberate choice for this specific guide, e.g. imported from a real
     * Blizzard export string) → the viewer's own saved build → the spec's admin default → empty.
     * No-op for modules with no ModuleGameBuild.
     */
    private function initSelectedSpellIds(): void
    {
        $build = $this->module->gameBuild;

        if (!$build) {
            return;
        }

        $service = app(TalentSelectionService::class);

        $moduleBuild = $service->resolveBuildForModule($this->module);
        if ($moduleBuild) {
            $this->selectedSpellIds = $service->selectedSpellIds($moduleBuild)->all();
            $this->selectedRanks = $service->selectedRanks($moduleBuild)->all();
            $this->resolvedTalentBuildId = $moduleBuild->id;

            return;
        }

        $activeBuild = $service->resolveActiveBuild(Auth::user(), $build->specialization_id);
        $this->selectedSpellIds = $service->selectedSpellIds($activeBuild)->all();
        $this->selectedRanks = $service->selectedRanks($activeBuild)->all();
        $this->resolvedTalentBuildId = $activeBuild->exists ? $activeBuild->id : null;
    }

    public function render()
    {
        $this->module->loadMissing([
            'subject.category.axes.concepts',
            'subject.category.axes',
            'subject.concepts',
            'questions.concepts',
            'modulePages',
            'proficiencies',
            'tags',
            'creator',
            'gameBuild.gameClass',
            'gameBuild.specialization',
            'gameBuild.heroTalentTree',
        ]);

        return view('livewire.modules.show')
            ->layout('layouts.app');
    }

    protected function refreshEnrollment(): void
    {
        if (!Auth::check()) {
            $this->enrolled = false;
            $this->userModule = null;
            return;
        }

        $pivot = $this->module->users()
            ->where('user_id', Auth::id())
            ->first();

        $this->enrolled = (bool) $pivot;
        $this->userModule = $pivot ? [
            'status'     => $pivot->pivot->status,
            'score'      => (int) ($pivot->pivot->score ?? 0),
            'difficulty' => $pivot->pivot->current_difficulty,
        ] : null;
    }

    public function enroll(): void
    {
        if (!Auth::check()) {
            $this->redirect(route('login'));
            return;
        }

        if (!$this->enrolled) {
            Auth::user()->modules()->attach($this->module->id, [
                'status'             => 'not_started',
                'current_difficulty' => 'easy',
                'last_activity_at'   => now(),
            ]);
        }

        $this->refreshEnrollment();
    }

    public function retake(): void
    {
        if (!Auth::check() || !$this->enrolled || ($this->userModule['status'] ?? '') !== 'completed') {
            return;
        }

        Auth::user()->modules()->updateExistingPivot($this->module->id, [
            'retake_started_at' => now(),
            'retake_count'      => DB::raw('retake_count + 1'),
            'status'            => 'in_progress',
            'completed_at'      => null,
        ]);

        $this->redirect(route('questions.quiz.index') . '?moduleId=' . $this->module->id);
    }

    // Axis mastery: one entry per axis in the category, mastery from DB if enrolled
    public function getRadarDataProperty(): array
    {
        $axes = $this->module->subject->category->axes;
        if ($axes->isEmpty()) {
            return [];
        }

        $totalQuestions = $this->module->questions->count();
        if ($totalQuestions === 0) {
            return $axes->map(fn ($a) => [
                'name'    => $a->name,
                'mastery' => 0,
            ])->values()->toArray();
        }

        // Build a map of concept_id → axis_ids
        $conceptAxisMap = [];
        foreach ($axes as $axis) {
            foreach ($axis->concepts as $concept) {
                $conceptAxisMap[$concept->id][] = $axis->id;
            }
        }

        // Count questions touching each axis
        $axisCounts = $axes->mapWithKeys(fn ($a) => [$a->id => 0])->toArray();
        foreach ($this->module->questions as $q) {
            $seenAxes = [];
            foreach ($q->concepts as $concept) {
                foreach ($conceptAxisMap[$concept->id] ?? [] as $axisId) {
                    if (!in_array($axisId, $seenAxes) && array_key_exists($axisId, $axisCounts)) {
                        $axisCounts[$axisId]++;
                        $seenAxes[] = $axisId;
                    }
                }
            }
        }

        return $axes->map(fn ($a) => [
            'name'    => $a->name,
            'mastery' => round(($axisCounts[$a->id] / $totalQuestions) * 100, 1),
        ])->values()->toArray();
    }

    public function getConceptMasteryProperty(): array
    {
        $totalQuestions = $this->module->questions->count();

        $conceptIds = $this->module->questions
            ->flatMap(fn ($q) => $q->concepts->pluck('id'))
            ->unique()
            ->values();

        if ($conceptIds->isEmpty() || $totalQuestions === 0) {
            return [];
        }

        // Count questions per concept
        $conceptCounts = $conceptIds->mapWithKeys(fn ($id) => [$id => 0])->toArray();
        foreach ($this->module->questions as $q) {
            foreach ($q->concepts as $concept) {
                if (array_key_exists($concept->id, $conceptCounts)) {
                    $conceptCounts[$concept->id]++;
                }
            }
        }

        return $this->module->subject->concepts
            ->whereIn('id', $conceptIds->all())
            ->map(fn ($c) => [
                'name'    => $c->name,
                'mastery' => round(($conceptCounts[$c->id] / $totalQuestions) * 100, 1),
            ])
            ->sortByDesc('mastery')
            ->values()
            ->toArray();
    }

    // All SVG geometry for the radar chart, computed from radarData
    public function getSvgDataProperty(): array
    {
        $radarData = $this->radarData;
        $n = count($radarData);

        if ($n < 3) {
            return ['hasChart' => false];
        }

        $cx = 200;
        $cy = 200;
        $r  = 125;
        $lr = 162;

        $toAttr = fn (array $pts) => collect($pts)
            ->map(fn ($p) => round($p[0], 2) . ',' . round($p[1], 2))
            ->join(' ');

        // Concentric grid rings at 25/50/75/100%
        $gridRings = [];
        foreach ([0.25, 0.5, 0.75, 1.0] as $frac) {
            $pts = [];
            for ($i = 0; $i < $n; $i++) {
                $a    = -M_PI / 2 + $i * 2 * M_PI / $n;
                $pts[] = [$cx + $r * $frac * cos($a), $cy + $r * $frac * sin($a)];
            }
            $gridRings[] = $toAttr($pts);
        }

        // Spoke endpoints (outer ring vertices)
        $spokeEnds = [];
        for ($i = 0; $i < $n; $i++) {
            $a         = -M_PI / 2 + $i * 2 * M_PI / $n;
            $spokeEnds[] = [round($cx + $r * cos($a), 2), round($cy + $r * sin($a), 2)];
        }

        // User mastery polygon — small minimum so the shape is always visible
        $userPts = [];
        foreach ($radarData as $i => $data) {
            $a       = -M_PI / 2 + $i * 2 * M_PI / $n;
            $pct     = max(0.03, $data['mastery'] / 100);
            $userPts[] = [$cx + $r * $pct * cos($a), $cy + $r * $pct * sin($a)];
        }

        // Dot marker positions as associative arrays for blade
        $dots = array_map(
            fn ($p) => ['x' => round($p[0], 2), 'y' => round($p[1], 2)],
            $userPts
        );

        // Axis name labels, positioned just beyond the outer ring
        $labels = [];
        foreach ($radarData as $i => $data) {
            $a      = -M_PI / 2 + $i * 2 * M_PI / $n;
            $lx     = round($cx + $lr * cos($a), 2);
            $ly     = round($cy + $lr * sin($a), 2);
            $anchor = 'middle';
            if ($lx < $cx - 15) {
                $anchor = 'end';
            } elseif ($lx > $cx + 15) {
                $anchor = 'start';
            }
            $labels[] = [
                'x'       => $lx,
                'y'       => $ly,
                'name'    => $data['name'],
                'mastery' => round($data['mastery']),
                'anchor'  => $anchor,
            ];
        }

        // Small percentage labels placed just right of the top spoke
        $ringLabels = [];
        foreach ([0.25, 0.5, 0.75, 1.0] as $frac) {
            $a = -M_PI / 2 + 0.12;
            $ringLabels[] = [
                'x'    => round($cx + $r * $frac * cos($a) + 3, 2),
                'y'    => round($cy + $r * $frac * sin($a) - 3, 2),
                'text' => (int) ($frac * 100) . '%',
            ];
        }

        return [
            'hasChart'      => true,
            'cx'            => $cx,
            'cy'            => $cy,
            'gridRings'     => $gridRings,
            'spokeEnds'     => $spokeEnds,
            'userPointsStr' => $toAttr($userPts),
            'dots'          => $dots,
            'labels'        => $labels,
            'ringLabels'    => $ringLabels,
        ];
    }

    // All ModulePages rendered as HTML, sorted by page_number
    public function getAllPagesHtmlProperty(): array
    {
        return $this->module->modulePages
            ->sortBy('page_number')
            ->filter(fn ($p) => $p->content)
            ->map(fn ($p) => [
                'id'          => $p->id,
                'title'       => $p->title ?: 'Page ' . $p->page_number,
                'page_number' => $p->page_number,
                'html'        => Str::markdown($p->content, [
                    'html_input'         => 'strip',
                    'allow_unsafe_links' => false,
                ]),
            ])
            ->values()
            ->toArray();
    }

    /**
     * The module's curated "Spells" reference section, if this module has a declared
     * ModuleGameBuild (see DiscPriestOracleModuleSeeder). Always computed live from whatever's
     * currently in spells/spell_relationships — never a stored snapshot, so it can't go stale
     * the way frozen prose could when a patch changes a number (see ModuleSpellReferenceService).
     *
     * 'modifiers'/'cooldown'/'charges' are all gated by $selectedSpellIds (see
     * TalentSelectionService) — a talent that's merely possible for the class/spec but not
     * currently selected no longer shows as an applying modifier or changes the computed
     * cooldown/charge count.
     *
     * 'isSelected' (added 2026-08-06) — false for a CHOICE-node sibling shown alongside the
     * actually-selected option (see TalentSelectionService::choiceSiblingSpellIds()); the blade
     * uses it to grey the entry out with a "not selected" note, never to affect the cooldown/
     * modifier computation above, which already only ever reflects the true $selectedSpellIds.
     *
     * @return array<int, array{spell: \App\Models\Spell, category: string, description: array{text: string, uncertain: bool}, formulaModifiers: \Illuminate\Support\Collection, isSelected: bool, modifiers: array{named: \Illuminate\Support\Collection, baseline: \Illuminate\Support\Collection}, cooldown: array{seconds: ?float, base_seconds: ?float, applied: \Illuminate\Support\Collection}, charges: array{charges: ?int, base_charges: ?int, applied: \Illuminate\Support\Collection}}>
     */
    public function getModuleSpellReferencesProperty(): array
    {
        $build = $this->module->gameBuild;

        if (!$build) {
            return [];
        }

        $service = new ModuleSpellReferenceService();
        $talentService = app(TalentSelectionService::class);
        $selected = collect($this->selectedSpellIds);
        $ranks = collect($this->selectedRanks);

        // The "road not taken" for any CHOICE-node talent this build has a pick for — e.g. a
        // module discussing Ultimate Penitence also shows Power Word: Barrier alongside it,
        // greyed out. Display-only, never merged into $selected — see
        // TalentSelectionService::choiceSiblingSpellIds()'s docblock for why that separation
        // matters for modifier correctness. A module's curated `module_spell_references` list is
        // still the base set (an author's own choice of what to reference); this only adds to
        // it, never removes or overrides.
        $curatedIds = $this->module->spellReferences()->pluck('spells.id');
        $siblingIds = $talentService->choiceSiblingSpellIds($selected);
        $displayIds = $curatedIds->merge($siblingIds)->unique();

        // Real, resolved text captured from the exact character export this build was decoded
        // from (see TalentSelectionService::resolvedDescriptionsFor()) — preferred over the
        // template resolver's output when available, since it has real numbers instead of
        // "varies by condition" for anything depending on the caster's own stats. Empty
        // collection (and therefore a no-op below) for the vast majority of builds, which have
        // no linked snapshot.
        $resolvedDescriptions = $this->resolvedTalentBuildId
            ? $talentService->resolvedDescriptionsFor(TalentBuild::find($this->resolvedTalentBuildId))
            : collect();

        return Spell::whereIn('id', $displayIds)
            ->with(['effects', 'incomingRelationships.sourceSpell.effects'])
            ->orderBy('name')
            ->get()
            ->map(function ($spell) use ($service, $build, $selected, $ranks, $resolvedDescriptions) {
                $description = $resolvedDescriptions->has($spell->spell_id)
                    ? ['text' => $resolvedDescriptions->get($spell->spell_id), 'uncertain' => false]
                    : $service->resolveDescription($spell, $build);

                return [
                    'spell' => $spell,
                    'category' => $service->categorize($spell),
                    'description' => $description,
                    // Only worth computing when the description couldn't be fully resolved to a
                    // real number — a snapshot-backed or cleanly-computed description already
                    // has the real answer, nothing to add. See variablesModifiers()'s docblock.
                    'formulaModifiers' => $description['uncertain'] ? $service->variablesModifiers($spell) : collect(),
                    'isSelected' => $selected->contains($spell->id),
                    'modifiers' => $service->modifiersFor($spell, $build, $selected, $ranks),
                    'cooldown' => $service->effectiveCooldown($spell, $build, $selected, $ranks),
                    'charges' => $service->effectiveCharges($spell, $build, $selected, $ranks),
                ];
            })
            ->all();
    }

    public function getSubjectResearchProperty(): \Illuminate\Support\Collection
    {
        return SubjectContent::where('subject_id', $this->module->subject_id)
            ->latest()
            ->take(5)
            ->get();
    }

    // Question count per skill type
    public function getSkillTypeCountsProperty(): array
    {
        $counts = ['recall' => 0, 'analysis' => 0, 'application' => 0];
        foreach ($this->module->questions as $q) {
            $type = $q->skill_type?->value ?? 'recall';
            if (array_key_exists($type, $counts)) {
                $counts[$type]++;
            }
        }
        return $counts;
    }
}
