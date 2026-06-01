<?php

namespace App\Livewire\Admin;

use App\Models\UserAxisMastery;
use App\Models\UserConceptMastery;
use App\Models\UserConceptSkillMastery;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class WeakAreas extends Component
{
    use WithPagination;

    public string $tab = 'concepts';
    public int $threshold = 50;

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedThreshold(): void
    {
        $this->resetPage();
    }

    public function getSummaryProperty(): array
    {
        $totalTracked = UserConceptMastery::distinct('user_id')->count('user_id');

        $usersWithWeak = UserConceptMastery::where('mastery_percentage', '<', $this->threshold)
            ->distinct('user_id')
            ->count('user_id');

        $avgMastery = round(UserConceptMastery::avg('mastery_percentage') ?? 0, 1);

        $skillBreakdown = UserConceptSkillMastery::select('skill_type', DB::raw('AVG(mastery_percentage) as avg'))
            ->groupBy('skill_type')
            ->pluck('avg', 'skill_type')
            ->map(fn($v) => round($v, 1))
            ->all();

        $weakestSkill = collect($skillBreakdown)->sortKeys()->sortBy(fn($v) => $v)->keys()->first() ?? '—';

        return compact('totalTracked', 'usersWithWeak', 'avgMastery', 'skillBreakdown', 'weakestSkill');
    }

    public function getWeakConceptsProperty()
    {
        return UserConceptMastery::with(['concept.subject'])
            ->select(
                'concept_id',
                DB::raw('ROUND(AVG(mastery_percentage), 1) as avg_mastery'),
                DB::raw('COUNT(DISTINCT user_id) as user_count'),
                DB::raw('SUM(CASE WHEN mastery_percentage < 50 THEN 1 ELSE 0 END) as struggling_count')
            )
            ->groupBy('concept_id')
            ->having('avg_mastery', '<', $this->threshold)
            ->orderBy('avg_mastery')
            ->paginate(15);
    }

    public function getWeakUsersProperty()
    {
        return UserConceptMastery::with('user')
            ->select(
                'user_id',
                DB::raw('ROUND(AVG(mastery_percentage), 1) as avg_mastery'),
                DB::raw('COUNT(*) as concept_count'),
                DB::raw('SUM(CASE WHEN mastery_percentage < 50 THEN 1 ELSE 0 END) as weak_count')
            )
            ->groupBy('user_id')
            ->having('weak_count', '>', 0)
            ->orderBy('avg_mastery')
            ->paginate(15);
    }

    public function getWeakAxesProperty()
    {
        return UserAxisMastery::with(['axis.category'])
            ->select(
                'axis_id',
                DB::raw('ROUND(AVG(mastery_percentage), 1) as avg_mastery'),
                DB::raw('COUNT(DISTINCT user_id) as user_count')
            )
            ->groupBy('axis_id')
            ->having('avg_mastery', '<', $this->threshold)
            ->orderBy('avg_mastery')
            ->get();
    }

    public function render()
    {
        return view('livewire.admin.weak-areas', [
            'summary'      => $this->summary,
            'weakConcepts' => $this->weakConcepts,
            'weakUsers'    => $this->weakUsers,
            'weakAxes'     => $this->weakAxes,
        ])->layout('layouts.app');
    }
}
