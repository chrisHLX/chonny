<?php

namespace App\Livewire\Modules;

use Livewire\Component;
use App\Models\Module;
use App\Models\Pipeline;
use App\Models\UserAxisMastery;
use App\Models\UserConceptMastery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Show extends Component
{
    public Module $module;
    public bool $enrolled = false;
    public ?array $userModule = null;
    public ?int $activePipelineId = null;

    public function mount(Module $module): void
    {
        if (!$module->published && !Auth::check()) {
            abort(404);
        }

        $this->module = $module;
        $this->refreshEnrollment();

        $this->activePipelineId = Pipeline::where('module_id', $module->id)
            ->whereIn('status', ['running', 'pending'])
            ->latest('id')
            ->first()?->id;
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
