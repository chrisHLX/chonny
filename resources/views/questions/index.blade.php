<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-4xl mx-auto">

            <div class="mb-6">
                <h1 class="text-[17px] font-semibold text-ink">Questions</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">All questions in the system.</p>
            </div>

            <div class="linear-card overflow-hidden">
                @forelse ($questions as $question)
                    <div class="{{ !$loop->last ? 'border-b border-line' : '' }} px-5 py-4 hover:bg-surface-2 transition-colors group">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-[14px] font-medium text-ink mb-1">{{ $question->question }}</p>
                                <div class="flex flex-wrap gap-2 mb-2">
                                    <span class="badge-gray">{{ $question->type }}</span>
                                    <span class="badge-gray">{{ $question->difficulty }}</span>
                                    @if($question->units->count())
                                        <span class="badge-blue">{{ $question->units->pluck('name')->join(', ') }}</span>
                                    @endif
                                    @if($question->concepts->count())
                                        <span class="badge-blue">{{ $question->concepts->pluck('name')->join(', ') }}</span>
                                    @endif
                                </div>
                                <pre class="bg-surface-2 border border-line rounded-md p-3 text-[11px] text-ink-muted overflow-x-auto">{{ json_encode($question->answer, JSON_PRETTY_PRINT) }}</pre>
                            </div>

                            <div class="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                <form action="{{ route('questions.destroy', $question) }}" method="POST"
                                      onsubmit="return confirm('Delete this question?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-16 text-center">
                        <p class="text-[13px] text-ink-subtle">No questions found.</p>
                    </div>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
