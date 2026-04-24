<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-3xl mx-auto space-y-6">

            <div>
                <h1 class="text-[17px] font-semibold text-ink">Problematic Questions</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Questions you've struggled with most.</p>
            </div>

            @if(isset($aiSummary) && $aiSummary)
                <div class="linear-card p-5">
                    <p class="text-[12px] font-semibold text-ink-subtle uppercase tracking-wide mb-2">AI Summary</p>
                    <p class="text-[13px] text-ink-muted leading-relaxed">{{ $aiSummary }}</p>
                </div>
            @endif

            <div class="linear-card overflow-hidden">
                @forelse ($questions as $question)
                    <div class="{{ !$loop->last ? 'border-b border-line' : '' }} px-5 py-3">
                        <p class="text-[13px] text-ink">{{ $question->question }}</p>
                    </div>
                @empty
                    <div class="px-5 py-12 text-center">
                        <p class="text-[13px] text-ink-subtle">No problematic questions found.</p>
                    </div>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
