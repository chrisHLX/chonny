<div class="py-6 max-w-2xl mx-auto">

    {{-- FULL QUIZ COMPLETION SCREEN --}}
    @if ($quizFullyComplete)
        <div class="space-y-3">

            {{-- Polling trigger: hidden element removed from DOM once suggestions arrive --}}
            @if ($suggestionsStatus === 'loading')
                <span wire:poll.3s="checkSuggestions" class="hidden"></span>
            @endif

            {{-- Header --}}
            <div class="linear-card p-6 text-center">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-500/15 border border-emerald-500/20 mb-4">
                    <svg class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h2 class="text-[18px] font-semibold text-ink mb-1">Module Complete</h2>
                <p class="text-[13px] text-ink-subtle">{{ $completionStats['module_name'] ?? '' }}</p>
            </div>

            {{-- Stats row --}}
            <div class="grid grid-cols-3 gap-3">
                <div class="linear-card p-4 text-center">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Score</p>
                    <p class="text-xl font-semibold {{ ($completionStats['score_percent'] ?? 0) >= 70 ? 'text-emerald-400' : 'text-amber-400' }}">
                        {{ $completionStats['score_percent'] ?? 0 }}%
                    </p>
                </div>
                <div class="linear-card p-4 text-center">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Questions</p>
                    <p class="text-xl font-semibold text-ink">{{ $completionStats['questions_count'] ?? 0 }}</p>
                </div>
                <div class="linear-card p-4 text-center">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Time</p>
                    <p class="text-xl font-semibold text-accent">{{ $this->totalTime }}s</p>
                </div>
            </div>

            {{-- Strengths & Needs Improvement --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="linear-card p-5">
                    <p class="text-[11px] font-semibold text-emerald-400 uppercase tracking-wide mb-3">Strengths</p>
                    @if (!empty($completionStats['strong_concepts']))
                        <ul class="space-y-2">
                            @foreach($completionStats['strong_concepts'] as $concept)
                                <li class="flex items-center gap-2.5 text-[13px] text-ink">
                                    <svg class="w-3.5 h-3.5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    {{ $concept }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-[13px] text-ink-muted italic">No clear strengths yet — keep practicing.</p>
                    @endif
                </div>

                <div class="linear-card p-5">
                    <p class="text-[11px] font-semibold text-red-400 uppercase tracking-wide mb-3">Needs Improvement</p>
                    @if (!empty($completionStats['weak_concepts']))
                        <ul class="space-y-2">
                            @foreach($completionStats['weak_concepts'] as $concept)
                                <li class="flex items-center gap-2.5 text-[13px] text-ink">
                                    <svg class="w-3.5 h-3.5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                    {{ $concept }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-[13px] text-ink-muted italic">No weak areas detected — great work.</p>
                    @endif
                </div>
            </div>

            {{-- Recommended Next Module --}}
            <div class="linear-card p-5">
                <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-4">Recommended Next Module</p>

                @if ($suggestionsStatus === 'loading')
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-4 h-4 border-2 border-accent border-t-transparent rounded-full animate-spin shrink-0"></div>
                        <p class="text-[13px] text-ink-muted">Analysing your performance and generating recommendations...</p>
                    </div>
                    <div class="space-y-2.5 animate-pulse">
                        <div class="h-4 bg-surface-3 rounded w-3/5"></div>
                        <div class="h-3 bg-surface-3 rounded w-full"></div>
                        <div class="h-3 bg-surface-3 rounded w-4/5"></div>
                        <div class="h-3 bg-surface-3 rounded w-2/3"></div>
                    </div>

                @elseif ($suggestionsStatus === 'ready' && !empty($suggestions))
                    @php $rec = $suggestions; @endphp
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <h3 class="text-[15px] font-semibold text-ink leading-snug">{{ $rec['name'] ?? 'Next Module' }}</h3>
                            @if (!empty($rec['proficiency']))
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-accent/10 text-accent border border-accent/20 shrink-0 whitespace-nowrap">
                                    {{ $rec['proficiency'] }}
                                </span>
                            @endif
                        </div>
                        @if (!empty($rec['description']))
                            <p class="text-[13px] text-ink-muted leading-relaxed mb-3">{{ $rec['description'] }}</p>
                        @endif
                        @if (!empty($rec['reason']))
                            <div class="border-t border-line pt-3">
                                <p class="text-[12px] text-ink-subtle leading-relaxed">
                                    <span class="text-ink-muted font-medium">Why this module: </span>
                                    {{ $rec['reason'] }}
                                </p>
                            </div>
                        @elseif (!empty($completionStats['weak_concepts']))
                            <div class="border-t border-line pt-3">
                                <p class="text-[12px] text-ink-subtle leading-relaxed">
                                    <span class="text-ink-muted font-medium">Why this module: </span>
                                    You struggled with <span class="text-ink font-medium">{{ implode(', ', array_slice($completionStats['weak_concepts'], 0, 2)) }}</span>.
                                    This module is designed to strengthen those areas.
                                </p>
                            </div>
                        @endif
                    </div>

                @else
                    <p class="text-[13px] text-ink-muted">Unable to generate recommendations right now. Explore the module library for your next challenge.</p>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex flex-col gap-2 pt-1">
                @if ($suggestionsStatus === 'ready' && !empty($suggestions))
                    <a href="{{ route('modules.next-module', $this->moduleId) }}"
                       class="inline-flex items-center justify-center gap-2 w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                        Continue Learning
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                @endif

                @if (!empty($completionStats['module_slug']))
                    <a href="{{ route('modules.show', $completionStats['module_slug']) }}"
                       class="inline-flex items-center justify-center w-full py-2.5 text-[13px] font-medium text-ink-muted border border-line hover:bg-surface-2 rounded-md transition-colors">
                        Return to Module
                    </a>
                @endif

                <a href="{{ route('collection.index') }}"
                   class="inline-flex items-center justify-center w-full py-2.5 text-[13px] font-medium text-ink-muted border border-line hover:bg-surface-2 rounded-md transition-colors">
                    Return to Collection
                </a>

                <a href="{{ route('modules.index') }}"
                   class="inline-flex items-center justify-center w-full py-2 text-[13px] font-medium text-ink-subtle hover:text-ink transition-colors">
                    Explore Other Modules
                </a>
            </div>

        </div>

    {{-- BETWEEN-TIER COMPLETION SCREEN --}}
    @elseif ($completed && !$quizFullyComplete)
        <div class="linear-card p-6" x-transition>
            <h2 class="text-[17px] font-semibold text-ink text-center mb-6">Level Complete</h2>

            <div class="grid grid-cols-2 gap-3 mb-6">
                <div class="bg-emerald-500/10 border border-emerald-500/20 rounded-lg p-4 text-center">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Score</p>
                    <p class="text-2xl font-semibold text-emerald-400">{{ $score }} / {{ $questions->count() }}</p>
                </div>
                <div class="bg-accent/10 border border-accent/20 rounded-lg p-4 text-center">
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Time</p>
                    <p class="text-2xl font-semibold text-accent">{{ $this->totalTime }}s</p>
                </div>
            </div>

                @if (count($wrongQuestions ?? []) > 0)
                <div class="bg-red-500/10 border border-red-500/20 rounded-lg p-4 mb-6">
                    <p class="text-[12px] font-medium text-red-400 mb-3">Incorrect answers</p>
                    <ul class="space-y-2">
                        @foreach ($wrongQuestions as $q)
                            <li class="text-[13px] text-ink-muted">{{ $q->question }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <button wire:click="nextLevel"
                    class="w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                Next Level
            </button>
        </div>

    {{-- QUESTION SCREEN --}}
    @elseif (!empty($questions) && $questions->count() > $currentIndex)
        @php $question = $questions[$currentIndex]; @endphp

        {{-- Header: proficiency + progress --}}
        <div class="mb-4">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[12px] text-ink-subtle">{{ $proficiency ?? '' }}</span>
                <span class="text-[12px] text-ink-subtle">{{ $currentIndex + 1 }} / {{ $questions->count() }}</span>
            </div>
            <div class="w-full bg-surface-3 rounded-full h-1 overflow-hidden">
                <div class="bg-accent h-1 rounded-full transition-all duration-500"
                     style="width: {{ (($currentIndex + 1) / $questions->count()) * 100 }}%"></div>
            </div>
            <div class="flex justify-end mt-1">
                <span class="text-[11px] text-ink-subtle">{{ $difficulty }}</span>
            </div>
        </div>

        {{-- Question card --}}
        <div class="linear-card p-6" wire:key="question-{{ $question->id }}" x-transition>
            <p class="text-[15px] font-medium text-ink leading-relaxed mb-5">
                <span class="text-accent font-semibold mr-1">{{ $currentIndex + 1 }}.</span>
                {{ $question->question }}
            </p>

            <form x-data="{ elapsed: 0 }"
                  x-init="setInterval(() => elapsed++, 1000)"
                  x-on:submit.prevent="$wire.submit({ elapsed })">

                @switch($question->type)
                    @case('mcq')
                        <div class="space-y-2">
                            @foreach ($question->answer['options'] as $option)
                                <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                                       :class="$wire.answer === @js($option)
                                           ? 'border-accent bg-accent/5 text-ink'
                                           : 'border-line text-ink-muted hover:bg-surface-2 hover:border-line-strong'">
                                    <div class="w-3.5 h-3.5 rounded-full border-2 shrink-0 transition-colors"
                                         :class="$wire.answer === @js($option) ? 'border-accent bg-accent' : 'border-line-strong'"></div>
                                    <input type="radio" wire:model="answer" value="{{ $option }}" class="sr-only">
                                    <span class="text-[13px]">{{ $option }}</span>
                                </label>
                            @endforeach
                        </div>
                        @break

                    @case('true_false')
                        <div class="space-y-2">
                            @foreach(['true' => 'True', 'false' => 'False'] as $val => $label)
                                <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                                       :class="$wire.answer === @js($val)
                                           ? 'border-accent bg-accent/5 text-ink'
                                           : 'border-line text-ink-muted hover:bg-surface-2 hover:border-line-strong'">
                                    <div class="w-3.5 h-3.5 rounded-full border-2 shrink-0 transition-colors"
                                         :class="$wire.answer === @js($val) ? 'border-accent bg-accent' : 'border-line-strong'"></div>
                                    <input type="radio" wire:model="answer" value="{{ $val }}" class="sr-only">
                                    <span class="text-[13px]">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @break

                    @case('open')
                        <textarea wire:model="answer" rows="3" required
                                  placeholder="Type your answer..."
                                  class="form-textarea mt-1"></textarea>
                        @break

                    @case('matching_pairs')
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-2 pb-2 border-b border-line">Items</p>
                                <ul class="space-y-2">
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li class="px-3 py-2 text-[13px] text-ink bg-surface-2 border border-line rounded-md">{{ $key }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-2 pb-2 border-b border-line">Match To</p>
                                <ul class="space-y-2">
                                    @foreach ($question->answer['pairs']['keys'] as $key)
                                        <li>
                                            <select wire:model="answer.{{ $key }}" class="form-select">
                                                <option value="">— Select —</option>
                                                @foreach ($question->answer['pairs']['values'] as $value)
                                                    <option value="{{ $value }}">{{ $value }}</option>
                                                @endforeach
                                            </select>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                        @break

                    @case('ordering')
                        @if(!empty($question->answer['steps']))
                        <ul id="ordering-list-{{ $question->id }}" class="ordering-list" x-sortable wire:ignore
                            x-init="$wire.set('answer', [...$el.children].map(e => e.dataset.value))"
                            x-on:sorted="$wire.set('answer', [...$el.children].map(e => e.dataset.value))">
                            @foreach($question->answer['steps'] as $step)
                                <li class="ordering-item" data-value="{{ $step }}">
                                    <svg class="w-3.5 h-3.5 text-ink-subtle shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
                                    </svg>
                                    {{ $step }}
                                </li>
                            @endforeach
                        </ul>
                        @else
                            <p class="text-[13px] text-ink-subtle">This question has no steps configured.</p>
                        @endif
                        @break
                @endswitch

                <div class="mt-5">
                    <button type="submit"
                            class="w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            :disabled="!$wire.answer && !$el.closest('form').querySelector('.ordering-list')">
                        Submit Answer
                    </button>
                </div>
            </form>
        </div>

    @else
        {{-- Questions not yet loaded or feedback state --}}
        @if ($feedback)
            <div class="linear-card p-6 text-center">
                <p class="text-[13px] text-ink-muted">{{ $feedback }}</p>
            </div>
        @else
            <div class="linear-card p-6 text-center">
                <p class="text-[13px] text-ink-muted">Loading...</p>
            </div>
        @endif
    @endif
</div>
