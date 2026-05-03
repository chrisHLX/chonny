<div @if($polling) wire:poll.3s @endif class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-4xl mx-auto space-y-6">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-[17px] font-semibold text-ink">Job Queue</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">
                    @if($polling)
                        {{ $active->count() }} job{{ $active->count() === 1 ? '' : 's' }} running &mdash; polling every 3 seconds.
                    @else
                        No active jobs. Showing last 24 hours of history.
                    @endif
                </p>
            </div>
            @if($polling)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-[11px] font-medium bg-accent/10 text-accent border border-accent/20 shrink-0 mt-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-accent animate-pulse"></span>
                    Live
                </span>
            @endif
        </div>

        {{-- Tabs --}}
        <div class="flex gap-1 border-b border-line">
            <button wire:click="$set('tab', 'active')"
                    class="px-3 py-2 text-[13px] font-medium transition-colors border-b-2 -mb-px
                        {{ $tab === 'active'
                            ? 'text-ink border-accent'
                            : 'text-ink-muted border-transparent hover:text-ink' }}">
                Active
                @if($active->isNotEmpty())
                    <span class="ml-1.5 inline-flex items-center justify-center w-4 h-4 rounded-full text-[10px] bg-accent text-white font-semibold">
                        {{ $active->count() }}
                    </span>
                @endif
            </button>
            <button wire:click="$set('tab', 'recent')"
                    class="px-3 py-2 text-[13px] font-medium transition-colors border-b-2 -mb-px
                        {{ $tab === 'recent'
                            ? 'text-ink border-accent'
                            : 'text-ink-muted border-transparent hover:text-ink' }}">
                History
                @if($recent->isNotEmpty())
                    <span class="ml-1.5 inline-flex items-center justify-center w-4 h-4 rounded-full text-[10px] bg-surface-3 text-ink-subtle font-semibold">
                        {{ $recent->count() }}
                    </span>
                @endif
            </button>
        </div>

        {{-- Active tab --}}
        @if($tab === 'active')
            @if($active->isEmpty())
                <div class="linear-card px-5 py-16 text-center">
                    <svg class="w-8 h-8 text-ink-subtle mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-[13px] text-ink-subtle font-medium">All clear</p>
                    <p class="text-[12px] text-ink-muted mt-1">No jobs are currently running.</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($active as $pipeline)
                        @include('livewire.partials.pipeline-card', ['pipeline' => $pipeline, 'isAdmin' => $isAdmin])
                    @endforeach
                </div>
            @endif
        @endif

        {{-- History tab --}}
        @if($tab === 'recent')
            @if($recent->isEmpty())
                <div class="linear-card px-5 py-16 text-center">
                    <p class="text-[13px] text-ink-subtle">No completed or failed jobs in the last 24 hours.</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($recent as $pipeline)
                        @include('livewire.partials.pipeline-card', ['pipeline' => $pipeline, 'isAdmin' => $isAdmin])
                    @endforeach
                </div>
            @endif
        @endif

    </div>
</div>
