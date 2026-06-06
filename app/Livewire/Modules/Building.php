<?php

namespace App\Livewire\Modules;

use App\Models\Module;
use Livewire\Attributes\On;
use Livewire\Component;

class Building extends Component
{
    public Module $module;
    public int $pipelineId = 0;
    public bool $ready = false;

    public function mount(Module $module): void
    {
        $this->module = $module;
        $this->pipelineId = request()->integer('pipeline');
    }

    #[On('questions-generated')]
    public function markReady(): void
    {
        $this->ready = true;
    }

    public function render()
    {
        $this->module->loadMissing(['subject', 'proficiencies']);

        return view('livewire.modules.building')
            ->layout('layouts.app');
    }
}
