<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Pipeline;

class CollectionPending extends Component
{
    public int $pipelineId;

    public function mount(int $pipelineId): void
    {
        $this->pipelineId = $pipelineId;
    }

    public function checkStatus(): void
    {
        $pipeline = Pipeline::with('steps')->find($this->pipelineId);

        if (! $pipeline) {
            return;
        }

        $generateStep = $pipeline->steps->firstWhere('name', 'Generate Card');

        if ($generateStep && $generateStep->status === 'completed') {
            $this->redirect(route('collection.index'), navigate: true);
        }
    }

    public function render()
    {
        $pipeline = Pipeline::with('steps')->find($this->pipelineId);

        return view('livewire.collection-pending', compact('pipeline'));
    }
}
