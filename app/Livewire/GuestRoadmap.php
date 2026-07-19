<?php

namespace App\Livewire;

use App\Http\Services\RoadmapService;
use App\Models\FunnelEvent;
use App\Models\Module;
use Livewire\Component;

/**
 * Nested inside diagnostic-quiz-runner.blade.php's guest completion screen — deliberately its
 * own component rather than a change to DiagnosticQuizRunner.php, so the fragile diagnostic
 * quiz flow itself stays untouched. Reads everything it needs from
 * session('guest_quiz_results.{moduleId}'), which DiagnosticQuizRunner::completeModule()
 * already writes — no persistence of its own, fully recomputable from session data.
 *
 * No AI call anywhere in this component or in RoadmapService — the "gate behind a click" here
 * is about funnel measurement and deferring the (cheap, DB-only) roadmap build, not about
 * saving AI spend.
 */
class GuestRoadmap extends Component
{
    public $moduleId;
    public bool $revealed = false;

    // False for authenticated users (they don't need this) or when the guest's session data
    // has expired/is missing — component renders nothing in either case.
    public bool $available = false;

    public ?array $roadmap = null;

    public function mount($moduleId): void
    {
        $this->moduleId = $moduleId;

        if (auth()->check()) {
            return;
        }

        $stored = session('guest_quiz_results.' . $this->moduleId);
        if (!$stored || empty($stored['diagnostic_profile'])) {
            return;
        }

        $this->available = true;

        // Fire once per session per module — guarded with a session flag, not a DB read, so a
        // refresh of the results screen doesn't re-log the same view.
        $viewedFlagKey = 'funnel_profile_viewed.' . $this->moduleId;
        if (!session($viewedFlagKey)) {
            session([$viewedFlagKey => true]);
            FunnelEvent::log('profile_viewed', session()->getId(), (int) $this->moduleId);
        }
    }

    public function reveal(): void
    {
        if (!$this->available || $this->revealed) {
            return;
        }

        FunnelEvent::log('roadmap_clicked', session()->getId(), (int) $this->moduleId);

        $stored = session('guest_quiz_results.' . $this->moduleId);
        $module = Module::with('subject')->find($this->moduleId);

        if (!$stored || !$module) {
            return;
        }

        $this->roadmap = app(RoadmapService::class)->buildGuestRoadmap(
            $stored['diagnostic_profile'] ?? [],
            $stored['survey_answers'] ?? [],
            $module
        );

        $this->revealed = true;
    }

    public function render()
    {
        return view('livewire.guest-roadmap');
    }
}
