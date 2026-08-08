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
                <p class="text-[11px] text-ink-subtle mt-1">clicked "Start Assessment" · {{ number_format($summary['pageViews']) }} page views total</p>
            </div>
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Completed</p>
                <p class="text-[22px] font-semibold text-ink leading-none">{{ number_format($summary['completed']) }}</p>
                <p class="text-[12px] text-ink-muted mt-1">{{ number_format($summary['guestCompleted']) }} guest · {{ number_format($summary['authCompleted']) }} auth</p>
            </div>
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Completion rate</p>
                <p class="text-[22px] font-semibold text-ink leading-none">{{ $summary['rate'] }}%</p>
                <p class="text-[12px] text-ink-muted mt-1">of engaged starts</p>
            </div>
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Guest completion rate</p>
                <p class="text-[22px] font-semibold text-ink leading-none">
                    {{ $summary['guestStarted'] > 0 ? round(($summary['guestCompleted'] / $summary['guestStarted']) * 100, 1) : 0 }}%
                </p>
                <p class="text-[12px] text-ink-muted mt-1">guests who finish before signing up</p>
            </div>
        </div>

        {{-- ── Signup funnel (guest → registered) ── --}}
        <div class="linear-card p-5">
            <div class="flex items-center justify-between mb-4">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Signup funnel — guest → registered</p>
                <p class="text-[11px] text-ink-subtle">Since the learning path launched</p>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
                <div>
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-1.5">Profile viewed</p>
                    <p class="text-[20px] font-semibold text-ink leading-none">{{ number_format($funnel['profileViewed']) }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-1.5">Roadmap clicked</p>
                    <p class="text-[20px] font-semibold text-ink leading-none">{{ number_format($funnel['roadmapClicked']) }}</p>
                    <p class="text-[11px] text-ink-muted mt-1">{{ $funnel['clickThroughRate'] }}% of profile views</p>
                </div>
                <div>
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-1.5">Signup started</p>
                    <p class="text-[20px] font-semibold text-ink leading-none">{{ number_format($funnel['signupStarted']) }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-1.5">Signed up</p>
                    <p class="text-[20px] font-semibold text-ink leading-none">{{ number_format($funnel['uniqueSignups']) }}</p>
                </div>
            </div>

            <div class="border-t border-line pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex items-center justify-between px-4 py-3 rounded-md bg-surface-2">
                    <div>
                        <p class="text-[12px] font-medium text-ink">Signed up after clicking the roadmap</p>
                        <p class="text-[11px] text-ink-subtle mt-0.5">{{ number_format($funnel['signupsAfterClick']) }} of {{ number_format($funnel['roadmapClicked']) }} clickers</p>
                    </div>
                    <p class="text-[18px] font-semibold text-gold">{{ $funnel['clickerSignupRate'] }}%</p>
                </div>
                <div class="flex items-center justify-between px-4 py-3 rounded-md bg-surface-2">
                    <div>
                        <p class="text-[12px] font-medium text-ink">Signed up without clicking it</p>
                        <p class="text-[11px] text-ink-subtle mt-0.5">went straight from profile to signup</p>
                    </div>
                    <p class="text-[18px] font-semibold text-ink-muted">{{ number_format($funnel['nonClickerSignups']) }}</p>
                </div>
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

        {{-- ── Drop-off by question ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Where people are dropping off</p>
                <p class="text-[11px] text-ink-subtle mt-0.5">Incomplete attempts, grouped by the last question they reached — updated live as people answer, so this includes people who never came back.</p>
            </div>

            @if($dropOff->isEmpty())
                <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No incomplete attempts with progress data yet.</p>
            @else
                <div class="divide-y divide-line">
                    @foreach($dropOff as $row)
                        <div class="px-5 py-4">
                            <div class="flex items-center justify-between mb-3">
                                <p class="text-[13px] font-medium text-ink">{{ $row['subject'] }}</p>
                                <p class="text-[11px] text-ink-subtle">
                                    {{ $row['incomplete'] }} incomplete
                                    @if($row['worstQuestion'])
                                        · most got stuck on Q{{ $row['worstQuestion'] }} of {{ $row['total_questions'] }} ({{ $row['worstCount'] }})
                                    @endif
                                </p>
                            </div>
                            @if(($row['neverStarted'] ?? 0) > 0 || ($row['abandonedOnQ1'] ?? 0) > 0)
                                <p class="text-[11px] text-ink-subtle mb-3">
                                    Of those stuck on Q1:
                                    <span class="text-ink-muted">{{ $row['neverStarted'] }} never clicked "Start Assessment"</span>
                                    ·
                                    <span class="text-ink-muted">{{ $row['abandonedOnQ1'] }} clicked it, then left before answering Q1</span>
                                </p>
                            @endif
                            <div class="flex items-end gap-1 h-16">
                                @for($qn = 1; $qn <= $row['total_questions']; $qn++)
                                    @php
                                        $count = $row['byQuestion'][$qn] ?? 0;
                                        $pct   = $row['worstCount'] > 0 ? round(($count / $row['worstCount']) * 100) : 0;
                                        $isWorst = $qn === $row['worstQuestion'];
                                    @endphp
                                    <div class="flex-1 flex flex-col justify-end group/bar relative"
                                         title="Question {{ $qn }}: {{ $count }} stuck here">
                                        <div class="w-full rounded-t-sm transition-colors {{ $isWorst ? 'bg-red-400' : 'bg-accent/40 group-hover/bar:bg-accent' }}"
                                             style="height: {{ max($pct, $count > 0 ? 4 : 0) }}%"></div>
                                    </div>
                                @endfor
                            </div>
                            <div class="flex mt-1">
                                @for($qn = 1; $qn <= $row['total_questions']; $qn++)
                                    <div class="flex-1 text-center">
                                        @if($qn === 1 || $qn === $row['total_questions'] || $qn % 5 === 0)
                                            <span class="text-[9px] text-ink-subtle">{{ $qn }}</span>
                                        @endif
                                    </div>
                                @endfor
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── Context declaration vs completion ── --}}
        <div class="linear-card overflow-hidden">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Does declaring context correlate with finishing?</p>
                <p class="text-[11px] text-ink-subtle mt-0.5">The read the context-aware-diagnostic work exists to produce — split further by whether that subject has any authored context-specific variants yet.</p>
            </div>
            <div class="divide-y divide-line">
                @foreach($contextCompletion as $row)
                    <div class="px-5 py-3 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-[13px] text-ink">{{ $row['label'] }}</p>
                            <p class="text-[11px] text-ink-subtle">{{ number_format($row['completed']) }} of {{ number_format($row['started']) }}</p>
                        </div>
                        <p class="text-[16px] font-semibold text-ink">{{ $row['rate'] }}%</p>
                    </div>
                @endforeach
            </div>
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
                                <th class="px-4 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Progress</th>
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
                                    <td class="px-4 py-3 text-[12px] text-ink-muted text-right whitespace-nowrap">
                                        @if($attempt->completed_at)
                                            &mdash;
                                        @elseif($attempt->last_question_index !== null && $attempt->total_questions)
                                            Q{{ $attempt->last_question_index + 1 }} of {{ $attempt->total_questions }}
                                        @else
                                            &mdash;
                                        @endif
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
