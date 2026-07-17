<div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
    <div class="max-w-6xl mx-auto space-y-6">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-[17px] font-semibold text-ink">Weak Areas</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Concepts, axes, and users that need improvement.</p>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-[12px] text-ink-muted">Show below</label>
                <select wire:model.live="threshold"
                        class="text-[12px] bg-surface-2 border border-line rounded px-2 py-1 text-ink">
                    <option value="30">30%</option>
                    <option value="50">50%</option>
                    <option value="70">70%</option>
                </select>
            </div>
        </div>

        {{-- Summary cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Users tracked</p>
                <p class="text-[22px] font-semibold text-ink leading-none">{{ $summary['totalTracked'] }}</p>
                <p class="text-[12px] text-ink-muted mt-1">with mastery data</p>
            </div>
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">At risk</p>
                <p class="text-[22px] font-semibold text-ink leading-none">{{ $summary['usersWithWeak'] }}</p>
                <p class="text-[12px] text-ink-muted mt-1">users below {{ $threshold }}%</p>
            </div>
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Platform avg</p>
                <p class="text-[22px] font-semibold text-ink leading-none">{{ $summary['avgMastery'] }}%</p>
                <p class="text-[12px] text-ink-muted mt-1">across all concepts</p>
            </div>
            <div class="linear-card p-5">
                <p class="text-[11px] text-ink-subtle uppercase tracking-wider mb-2">Weakest skill</p>
                <p class="text-[22px] font-semibold text-ink leading-none capitalize">{{ $summary['weakestSkill'] }}</p>
                <p class="text-[12px] text-ink-muted mt-1">thinking mode</p>
            </div>
        </div>

        {{-- Skill type row --}}
        @if(!empty($summary['skillBreakdown']))
            <div class="linear-card p-5">
                <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider mb-4">Skill type averages</p>
                <div class="grid grid-cols-3 gap-6">
                    @foreach(['recall', 'analysis', 'application'] as $skill)
                        @php $val = $summary['skillBreakdown'][$skill] ?? null; @endphp
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-[12px] font-medium text-ink capitalize">{{ $skill }}</span>
                                <span class="text-[12px] text-ink-muted">{{ $val !== null ? $val.'%' : '—' }}</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden">
                                <div class="h-full rounded-full transition-all
                                    {{ $val !== null && $val < 40 ? 'bg-red-400' : ($val !== null && $val < 65 ? 'bg-yellow-400' : 'bg-accent') }}"
                                     style="width: {{ $val ?? 0 }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Weak axes --}}
        @if($weakAxes->isNotEmpty())
            <div class="linear-card overflow-hidden">
                <div class="px-5 py-4 border-b border-line">
                    <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">Weak axes (below {{ $threshold }}%)</p>
                </div>
                <div class="divide-y divide-line">
                    @foreach($weakAxes as $row)
                        <div class="px-5 py-3 flex items-center gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-[13px] font-medium text-ink truncate">{{ $row->axis->name ?? '—' }}</p>
                                <p class="text-[11px] text-ink-subtle">{{ $row->axis->category->name ?? '' }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-[13px] font-semibold {{ $row->avg_mastery < 40 ? 'text-red-400' : 'text-yellow-500' }}">
                                    {{ $row->avg_mastery }}%
                                </p>
                                <p class="text-[11px] text-ink-subtle">{{ $row->user_count }} {{ Str::plural('user', $row->user_count) }}</p>
                            </div>
                            <div class="w-24 shrink-0">
                                <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden">
                                    <div class="h-full rounded-full {{ $row->avg_mastery < 40 ? 'bg-red-400' : 'bg-yellow-400' }}"
                                         style="width: {{ $row->avg_mastery }}%"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- User detail drill-down --}}
        @if($viewingUserId && $viewingUser)
            <div class="linear-card overflow-hidden">
                <div class="px-5 py-4 border-b border-line flex items-center justify-between gap-4">
                    <div>
                        <p class="text-[13px] font-semibold text-ink">{{ $viewingUser->name }}</p>
                        <p class="text-[11px] text-ink-subtle">{{ $viewingUser->email }}</p>
                    </div>
                    <button wire:click="closeUserDetail"
                            class="text-[12px] text-ink-muted hover:text-ink transition-colors shrink-0">
                        &larr; Back to list
                    </button>
                </div>

                <div class="p-5 space-y-8">
                    {{-- Diagnostic profile(s) --}}
                    <div>
                        <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider mb-3">
                            Diagnostic Profile{{ $userDiagnosticProfiles->count() === 1 ? '' : 's' }}
                        </p>
                        @forelse($userDiagnosticProfiles as $d)
                            <div class="border border-line rounded-lg p-4 mb-3 last:mb-0">
                                <div class="flex items-center justify-between gap-4 mb-2">
                                    <p class="text-[13px] font-semibold text-ink">
                                        {{ $d['subject_name'] }}
                                        <span class="text-ink-subtle font-normal">— {{ $d['profile']['profile_title'] ?? ($d['profile']['player_type'] ?? 'Unclassified') }}</span>
                                    </p>
                                    <span class="text-[11px] text-ink-subtle shrink-0">{{ $d['completed_at'] }}</span>
                                </div>
                                @if($d['profile'])
                                    <pre class="text-[11px] text-ink-muted whitespace-pre-wrap break-words bg-surface-2 rounded p-3 overflow-x-auto max-h-96 overflow-y-auto">{{ json_encode($d['profile'], JSON_PRETTY_PRINT) }}</pre>
                                @else
                                    <p class="text-[12px] text-ink-subtle">No profile JSON stored for this completion.</p>
                                @endif
                            </div>
                        @empty
                            <p class="text-[12px] text-ink-subtle">No completed diagnostics for this user.</p>
                        @endforelse
                    </div>

                    {{-- Diagnostic answers: trait evidence --}}
                    <div>
                        <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider mb-3">
                            Diagnostic Answers — Trait Questions ({{ $userTraitEvidence->count() }})
                        </p>
                        @if($userTraitEvidence->isEmpty())
                            <p class="text-[12px] text-ink-subtle">No trait evidence recorded.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-line">
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Question</th>
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Selected Answer</th>
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Trait</th>
                                            <th class="px-3 py-2 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Points</th>
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider hidden sm:table-cell">Module</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-line">
                                        @foreach($userTraitEvidence as $e)
                                            <tr>
                                                <td class="px-3 py-2 text-[12px] text-ink max-w-xs">{{ $e->question?->question ?? '—' }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-muted">{{ $e->selected_answer }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-muted">{{ $e->trait?->name ?? $e->trait_id }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-muted text-right">{{ $e->points }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-subtle hidden sm:table-cell">{{ $e->module?->name ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- Diagnostic answers: self-reported survey --}}
                    <div>
                        <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider mb-3">
                            Diagnostic Answers — Survey ({{ $userProfileEvidence->count() }})
                        </p>
                        @if($userProfileEvidence->isEmpty())
                            <p class="text-[12px] text-ink-subtle">No survey evidence recorded.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-line">
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Question</th>
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Key</th>
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Answer</th>
                                            <th class="px-3 py-2 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-line">
                                        @foreach($userProfileEvidence as $e)
                                            <tr>
                                                <td class="px-3 py-2 text-[12px] text-ink max-w-xs">{{ $e->question?->question ?? '—' }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-muted">{{ $e->question_key }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-muted">{{ $e->answer_text }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-muted text-right">{{ $e->answer_value }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- Wrong answers on regular modules --}}
                    <div>
                        <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider mb-3">
                            Wrong Answers — Modules ({{ $userWrongAnswers->count() }})
                        </p>
                        @if($userWrongAnswers->isEmpty())
                            <p class="text-[12px] text-ink-subtle">No incorrect answers on record.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-line">
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Question</th>
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Type</th>
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Their Answer</th>
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Correct Answer</th>
                                            <th class="px-3 py-2 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Attempts</th>
                                            <th class="px-3 py-2 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider hidden lg:table-cell">Module(s)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-line">
                                        @foreach($userWrongAnswers as $q)
                                            <tr>
                                                <td class="px-3 py-2 text-[12px] text-ink max-w-xs">{{ $q->question }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-muted">{{ $q->type }}</td>
                                                <td class="px-3 py-2 text-[11px] text-red-400 font-mono max-w-[16rem] truncate" title="{{ json_encode($q->pivot->last_answer) }}">{{ json_encode($q->pivot->last_answer) }}</td>
                                                <td class="px-3 py-2 text-[11px] text-ink-muted font-mono max-w-[16rem] truncate" title="{{ json_encode($q->answer) }}">{{ json_encode($q->answer) }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-muted text-right">{{ $q->pivot->correct_count }}/{{ $q->pivot->attempts }}</td>
                                                <td class="px-3 py-2 text-[12px] text-ink-subtle hidden lg:table-cell">{{ $q->modules->pluck('name')->implode(', ') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @else

        {{-- Tabs --}}
        <div class="border-b border-line flex gap-6">
            <button wire:click="$set('tab', 'concepts')"
                    class="pb-2.5 text-[13px] font-medium border-b-2 transition-colors
                        {{ $tab === 'concepts' ? 'border-accent text-ink' : 'border-transparent text-ink-muted hover:text-ink' }}">
                By Concept
            </button>
            <button wire:click="$set('tab', 'users')"
                    class="pb-2.5 text-[13px] font-medium border-b-2 transition-colors
                        {{ $tab === 'users' ? 'border-accent text-ink' : 'border-transparent text-ink-muted hover:text-ink' }}">
                By User
            </button>
        </div>

        {{-- Concepts tab --}}
        @if($tab === 'concepts')
            <div class="linear-card overflow-hidden">
                <div class="px-5 py-4 border-b border-line">
                    <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">
                        Concepts averaging below {{ $threshold }}%
                    </p>
                </div>

                @if($weakConcepts->isEmpty())
                    <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No concepts below {{ $threshold }}% yet.</p>
                @else
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-line">
                                <th class="px-5 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Concept</th>
                                <th class="px-5 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider hidden sm:table-cell">Subject</th>
                                <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Avg mastery</th>
                                <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Users</th>
                                <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Struggling</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach($weakConcepts as $row)
                                <tr class="hover:bg-surface-2 transition-colors">
                                    <td class="px-5 py-3">
                                        <p class="text-[13px] font-medium text-ink">{{ $row->concept->name ?? '—' }}</p>
                                    </td>
                                    <td class="px-5 py-3 hidden sm:table-cell">
                                        <p class="text-[12px] text-ink-muted">{{ $row->concept->subject->name ?? '—' }}</p>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="text-[13px] font-semibold {{ $row->avg_mastery < 30 ? 'text-red-400' : ($row->avg_mastery < 50 ? 'text-yellow-500' : 'text-ink') }}">
                                                {{ $row->avg_mastery }}%
                                            </span>
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-right text-[12px] text-ink-muted">
                                        {{ $row->user_count }}
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        @if($row->struggling_count > 0)
                                            <span class="inline-block text-[11px] font-medium text-red-400 bg-red-400/10 rounded px-1.5 py-0.5">
                                                {{ $row->struggling_count }}
                                            </span>
                                        @else
                                            <span class="text-[12px] text-ink-subtle">0</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-5 py-3 border-t border-line">
                        {{ $weakConcepts->links() }}
                    </div>
                @endif
            </div>
        @endif

        {{-- Users tab --}}
        @if($tab === 'users')
            <div class="linear-card overflow-hidden">
                <div class="px-5 py-4 border-b border-line">
                    <p class="text-[12px] font-medium text-ink-muted uppercase tracking-wider">
                        Users with at least one weak concept
                    </p>
                </div>

                @if($weakUsers->isEmpty())
                    <p class="px-5 py-8 text-center text-[13px] text-ink-subtle">No users with weak concepts found.</p>
                @else
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-line">
                                <th class="px-5 py-2.5 text-left text-[11px] font-medium text-ink-subtle uppercase tracking-wider">User</th>
                                <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Avg mastery</th>
                                <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Concepts tracked</th>
                                <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider">Weak (&lt;50%)</th>
                                <th class="px-5 py-2.5 text-right text-[11px] font-medium text-ink-subtle uppercase tracking-wider"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach($weakUsers as $row)
                                <tr class="hover:bg-surface-2 transition-colors">
                                    <td class="px-5 py-3">
                                        <p class="text-[13px] font-medium text-ink">{{ $row->user->name ?? '—' }}</p>
                                        <p class="text-[11px] text-ink-subtle">{{ $row->user->email ?? '' }}</p>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <span class="text-[13px] font-semibold {{ $row->avg_mastery < 30 ? 'text-red-400' : ($row->avg_mastery < 50 ? 'text-yellow-500' : 'text-ink') }}">
                                            {{ $row->avg_mastery }}%
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-right text-[12px] text-ink-muted">
                                        {{ $row->concept_count }}
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        @if($row->weak_count > 0)
                                            <span class="inline-block text-[11px] font-medium text-red-400 bg-red-400/10 rounded px-1.5 py-0.5">
                                                {{ $row->weak_count }}
                                            </span>
                                        @else
                                            <span class="text-[12px] text-ink-subtle">0</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <button wire:click="viewUser({{ $row->user_id }})"
                                                class="text-[12px] font-medium text-accent hover:text-accent-hover transition-colors">
                                            View
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-5 py-3 border-t border-line">
                        {{ $weakUsers->links() }}
                    </div>
                @endif
            </div>
        @endif

        @endif

    </div>
</div>
