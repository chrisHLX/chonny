<x-app-layout>
    <div class="min-h-full py-8 px-4">
        @if ($module->type === 'diagnostic')
            <livewire:diagnostic-quiz-runner :moduleId="$module->id" />
        @else
            <livewire:quiz-runner :moduleId="$module->id" />
        @endif
    </div>
</x-app-layout>
