<div class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-7xl mx-auto space-y-8">

        <div>
            <h1 class="text-[17px] font-semibold text-ink">Progress</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">Your active modules, flagged questions, and question history.</p>
        </div>

        <x-context-bar-livewire :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" routeName="collection.index"/>

        {{-- Active Modules tray --}}
        <div>
            <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">Active Modules</p>
            @forelse($this->enrolledModules as $mod)
                @php $modStatus = $mod->pivot->status ?? 'not_started'; @endphp
                <div wire:click="selectModule({{ $mod->id }})"
                     class="linear-card px-5 py-3.5 flex items-center justify-between gap-4 cursor-pointer transition-colors
                         {{ !$loop->last ? 'mb-2' : '' }}
                         {{ $selectedModuleId === $mod->id ? 'ring-2 ring-accent' : '' }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] font-medium text-ink truncate">{{ $mod->name }}</p>
                        <div class="flex items-center gap-2 mt-1">
                            <div class="flex-1 max-w-[120px] bg-surface-3 rounded-full h-1 overflow-hidden">
                                <div class="h-1 rounded-full bg-accent transition-all"
                                     style="width: {{ $mod->pivot->score ?? 0 }}%"></div>
                            </div>
                            <span class="text-[11px] text-ink-subtle tabular-nums">{{ $mod->pivot->score ?? 0 }}%</span>
                            <span class="text-[10px] px-1.5 py-0.5 rounded font-medium
                                {{ $modStatus === 'completed' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400' }}">
                                {{ ucfirst(str_replace('_', ' ', $modStatus)) }}
                            </span>
                        </div>
                    </div>
                    <a href="{{ route('questions.quiz.index', ['moduleId' => $mod->id]) }}"
                       class="shrink-0 inline-flex items-center gap-1 px-3 py-1.5 text-[12px] font-medium rounded transition-colors
                           {{ $modStatus === 'completed'
                              ? 'text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line'
                              : 'text-white bg-accent hover:bg-accent-hover' }}">
                        {{ $modStatus === 'not_started' ? 'Start' : ($modStatus === 'completed' ? 'Retake' : 'Resume') }}
                    </a>
                </div>
            @empty
                <div class="linear-card px-5 py-8 text-center">
                    <p class="text-[13px] text-ink-subtle mb-3">No modules enrolled yet.</p>
                    <a href="{{ route('modules.index') }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                        Discover Modules
                    </a>
                </div>
            @endforelse
        </div>

        {{-- Your Library: questions flagged as personally important while taking a quiz.
             Always visible, not gated behind selecting a module — subject-scoped like the tray
             above, not module-scoped, since this is meant to feel like a standalone thing worth
             remembering rather than something buried behind browsing a specific module. --}}
        <div>
            <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">Your Library</p>
            <div class="linear-card overflow-hidden">
                @forelse($this->flaggedQuestions as $question)
                    <div class="{{ !$loop->last ? 'border-b border-line' : '' }} px-5 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-[13px] text-ink mb-2">{{ $question->question }}</p>
                                @if($question->concepts->isNotEmpty())
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($question->concepts as $concept)
                                            <span class="badge-gray">{{ $concept->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @if(isset($flaggedExplanations[$question->id]))
                                    <p class="text-[12px] text-ink-muted mt-3 leading-relaxed border-t border-line pt-2">
                                        {{ $flaggedExplanations[$question->id] }}
                                    </p>
                                @endif
                            </div>
                            <div class="shrink-0 flex items-center gap-2">
                                @if(!isset($flaggedExplanations[$question->id]))
                                    <button wire:click="explainQuestion({{ $question->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="explainQuestion({{ $question->id }})"
                                            class="text-[11px] font-medium text-accent hover:text-accent-hover px-2 py-1 rounded border border-line hover:bg-surface-2 transition-colors disabled:opacity-50">
                                        <span wire:loading.remove wire:target="explainQuestion({{ $question->id }})">Explain</span>
                                        <span wire:loading wire:target="explainQuestion({{ $question->id }})">…</span>
                                    </button>
                                @endif
                                <button wire:click="unflagQuestion({{ $question->id }})"
                                        class="text-ink-subtle hover:text-red-400 transition-colors p-1"
                                        title="Unflag">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center">
                        <p class="text-[13px] text-ink-subtle mb-1">Nothing flagged yet.</p>
                        <p class="text-[12px] text-ink-subtle">Star a question while taking a quiz to remember it here.</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Your Path: a chronological, subject-scoped record of real events only — diagnostics
             completed, questions flagged, modules completed, quest chains concluded. No
             fabricated scores or trend lines; see Collection.php's getAllTimelineEventsProperty()
             for exactly what feeds each entry. --}}
        <div>
            <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">Your Path</p>
            <div class="linear-card p-5">
                @forelse($this->timelineEvents as $event)
                    <div class="relative pl-10 {{ !$loop->last ? 'pb-6' : '' }}">
                        @unless($loop->last)
                            <div class="absolute left-4 top-8 bottom-0 w-px bg-line"></div>
                        @endunless
                        <div class="absolute left-0 top-0 w-8 h-8 rounded-full bg-surface-2 border border-line flex items-center justify-center">
                            <x-mc-icon :name="$event['icon']" class="w-4 h-4 text-gold"/>
                        </div>
                        <div class="flex items-center justify-between gap-3 mb-0.5">
                            <p class="text-[13px] font-medium text-ink">{{ $event['title'] }}</p>
                            <span class="text-[11px] text-ink-subtle shrink-0 tabular-nums">{{ $event['timestamp']->diffForHumans() }}</span>
                        </div>
                        <p class="text-[12px] text-ink-muted leading-relaxed line-clamp-2">{{ $event['detail'] }}</p>
                    </div>
                @empty
                    <div class="text-center py-8">
                        <p class="text-[13px] text-ink-subtle mb-1">Nothing on your path yet.</p>
                        <p class="text-[12px] text-ink-subtle">Complete a diagnostic, finish a module, or flag a question to start building your history.</p>
                    </div>
                @endforelse

                @if($this->timelineTotalCount > $timelineLimit)
                    <div class="text-center pt-4 {{ $this->timelineEvents->isNotEmpty() ? 'mt-2 border-t border-line' : '' }}">
                        <button wire:click="loadMoreTimeline"
                                wire:loading.attr="disabled"
                                wire:target="loadMoreTimeline"
                                class="text-[12px] font-medium text-accent hover:text-accent-hover px-3 py-2 disabled:opacity-50">
                            <span wire:loading.remove wire:target="loadMoreTimeline">Load more ({{ $this->timelineTotalCount - $timelineLimit }} more)</span>
                            <span wire:loading wire:target="loadMoreTimeline">Loading…</span>
                        </button>
                    </div>
                @endif
            </div>
        </div>

        @if($this->selectedModule)
            {{-- Module Progress --}}
            <div class="linear-card p-6">
                <h3 class="page-section-title mb-4">Module Progress</h3>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-[14px] font-medium text-ink">{{ $this->selectedModule->name }}</p>
                        <span class="{{ ($this->selectedModule->pivot->score ?? 0) >= 80 ? 'badge-green' : 'badge-amber' }}">
                            {{ ucfirst($this->selectedModule->pivot->status ?? 'not_started') }}
                        </span>
                    </div>
                    @if($this->selectedModule->description)
                        <p class="text-[12px] text-ink-subtle mb-2">{{ $this->selectedModule->description }}</p>
                    @endif
                    <div class="w-full bg-surface-3 rounded-full h-1.5 overflow-hidden">
                        <div class="h-1.5 rounded-full bg-accent transition-all duration-500"
                             style="width: {{ $this->selectedModule->pivot->score ?? 0 }}%"></div>
                    </div>
                    <p class="text-[11px] text-ink-subtle mt-1">{{ $this->selectedModule->pivot->score ?? 0 }}%</p>
                </div>
            </div>

            {{-- Questions tabs --}}
            <div class="linear-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="page-section-title">Questions</h3>
                    <div class="flex gap-1">
                        <button wire:click="$set('activeQuestionTab', 'history')"
                                class="tab-btn {{ $activeQuestionTab === 'history' ? 'tab-active' : 'tab-inactive' }}">
                            History
                        </button>
                        <button wire:click="$set('activeQuestionTab', 'wrong')"
                                class="tab-btn {{ $activeQuestionTab === 'wrong' ? 'tab-active' : 'tab-inactive' }}">
                            Wrong
                        </button>
                    </div>
                </div>

                <div class="space-y-0">
                    @if($activeQuestionTab === 'history')
                        @forelse ($this->answeredQuestions as $question)
                            <div class="{{ !$loop->last ? 'border-b border-line' : '' }} py-3">
                                <p class="text-[13px] text-ink mb-2">{{ $question->question }}</p>

                                {{-- Correct answer --}}
                                @php $ans = $question->answer; @endphp
                                @if($ans)
                                    <div class="mb-2">
                                        @if($question->type === 'mcq')
                                            <p class="text-[12px] text-ink-subtle">
                                                <span class="text-ink-muted font-medium">Answer:</span>
                                                {{ $ans['correct'] ?? '—' }}
                                            </p>
                                        @elseif($question->type === 'true_false')
                                            <p class="text-[12px] text-ink-subtle">
                                                <span class="text-ink-muted font-medium">Answer:</span>
                                                {{ isset($ans['correct']) ? ($ans['correct'] ? 'True' : 'False') : '—' }}
                                            </p>
                                        @elseif($question->type === 'open')
                                            @if(!empty($ans['ideal_answer']))
                                                <p class="text-[12px] text-ink-subtle">
                                                    <span class="text-ink-muted font-medium">Answer:</span>
                                                    {{ $ans['ideal_answer'] }}
                                                </p>
                                            @elseif(!empty($ans['correct_keywords']))
                                                <p class="text-[12px] text-ink-subtle">
                                                    <span class="text-ink-muted font-medium">Keywords:</span>
                                                    {{ implode(', ', $ans['correct_keywords']) }}
                                                </p>
                                            @endif
                                        @elseif($question->type === 'ordering')
                                            @if(!empty($ans['steps']))
                                                <p class="text-[12px] text-ink-subtle">
                                                    <span class="text-ink-muted font-medium">Order:</span>
                                                    {{ implode(' → ', $ans['steps']) }}
                                                </p>
                                            @endif
                                        @elseif($question->type === 'matching_pairs')
                                            @if(!empty($ans['correct']))
                                                <div class="text-[12px] text-ink-subtle">
                                                    <span class="text-ink-muted font-medium">Pairs:</span>
                                                    <span>{{ collect($ans['correct'])->map(fn($v, $k) => "$k → $v")->join(', ') }}</span>
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                @endif

                                <div class="flex flex-wrap gap-2">
                                    <span class="badge-gray">{{ $question->pivot->attempts }} attempts</span>
                                    <span class="badge-green">{{ $question->pivot->correct_count }} correct</span>
                                    <span class="badge-blue">
                                        {{ $question->pivot->attempts > 0
                                            ? round(($question->pivot->correct_count / $question->pivot->attempts) * 100, 1)
                                            : 0 }}% accuracy
                                    </span>
                                    <span class="badge-gray">{{ $question->pivot->total_time_spent }}s</span>
                                    @if($question->concepts->isNotEmpty())
                                        <span class="badge-gray">{{ $question->concepts->pluck('name')->join(', ') }}</span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-[13px] text-ink-subtle py-4">No questions attempted yet.</p>
                        @endforelse
                    @endif

                    @if($activeQuestionTab === 'wrong')
                        @forelse ($this->wrongQuestions as $question)
                            <div class="{{ !$loop->last ? 'border-b border-line' : '' }} py-3">
                                <p class="text-[13px] text-ink mb-1">{{ $question->question }}</p>
                                @php $content = $question->contents->first(); @endphp
                                @if($content)
                                    <p class="text-[12px] text-ink-muted mb-0.5">{{ $content->content }}</p>
                                    <p class="text-[11px] text-ink-subtle">Source: {{ $content->source }}</p>
                                @endif
                            </div>
                        @empty
                            <p class="text-[13px] text-ink-subtle py-4">No wrong questions for this module.</p>
                        @endforelse
                    @endif
                </div>
            </div>
        @endif

    </div>
</div>
