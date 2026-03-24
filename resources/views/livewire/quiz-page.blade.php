<div class="p-6 max-w-4xl mt-8 mx-auto bg-white rounded-2xl shadow-lg border border-gray-100 text-gray-700">

    @if($mode === 'selection')
        <livewire:quiz-selection />
    @elseif($mode === 'running')
        <livewire:quiz-runner
            :module-id="$selectedModule"
            wire:key="quiz-runner-{{ $selectedModule }}"
        />
    @endif

</div>