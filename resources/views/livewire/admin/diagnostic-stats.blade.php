<div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
    <div class="max-w-6xl mx-auto space-y-6">

        {{-- Header --}}
        <div>
            <h1 class="text-[17px] font-semibold text-ink">Diagnostic Stats</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">Who's starting and finishing diagnostic quizzes — guest and registered.</p>
        </div>

        {{-- ── Summary row ── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Started</p>
                <p class="text-[22px] font-semibold text-ink leading-none">{{ number_format($summary['started']) }}</p>
                <p class="text-[12px] text-ink-muted mt-1">{{ number_format($summary['guestStarted']) }} guest · {{ number_format($summary['authStarted']) }} auth</p>
            </div>
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Completed</p>
                <p class="text-[22px] font-semibold text-ink leading-none">{{ number_format($summary['completed']) }}</p>
                <p class="text-[12px] text-ink-muted mt-1">{{ number_format($summary['guestCompleted']) }} guest · {{ number_format($summary['authCompleted']) }} auth</p>
            </div>
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Completion rate</p>
                <p class="text-[22px] font-semibold text-ink leading-none">{{ $summary['rate'] }}%</p>
                <p class="text-[12px] text-ink-muted mt-1">of all starts</p>
            </div>
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Guest completion rate</p>
                <p class="text-[22px] font-semibold text-ink leading-none">
                    {{ $summary['guestStarted'] > 0 ? round(($summary['guestCompleted'] / $summary['guestStarted']) * 100, 1) : 0 }}%
                </p>
                <p class="text-[12px] text-ink-muted mt-1">guests who finish before signing up</p>
            </div>
        </div>

        {{-- ── 30-day chart ── --}}
        <div class="linear-card p-5">
            <div class="flex items-center justify-between mb-4">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Starts vs completions — last 30 days</p>
                <div class="flex items-center gap-3 text-[11px] text-ink-subtle">
                    <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm bg-accent/40"></span>Started</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm bg-violet"></span>Completed</span>
                </div>
            </div>

            <div class="flex items-end gap-px h-20">
                @foreach($chartData as $day)
                    <div class="flex-1 flex flex-col justify-end group/bar relative"
                         title="{{ $day['label'] }}: {{ $day['started'] }} started, {{ $day['completed'] }} completed">
                        <div class="w-full rounded-t-sm bg-accent/40 group-hover/bar:bg-accent transition-colors relative"
                             style="height: {{ max($day['pct'], 2) }}%">
                            @if($day['completed'] > 0 && $day['started'] > 0)
                                <div class="absolute bottom-0 left-0 w-full rounded-t-sm bg-violet"
                                     style="height: {{ min(100, round(($day['completed'] / $day['started']) * 100)) }}%"></div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex mt-1.5">
                @foreach($chartData as $i => $day)
                    <div class="flex-1 text-center">
                        @if($i % 7 === 0 || $loop->last)
                            <span class="text-[9px] text-ink-subtle">{{ $day['label'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Per-subject breakdown ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">By subject</p>
            </div>

            @if($subjectBreakdown->isEmpty())
                <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No data yet.</p>
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-line">
                            <th class="px-5 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Subject</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Started</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Completed</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($subjectBreakdown as $row)
                            <tr class="{{ !$loop->last ? 'border-b border-line' : '' }} hover:bg-surface-2 transition-colors">
                                <td class="px-5 py-3 text-[13px] text-ink">{{ $row['subject'] }}</td>
                                <td class="px-5 py-3 text-[13px] text-ink-muted text-right">{{ number_format($row['started']) }}</td>
                                <td class="px-5 py-3 text-[13px] text-ink-muted text-right">{{ number_format($row['completed']) }}</td>
                                <td class="px-5 py-3 text-[13px] text-ink text-right">{{ $row['rate'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Recent attempts log ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Recent attempts</p>
            </div>

            @if($attempts->isEmpty())
                <p class="px-5 py-12 text-center text-[13px] text-ink-subtle">No diagnostic attempts recorded yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[700px]">
                        <thead>
                            <tr class="border-b border-line bg-surface-2">
                                <th class="px-4 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider whitespace-nowrap">Started</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Subject</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Module</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">User</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($attempts as $attempt)
                                <tr wire:key="attempt-{{ $attempt->id }}"
                                    class="{{ !$loop->last ? 'border-b border-line' : '' }} hover:bg-surface-2 transition-colors">
                                    <td class="px-4 py-3 text-[12px] text-ink-muted whitespace-nowrap">
                                        {{ $attempt->started_at->format('M j, g:ia') }}
                                    </td>
                                    <td class="px-4 py-3 text-[12px] text-ink-muted">
                                        {{ $attempt->subject->name ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-[12px] text-ink-muted">
                                        {{ $attempt->module->name ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-[12px] text-ink-muted whitespace-nowrap">
                                        {{ $attempt->user->name ?? 'Guest' }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if($attempt->completed_at)
                                            <span class="badge-green">Completed</span>
                                        @else
                                            <span class="badge-amber">In progress</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($attempts->hasPages())
                    <div class="px-5 py-4 border-t border-line">
                        {{ $attempts->links() }}
                    </div>
                @endif
            @endif
        </div>

    </div>
</div>
