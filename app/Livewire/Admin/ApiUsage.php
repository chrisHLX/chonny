<?php

namespace App\Livewire\Admin;

use App\Models\AiRequest;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class ApiUsage extends Component
{
    use WithPagination;

    public function getSummaryProperty(): array
    {
        $rows = AiRequest::where('created_at', '>=', now()->startOfMonth())
            ->get(['metadata']);

        $totalCost  = 0.0;
        $totalCalls = $rows->count();
        $providers  = [
            'OpenAI'    => ['cost' => 0.0, 'calls' => 0],
            'Anthropic' => ['cost' => 0.0, 'calls' => 0],
            'Gemini'    => ['cost' => 0.0, 'calls' => 0],
        ];

        foreach ($rows as $row) {
            $meta     = $row->metadata ?? [];
            $cost     = (float) ($meta['cost_usd'] ?? 0);
            $provider = $this->providerFromModel($meta['model'] ?? '');

            $totalCost                     += $cost;
            $providers[$provider]['cost']  += $cost;
            $providers[$provider]['calls'] += 1;
        }

        return ['cost' => $totalCost, 'calls' => $totalCalls, 'providers' => $providers];
    }

    public function getChartDataProperty(): array
    {
        $rows = AiRequest::where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->get(['metadata', 'created_at']);

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[now()->subDays($i)->format('Y-m-d')] = 0.0;
        }

        foreach ($rows as $row) {
            $key = $row->created_at->format('Y-m-d');
            if (array_key_exists($key, $days)) {
                $days[$key] += (float) (($row->metadata ?? [])['cost_usd'] ?? 0);
            }
        }

        $max = max($days) ?: 0.0001;

        return collect($days)->map(fn($cost, $date) => [
            'date'  => $date,
            'cost'  => round($cost, 6),
            'pct'   => (int) round(($cost / $max) * 100),
            'label' => Carbon::parse($date)->format('M j'),
        ])->values()->all();
    }

    public function getPurposeBreakdownProperty(): \Illuminate\Support\Collection
    {
        return AiRequest::get(['purpose', 'metadata'])
            ->groupBy('purpose')
            ->map(fn($group) => [
                'purpose' => $group->first()?->purpose ?? '—',
                'calls'   => $group->count(),
                'cost'    => round($group->sum(fn($r) => (float) (($r->metadata ?? [])['cost_usd'] ?? 0)), 6),
            ])
            ->sortByDesc('cost')
            ->values();
    }

    private function providerFromModel(string $model): string
    {
        return match (true) {
            str_starts_with($model, 'claude-') => 'Anthropic',
            str_starts_with($model, 'gemini-') => 'Gemini',
            default                             => 'OpenAI',
        };
    }

    public function render()
    {
        return view('livewire.admin.api-usage', [
            'requests'         => AiRequest::with('user')->latest()->paginate(25),
            'summary'          => $this->summary,
            'chartData'        => $this->chartData,
            'purposeBreakdown' => $this->purposeBreakdown,
        ])->layout('layouts.app');
    }
}
