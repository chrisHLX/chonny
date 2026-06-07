<div class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-7xl mx-auto space-y-8">

        <div>
            <h1 class="text-[17px] font-semibold text-ink">Progress</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">Your active modules, earned cards, and question history.</p>
        </div>

        <x-context-bar-livewire :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" routeName="collection.index"/>

        {{-- Recommendation recovery --}}
        @if($this->latestSuggestion)
            @php
                $s   = $this->latestSuggestion;
                $rec = $s['recommendation'];
            @endphp
            <div class="linear-card overflow-hidden border border-accent/30">
                <div class="px-5 py-2.5 bg-accent/5 border-b border-accent/20">
                    <p class="text-[11px] font-semibold text-accent uppercase tracking-wide">Your Next Training Guide</p>
                </div>
                <div class="px-5 py-4 flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <p class="text-[14px] font-medium text-ink">{{ $rec['name'] }}</p>
                        @if(!empty($rec['description']))
                            <p class="text-[13px] text-ink-muted mt-0.5 line-clamp-2">{{ $rec['description'] }}</p>
                        @endif
                        @if(!empty($rec['reason']))
                            <p class="text-[12px] text-ink-subtle mt-2 border-t border-line pt-2 leading-relaxed">
                                <span class="font-medium text-ink-muted">Why: </span>{{ $rec['reason'] }}
                            </p>
                        @endif
                    </div>
                    <div class="shrink-0">
                        @if($s['enrolled'])
                            <a href="{{ route('questions.quiz.index', ['moduleId' => $s['module_id']]) }}"
                               class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                                @if($s['module_status'] === 'completed') Retake
                                @elseif($s['module_status'] === 'in_progress') Resume
                                @else Start Training
                                @endif
                            </a>
                        @elseif($s['exists'])
                            <form action="{{ route('modules.assign', $s['module_slug']) }}" method="POST">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                                    Add to My Guides
                                </button>
                            </form>
                        @else
                            <form action="{{ route('modules.create-suggested') }}" method="POST">
                                @csrf
                                <input type="hidden" name="suggestion"
                                       value="{{ json_encode(array_merge($rec, ['parent_id' => $s['source_module_id']])) }}">
                                <button type="submit"
                                        class="inline-flex items-center px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                                    Build This Guide
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Active Modules tray --}}
        <div>
            <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">Active Modules</p>
            @forelse($this->enrolledModules as $mod)
                @php $modStatus = $mod->pivot->status ?? 'not_started'; @endphp
                <div class="linear-card px-5 py-3.5 flex items-center justify-between gap-4 {{ !$loop->last ? 'mb-2' : '' }}">
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

        {{-- Cards grid --}}
        <div>
            <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">Earned Cards</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @forelse($this->cards as $card)
                    <div wire:click="selectCard({{ $card->id }})"
                         class="cursor-pointer transition-transform hover:scale-[1.02]
                             {{ $selectedCardId === $card->id ? 'ring-2 ring-accent rounded-xl' : '' }}">
                        <x-card :card="$card" class="flex-1 w-full"/>
                    </div>
                @empty
                    <div class="col-span-full linear-card p-8 text-center">
                        <p class="text-[13px] text-ink-subtle mb-1">No cards yet.</p>
                        <p class="text-[12px] text-ink-subtle">Finish a module to earn your first collectible card.</p>
                    </div>
                @endforelse
            </div>
        </div>

        @if($selectedCardId)
            {{-- Module Progress --}}
            <div class="linear-card p-6">
                <h3 class="page-section-title mb-4">Module Progress</h3>
                @forelse ($this->modules as $module)
                    <div class="{{ !$loop->last ? 'mb-5 pb-5 border-b border-line' : '' }}">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-[14px] font-medium text-ink">{{ $module->name }}</p>
                            <span class="{{ $module->pivot->score >= 80 ? 'badge-green' : 'badge-amber' }}">
                                {{ ucfirst($module->pivot->status) }}
                            </span>
                        </div>
                        @if($module->description)
                            <p class="text-[12px] text-ink-subtle mb-2">{{ $module->description }}</p>
                        @endif
                        <div class="w-full bg-surface-3 rounded-full h-1.5 overflow-hidden">
                            <div class="h-1.5 rounded-full bg-accent transition-all duration-500"
                                 style="width: {{ $module->pivot->score }}%"></div>
                        </div>
                        <p class="text-[11px] text-ink-subtle mt-1">{{ $module->pivot->score }}%</p>
                    </div>
                @empty
                    <p class="text-[13px] text-ink-subtle">No modules linked to this card yet.</p>
                @endforelse
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
                            <p class="text-[13px] text-ink-subtle py-4">No wrong questions for this card.</p>
                        @endforelse
                    @endif
                </div>
            </div>
        @endif

    </div>
</div>
