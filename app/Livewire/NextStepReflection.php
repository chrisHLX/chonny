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

    public function render()
    {
        return view('livewire.next-step-reflection');
    }
}
