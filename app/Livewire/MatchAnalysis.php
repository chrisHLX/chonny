<?php

namespace App\Livewire;

use App\Http\Services\MatchAnalysisService;
use App\Models\PageViewEvent;
use Livewire\Component;

/**
 * "Your analysis" — a player's own uploaded games, read the way match-review-analysis.md reads one
 * team's 26 Sep session: the review table, who they really played, what differed between the games
 * they won and lost, and a takeaway for their role. See MatchAnalysisService.
 *
 * PRIVATE TO THE VIEWER, like GameReview: the route carries `auth`, and every game read is scoped
 * to auth()->id() inside the service. $session only chooses among the viewer's own sessions, so a
 * forged value can select nothing that is not theirs.
 */
class MatchAnalysis extends Component
{
    /** "date|bracket|teamKey" — which session is open. */
    public ?string $session = null;

    public function mount(): void
    {
        PageViewEvent::log('match_analysis');
        $this->session ??= $this->key($this->sessions()[0] ?? null);
    }

    public function sessions(): array
    {
        return app(MatchAnalysisService::class)->sessions(auth()->user());
    }

    /** Choosing a session is a real explicit selection, so it is attributed (rule 28). */
    public function open(string $key): void
    {
        if (collect($this->sessions())->contains(fn ($s) => $this->key($s) === $key)) {
            $this->session = $key;
            PageViewEvent::log('match_analysis', slot: explode('|', $key)[0]);
        }
    }

    private function key(?array $s): ?string
    {
        return $s ? $s['date'].'|'.$s['bracket'].'|'.$s['team'] : null;
    }

    public function render()
    {
        $analysis = null;
        if ($this->session && count($parts = explode('|', $this->session)) === 3) {
            $analysis = app(MatchAnalysisService::class)->build(auth()->user(), ...$parts);
        }

        return view('livewire.match-analysis', [
            'sessions' => $this->sessions(),
            'analysis' => $analysis,
        ])->layout('layouts.app', [
            'title' => 'Your analysis — your arena games, explained | MindCollector',
            'description' => 'Your own arena games read back: who you really played, what differed between the games you won and lost, and what to change.',
        ]);
    }
}
