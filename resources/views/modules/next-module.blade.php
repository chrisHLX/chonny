<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-3xl mx-auto space-y-6">

            <div>
                <h1 class="text-[17px] font-semibold text-ink">Suggested Next Modules</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Based on your progress, here's what to tackle next.</p>
            </div>

            @if(empty($suggestions))
                <div class="linear-card px-5 py-12 text-center">
                    <p class="text-[13px] text-ink-subtle">No suggestions available yet.</p>
                </div>
            @else
                <div class="linear-card overflow-hidden">
                    @foreach ($suggestions as $s)
                        <div class="{{ !$loop->last ? 'border-b border-line' : '' }} px-5 py-4 hover:bg-surface-2 transition-colors">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <p class="text-[14px] font-medium text-ink">{{ $s['name'] }}</p>
                                    @if(!empty($s['description']))
                                        <p class="text-[13px] text-ink-muted mt-0.5 line-clamp-2">{{ $s['description'] }}</p>
                                    @endif
                                    <p class="text-[11px] text-ink-subtle mt-1">
                                        {{ $s['subject'] ?? '' }}@if(!empty($s['subject']) && !empty($s['proficiency'])) — @endif{{ $s['proficiency'] ?? '' }}
                                    </p>
                                </div>

                                <div class="shrink-0">
                                    @if ($s['exists'])
                                        @if ($s['assigned'])
                                            <span class="badge-green">Added</span>
                                        @else
                                            <form action="{{ route('modules.assign', $s['module_slug']) }}" method="POST">
                                                @csrf
                                                <button type="submit"
                                                        class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                                                    Add
                                                </button>
                                            </form>
                                        @endif
                                    @else
                                        <form action="{{ route('modules.create-suggested') }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="suggestion" value="{{ json_encode($s) }}">
                                            <button type="submit"
                                                    class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                                                Create
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
