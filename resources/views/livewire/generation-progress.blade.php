<div @if($polling) wire:poll.2s @endif class="linear-card overflow-hidden">

    {{-- Header --}}
    <div class="px-6 pt-5 pb-4 border-b border-border flex items-center justify-between gap-4">
        <div>
            <h3 class="page-section-title">Generation Progress</h3>
            @if($pipeline)
                @if($pipeline->status === 'running')
                    <p class="text-[12px] text-ink-muted mt-0.5">Jobs are running — updates every 2 seconds.</p>
                @elseif($pipeline->status === 'completed')
                    <p class="text-[12px] text-green-400 mt-0.5">Questions generated successfully</p>
                @elseif($pipeline->status === 'failed')
                    <p class="text-[12px] text-red-400 mt-0.5">Generation failed — check logs</p>
                @endif
            @endif
        </div>

        @if($pipeline)
            @php
                $badgeClass = match($pipeline->status) {
                    'running'   => 'bg-accent/10 text-accent border-accent/20',
                    'completed' => 'bg-green-500/10 text-green-400 border-green-500/20',
                    'failed'    => 'bg-red-500/10 text-red-400 border-red-500/20',
                    default     => 'bg-surface-1 text-ink-muted border-border',
                };
            @endphp
            <span class="shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-[11px] font-medium border {{ $badgeClass }}">
                @if($pipeline->status === 'running')
                    <span class="w-1.5 h-1.5 rounded-full bg-accent animate-pulse"></span>
                @elseif($pipeline->status === 'completed')
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                @elseif($pipeline->status === 'failed')
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                @endif
                {{ ucfirst($pipeline->status) }}
            </span>
        @endif
    </div>

    {{-- Steps --}}
    @if($pipeline && $pipeline->steps->isNotEmpty())
        <ul class="divide-y divide-border">
            @foreach($pipeline->steps as $step)
                @php
                    $stepTextClass = match($step->status) {
                        'pending'   => 'text-ink-subtle',
                        'running'   => 'text-ink',
                        'completed' => 'text-ink',
                        'failed'    => 'text-red-400',
                        default     => 'text-ink-muted',
                    };
                    $stepLabel = match(true) {
                        str_contains($step->name, 'Content')     => 'Writing guide content',
                        str_contains($step->name, 'Suggestions') => 'Analysing your performance',
                        str_contains($step->name, 'Card')        => 'Creating your card',
                        default                                  => 'Generating questions',
                    };
                @endphp
                <li class="px-6 py-3.5 flex items-center justify-between gap-4">
                    <span class="text-[13px] font-medium {{ $stepTextClass }}">{{ $stepLabel }}</span>
                    <span class="shrink-0 flex items-center gap-1.5 text-[12px]">
                        @if($step->status === 'pending')
                            <svg class="w-4 h-4 text-ink-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                      d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span class="text-ink-subtle">Pending</span>
                        @elseif($step->status === 'running')
                            <span class="w-2 h-2 rounded-full bg-accent animate-pulse"></span>
                            <span class="text-accent">Running</span>
                        @elseif($step->status === 'completed')
                            <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span class="text-green-400">Done</span>
                        @elseif($step->status === 'failed')
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            <span class="text-red-400">Failed</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Footer message --}}
    @if($pipeline && $pipeline->status === 'completed')
        <div class="px-6 py-4 bg-green-500/5 border-t border-green-500/20 flex items-center gap-2">
            <svg class="w-4 h-4 text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-[12px] text-green-400">All done — reload the page to see the new questions.</p>
        </div>
    @elseif($pipeline && $pipeline->status === 'failed')
        <div class="px-6 py-4 bg-red-500/5 border-t border-red-500/20 flex items-center gap-2">
            <svg class="w-4 h-4 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <p class="text-[12px] text-red-400">Generation failed — check logs</p>
        </div>
    @endif

</div>

