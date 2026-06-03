<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Module;
use App\Models\Subject;
use App\Models\Category;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QuizSelection extends Component
{
    public $subjects = [];
    public $selectedModule;
    public $selectedSubject = null;
    public $category_id;
    public ?array $selectedModuleData = null;
    public ?Module $selectedModuleModel = null;

    public function mount()
    {
        $this->category_id = request()->query('category_id');

        if(!$this->category_id) {
            $this->category_id = Category::first()?->id;
        }

        $this->subjects = Subject::when($this->category_id, function($query, $catId) {
            $query->where('category_id', $catId);
        })->get();

        $this->selectedSubject = $this->subjects->first()?->id;

        // Prefer a moduleId from the URL (set when arriving via Resume button)
        $preselectedId = (int) request()->query('moduleId') ?: null;
        if ($preselectedId) {
            $preselectedModule = Module::find($preselectedId);
            if ($preselectedModule) {
                $this->selectedSubject = $preselectedModule->subject_id;
                $this->subjects = Subject::when($this->category_id, function($query, $catId) {
                    $query->where('category_id', $catId);
                })->get();
                $this->selectedModule = $preselectedId;
            }
        } else {
            $this->selectedModule = $this->modules->first()?->id;
        }

        $this->loadSelectedModuleData();
    }

    public function updatedSelectedModule(): void
    {
        $this->loadSelectedModuleData();
    }

    

    public function getModulesProperty()
    {
        $subjectIds = $this->subjects->pluck('id');

        return auth()->user()
            ->modules()
            ->with('proficiencies')
            ->withPivot(['status'])
            ->whereIn('subject_id', $subjectIds)
            ->when($this->selectedSubject, fn($q) =>
                $q->where('subject_id', $this->selectedSubject)
            )
            ->get();
    }

    private function loadSelectedModuleData(): void
    {
        if (!$this->selectedModule) {
            $this->selectedModuleData = null;
            $this->selectedModuleModel = null;
            return;
        }

        $module = Module::with(['questions.concepts', 'subject.concepts', 'subject.category.axes.concepts', 'modulePages'])->find($this->selectedModule);

        if (!$module) {
            $this->selectedModuleData = null;
            $this->selectedModuleModel = null;
            return;
        }

        $this->selectedModuleModel = $module;

        $total = $module->questions->count();

        // Skill type counts
        $skillCounts = ['recall' => 0, 'analysis' => 0, 'application' => 0];
        foreach ($module->questions as $q) {
            $type = $q->skill_type?->value ?? 'recall';
            if (array_key_exists($type, $skillCounts)) {
                $skillCounts[$type]++;
            }
        }

        // Concept distribution: questions per concept as % of total
        $conceptIds = $module->questions
            ->flatMap(fn($q) => $q->concepts->pluck('id'))
            ->unique()
            ->values();

        $conceptCounts = $conceptIds->mapWithKeys(fn($id) => [$id => 0])->toArray();
        foreach ($module->questions as $q) {
            foreach ($q->concepts as $concept) {
                if (array_key_exists($concept->id, $conceptCounts)) {
                    $conceptCounts[$concept->id]++;
                }
            }
        }

        $concepts = $module->subject->concepts
            ->whereIn('id', $conceptIds->all())
            ->map(fn($c) => [
                'name'     => $c->name,
                'coverage' => $total > 0 ? round(($conceptCounts[$c->id] / $total) * 100, 1) : 0,
                'count'    => $conceptCounts[$c->id],
            ])
            ->sortByDesc('coverage')
            ->values()
            ->toArray();

        $pages = $module->modulePages
            ->sortBy('page_number')
            ->filter(fn ($p) => $p->content)
            ->map(fn ($p) => [
                'title' => $p->title ?: 'Page ' . $p->page_number,
                'html'  => Str::markdown($p->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ])
            ->values()
            ->toArray();

        $this->selectedModuleData = [
            'total'        => $total,
            'skill_counts' => $skillCounts,
            'concepts'     => $concepts,
            'pages'        => $pages,
        ];
    }

    public function getSelectedModuleAxisDataProperty(): array
    {
        if (!$this->selectedModuleModel) {
            return [];
        }

        $module = $this->selectedModuleModel;
        $axes = $module->subject->category->axes;

        if ($axes->isEmpty()) {
            return [];
        }

        $totalQuestions = $module->questions->count();
        if ($totalQuestions === 0) {
            return [];
        }

        $conceptAxisMap = [];
        foreach ($axes as $axis) {
            foreach ($axis->concepts as $concept) {
                $conceptAxisMap[$concept->id][] = $axis->id;
            }
        }

        $axisCounts = $axes->mapWithKeys(fn($a) => [$a->id => 0])->toArray();
        foreach ($module->questions as $q) {
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

        return $axes->map(fn($a) => [
            'name'     => $a->name,
            'coverage' => round(($axisCounts[$a->id] / $totalQuestions) * 100, 1),
        ])->values()->toArray();
    }

    public function getSelectedModuleRadarSvgProperty(): array
    {
        $axisData = $this->selectedModuleAxisData;
        $n = count($axisData);

        if ($n < 3) {
            return ['hasChart' => false];
        }

        $cx = 100;
        $cy = 100;
        $r  = 75;
        $lr = 110;

        $toAttr = fn(array $pts) => collect($pts)
            ->map(fn($p) => round($p[0], 2) . ',' . round($p[1], 2))
            ->join(' ');

        $gridRings = [];
        foreach ([0.25, 0.5, 0.75, 1.0] as $frac) {
            $pts = [];
            for ($i = 0; $i < $n; $i++) {
                $a     = -M_PI / 2 + $i * 2 * M_PI / $n;
                $pts[] = [$cx + $r * $frac * cos($a), $cy + $r * $frac * sin($a)];
            }
            $gridRings[] = $toAttr($pts);
        }

        $spokeEnds = [];
        for ($i = 0; $i < $n; $i++) {
            $a           = -M_PI / 2 + $i * 2 * M_PI / $n;
            $spokeEnds[] = [round($cx + $r * cos($a), 2), round($cy + $r * sin($a), 2)];
        }

        $labels = [];
        foreach ($axisData as $i => $data) {
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
                'x'      => $lx,
                'y'      => $ly,
                'name'   => strtoupper(substr($data['name'], 0, 3)),
                'anchor' => $anchor,
            ];
        }

        return [
            'hasChart'  => true,
            'gridRings' => $gridRings,
            'spokeEnds' => $spokeEnds,
            'labels'    => $labels,
        ];
    }

    public function startQuiz()
    {
        if (!$this->selectedModule) {
            return;
        }

        $this->dispatch('startQuiz', moduleId: $this->selectedModule);
    }

    public function render()
    {
        return view('livewire.quiz-selection');
    }
}