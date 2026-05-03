@php
    $typeLabels = [
        'question_generation' => 'Question Generation',
        'quiz_completion'     => 'Quiz Completion',
        'explore_generation'  => 'Explore Generation',
        'attach tags'         => 'Tag Assignment',
    ];

    $typeLabel = $typeLabels[$pipeline->type] ?? ucwords(str_replace('_', ' ', $pipeline->type));

    $statusBadge = match($pipeline->status) {
        'running'   => 'bg-accent/10 text-accent border-accent/20',
        'pending'   => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
        'completed' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
        'failed'    => 'bg-red-500/10 text-red-400 border-red-500/20',
        default     => 'bg-surface-2 text-ink-muted border-border',
    };

    $elapsed = null;
    if ($pipeline->started_at) {
        $end = $pipeline->completed_at ?? now();
        $seconds = $pipeline->started_at->diffInSeconds($end);
        if ($seconds < 60) {
            $elapsed = $seconds . 's';
        } else {
            $elapsed = floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }
    }

    $completedSteps = $pipeline->steps->where('status', 'completed')->count();
    $totalSteps     = $pipeline->steps->count();
    $progress       = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
@endphp

<div class="linear-card overflow-hidden">
    {{-- Card header --}}
    <div class="px-5 pt-4 pb-3 border-b border-line flex items-start justify-between gap-4">
        <div class="flex items-start gap-3 min-w-0">
            {{-- Type icon --}}
            <div class="w-7 h-7 rounded-md bg-surface-2 flex items-center justify-center shrink-0 mt-0.5">
                @if($pipeline->type === 'question_generation')
                    <svg class="w-3.5 h-3.5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                @elseif($pipeline->type === 'quiz_completion')
                    <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                    </svg>
                @elseif($pipeline->type === 'explore_generation')
                    <svg class="w-3.5 h-3.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                @else
                    <svg class="w-3.5 h-3.5 text-ink-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                    </svg>
                @endif
            </div>

            {{-- Labels --}}
            <div class="min-w-0">
                <p class="text-[13px] font-medium text-ink">{{ $typeLabel }}</p>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
                    @if($pipeline->module)
                        <span class="text-[11px] text-ink-muted truncate">{{ $pipeline->module->name }}</span>
                    @endif
                    @if($isAdmin && $pipeline->user)
                        <span class="text-[11px] text-ink-subtle">&bull;</span>
                        <span class="text-[11px] text-ink-subtle">{{ $pipeline->user->name }}</span>
                    @endif
                    @if($elapsed)
                        <span class="text-[11px] text-ink-subtle">&bull;</span>
                        <span class="text-[11px] text-ink-subtle">{{ $elapsed }}</span>
                    @endif
                    @if($pipeline->started_at)
                        <span class="text-[11px] text-ink-subtle">&bull;</span>
                        <span class="text-[11px] text-ink-subtle" title="{{ $pipeline->started_at->format('Y-m-d H:i:s') }}">
                            {{ $pipeline->started_at->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Status badge --}}
        <span class="shrink-0 inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[11px] font-medium border {{ $statusBadge }}">
            @if($pipeline->status === 'running')
                <span class="w-1.5 h-1.5 rounded-full bg-accent animate-pulse"></span>
            @elseif($pipeline->status === 'pending')
                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
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
    </div>

    {{-- Progress bar (only for running/pending with steps) --}}
    @if(in_array($pipeline->status, ['running', 'pending']) && $totalSteps > 0)
        <div class="h-0.5 bg-surface-2">
            <div class="h-full bg-accent transition-all duration-500"
                 style="width: {{ $progress }}%"></div>
        </div>
    @endif

    {{-- Steps --}}
    @if($pipeline->steps->isNotEmpty())
        <ul class="divide-y divide-line">
            @foreach($pipeline->steps as $step)
                @php
                    $stepTextClass = match($step->status) {
                        'pending'   => 'text-ink-subtle',
                        'running'   => 'text-ink',
                        'completed' => 'text-ink',
                        'failed'    => 'text-red-400',
                        default     => 'text-ink-muted',
                    };
                @endphp
                <li class="px-5 py-2.5 flex items-center justify-between gap-4">
                    <span class="text-[12px] font-medium {{ $stepTextClass }}">{{ $step->name }}</span>
                    <span class="shrink-0 flex items-center gap-1.5 text-[11px]">
                        @if($step->status === 'pending')
                            <svg class="w-3.5 h-3.5 text-ink-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                      d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span class="text-ink-subtle">Pending</span>
                        @elseif($step->status === 'running')
                            <span class="w-2 h-2 rounded-full bg-accent animate-pulse"></span>
                            <span class="text-accent">Running</span>
                        @elseif($step->status === 'completed')
                            <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span class="text-emerald-400">Done</span>
                        @elseif($step->status === 'failed')
                            <svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

    {{-- Error footer --}}
    @if($pipeline->status === 'failed' && $pipeline->error)
        <div class="px-5 py-3 bg-red-500/5 border-t border-red-500/20 flex items-start gap-2">
            <svg class="w-3.5 h-3.5 text-red-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <p class="text-[11px] text-red-400 font-mono leading-relaxed">{{ $pipeline->error }}</p>
        </div>
    @endif

    {{-- Completion footer --}}
    @if($pipeline->status === 'completed' && $pipeline->completed_at)
        <div class="px-5 py-2.5 bg-emerald-500/5 border-t border-emerald-500/10 flex items-center gap-2">
            <svg class="w-3.5 h-3.5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-[11px] text-emerald-400">Completed {{ $pipeline->completed_at->diffForHumans() }}</p>
        </div>
    @endif
</div>
