<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-4xl mx-auto space-y-6">

            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-[17px] font-semibold text-ink">{{ $module->name }}</h1>
                    @if($module->proficiencies()->first())
                        <p class="text-[13px] text-ink-muted mt-0.5">{{ $module->proficiencies()->first()->name }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('modules.export', $module) }}" target="_blank"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[13px] font-medium text-ink-muted border border-border hover:border-accent/50 hover:text-ink rounded-md transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Export Diagnostic
                    </a>
                    <form action="{{ route('modules.destroy', $module) }}" method="POST"
                          onsubmit="return confirm('Delete this module?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-danger">Delete Module</button>
                    </form>
                </div>
            </div>

            {{-- Research panel --}}
            <x-modules.research-panel :module="$module" />

            {{-- Edit module --}}
            <x-modules.update-form :module="$module" :all-questions="$allQuestions" />

            {{-- Generate questions --}}
            <x-modules.generate-questions-form :module="$module" :modulePages="$modulePages" />

            {{-- Generation progress --}}
            @if($pipeline)
                <livewire:generation-progress :pipeline-id="$pipeline->id" />
            @endif

            {{-- Content manager --}}
            <x-modules.content-manager :module="$module" :modulePages="$modulePages" />

        </div>
    </div>
</x-app-layout>
