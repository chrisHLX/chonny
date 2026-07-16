<?php

namespace App\Livewire\Admin;

use App\Models\DiagnosticAttempt;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class DiagnosticStats extends Component
{
    use WithPagination;

    public function getSummaryProperty(): array
    {
        $started   = DiagnosticAttempt::count();
        $completed = DiagnosticAttempt::whereNotNull('completed_at')->count();

        $guestStarted   = DiagnosticAttempt::whereNull('user_id')->count();
        $guestCompleted = DiagnosticAttempt::whereNull('user_id')->whereNotNull('completed_at')->count();

        $authStarted   = $started - $guestStarted;
        $authCompleted = $completed - $guestCompleted;

        return [
            'started'        => $started,
            'completed'      => $completed,
            'rate'           => $started > 0 ? round(($completed / $started) * 100, 1) : 0.0,
            'guestStarted'   => $guestStarted,
            'guestCompleted' => $guestCompleted,
            'authStarted'    => $authStarted,
            'authCompleted'  => $authCompleted,
        ];
    }

    public function getChartDataProperty(): array
    {
        $rows = DiagnosticAttempt::where('started_at', '>=', now()->subDays(29)->startOfDay())
            ->get(['started_at', 'completed_at']);

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[now()->subDays($i)->format('Y-m-d')] = ['started' => 0, 'completed' => 0];
        }

        foreach ($rows as $row) {
            $key = $row->started_at->format('Y-m-d');
            if (array_key_exists($key, $days)) {
                $days[$key]['started']++;
            }
            if ($row->completed_at && array_key_exists($ck = $row->completed_at->format('Y-m-d'), $days)) {
                $days[$ck]['completed']++;
            }
        }

        $max = max(array_column($days, 'started')) ?: 1;

        return collect($days)->map(fn($counts, $date) => [
            'date'      => $date,
            'started'   => $counts['started'],
            'completed' => $counts['completed'],
            'pct'       => (int) round(($counts['started'] / $max) * 100),
            'label'     => Carbon::parse($date)->format('M j'),
        ])->values()->all();
    }

    public function getSubjectBreakdownProperty(): \Illuminate\Support\Collection
    {
        return DiagnosticAttempt::with('subject')
            ->get(['subject_id', 'completed_at'])
            ->groupBy('subject_id')
            ->map(function ($group) {
                $started   = $group->count();
                $completed = $group->whereNotNull('completed_at')->count();

                return [
                    'subject'   => $group->first()->subject?->name ?? '—',
                    'started'   => $started,
                    'completed' => $completed,
                    'rate'      => $started > 0 ? round(($completed / $started) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('started')
            ->values();
    }

    public function render()
    {
        return view('livewire.admin.diagnostic-stats', [
            'summary'          => $this->summary,
            'chartData'        => $this->chartData,
            'subjectBreakdown' => $this->subjectBreakdown,
            'attempts'         => DiagnosticAttempt::with(['module', 'subject', 'user'])
                ->latest('started_at')
                ->paginate(25),
        ])->layout('layouts.app');
    }
}
