<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-3xl mx-auto space-y-6">

            <div>
                <h1 class="text-[17px] font-semibold text-ink">{{ $module->name }}</h1>
                @if($module->description)
                    <p class="text-[13px] text-ink-muted mt-0.5">{{ $module->description }}</p>
                @endif
            </div>

            <form method="POST" action="{{ route('modules.start', $module) }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                    Start Module
                </button>
            </form>

            @if($questions->isNotEmpty())
                <div class="linear-card overflow-hidden">
                    <div class="px-5 py-3 border-b border-line">
                        <p class="text-[12px] font-semibold text-ink-subtle uppercase tracking-wide">Practice Questions</p>
                    </div>
                    @foreach ($questions as $question)
                        <div class="{{ !$loop->last ? 'border-b border-line' : '' }} px-5 py-3">
                            <p class="text-[13px] text-ink-muted">{{ $question->question }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
