<div class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-3xl mx-auto">
        @if($mode === 'selection')
            <livewire:quiz-selection />
        @elseif($mode === 'running')
            <livewire:quiz-runner
                :module-id="$selectedModule"
                wire:key="quiz-runner-{{ $selectedModule }}"
            />
        @endif
    </div>
</div>
