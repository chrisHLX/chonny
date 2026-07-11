<?php

namespace App\Livewire;

use App\Enums\NextStepStatus;
use App\Enums\StepType;
use App\Jobs\InterpretReflectionJob;
use App\Models\UserNextStep;
use App\Models\UserNextStepReflection;
use Livewire\Component;

class NextStepReflection extends Component
{
    public int $nextStepId;
    public ?string $didTry = null;
    public string $howItWent = '';
    public string $whyReasoning = '';
    public bool $submitted = false;

    public function mount(int $nextStepId)
    {
        $this->nextStepId = $nextStepId;
    }

    public function submit()
    {
        $this->validate([
            'didTry'       => 'required|in:yes,no,partially',
            'howItWent'    => 'required|string|max:2000',
            'whyReasoning' => 'required|string|max:2000',
        ]);

        $step = UserNextStep::where('id', $this->nextStepId)
            ->where('user_id', auth()->id())
            ->where('step_type', StepType::Task->value)
            ->firstOrFail();

        $reflection = UserNextStepReflection::create([
            'next_step_id'  => $step->id,
            'did_try'       => $this->didTry,
            'how_it_went'   => $this->howItWent,
            'why_reasoning' => $this->whyReasoning,
            'submitted_at'  => now(),
        ]);

        $step->update([
            'status'      => NextStepStatus::Attempted,
            'attempted_at' => $step->attempted_at ?? now(),
        ]);

        InterpretReflectionJob::dispatch($reflection->id);

        $this->submitted = true;
    }

    /**
     * Polled while $submitted is true — InterpretReflectionJob runs in the background (often
     * within seconds, but it's a queued job with no other completion signal reaching this
     * component). Checking $step->status === Completed alone is racy: the job marks the step
     * Completed *before* it generates the replacement (a separate AI call, can take a couple
     * more seconds) — a poll landing in that gap would redirect to a dashboard whose
     * $activeNextStep resolves to nothing yet. Check for the successor's existence instead,
     * which is only ever true once regeneration has actually finished.
     *
     * A concluded investigation chain (NextStepService::investigationChainExhausted) never gets
     * a successor by design — regeneration deliberately stops there — so that alone would poll
     * forever. Treat the step's own status flipping to Concluded as an equally valid terminal
     * signal to redirect on.
     */
    public function checkCompletion(): void
    {
        $hasSuccessor = UserNextStep::where('previous_step_id', $this->nextStepId)->exists();
        $concluded = UserNextStep::where('id', $this->nextStepId)
            ->where('status', NextStepStatus::Concluded->value)
            ->exists();

        if ($hasSuccessor || $concluded) {
            $this->redirect(route('dashboard'));
        }
    }

    public function render()
    {
        return view('livewire.next-step-reflection');
    }
}
