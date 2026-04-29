<?php

namespace App\Livewire;

use App\Models\Pipeline;
use Livewire\Component;

class GenerationProgress extends Component
{
    public int $pipelineId;
    public bool $emitted = false;

    public function render(): \Illuminate\View\View
    {
        $pipeline = Pipeline::with('steps')->find($this->pipelineId);

        if (! $this->emitted && $pipeline && $pipeline->status === 'completed') {
            $this->emitted = true;
            $this->dispatch('questions-generated');
        }

        return view('livewire.generation-progress', [
            'pipeline' => $pipeline,
            'polling'  => $pipeline && $pipeline->status === 'running',
        ]);
    }
}
