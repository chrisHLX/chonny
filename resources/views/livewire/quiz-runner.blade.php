<div class="py-6 max-w-2xl mx-auto">

    {{-- FULL QUIZ COMPLETION SCREEN --}}
    @if ($quizFullyComplete)
        <div class="space-y-3">

            {{-- Header --}}
            <div class="linear-card p-6 text-center relative overflow-hidden">
                {{-- bg-arch decoration --}}
                <div class="absolute inset-0 opacity-20 pointer-events-none select-none text-gold" aria-hidden="true">
                    <svg class="absolute inset-0 w-full h-full" viewBox="-144 -144 288 166" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M-140,0 A140,140 0 0,1 140,0" stroke="currentColor" stroke-width="1" stroke-linecap="round"/>
                        <path d="M-110,0 A110,110 0 0,1 110,0" stroke="currentColor" stroke-width="0.8" stroke-linecap="round"/>
                        <path d="M-80,0 A80,80 0 0,1 80,0" stroke="currentColor" stroke-width="0.6" stroke-linecap="round"/>
                        <line x1="0" y1="-140" x2="0" y2="-60" stroke="currentColor" stroke-width="0.8" stroke-linecap="round"/>
                        <line x1="-69.3" y1="-120" x2="-30" y2="-52" stroke="currentColor" stroke-width="0.6" stroke-linecap="round"/>
                        <line x1="69.3" y1="-120" x2="30" y2="-52" stroke="currentColor" stroke-width="0.6" stroke-linecap="round"/>
                        <line x1="-120" y1="-69.3" x2="-52" y2="-30" stroke="currentColor" stroke-width="0.5" stroke-linecap="round"/>
                        <line x1="120" y1="-69.3" x2="52" y2="-30" stroke="currentColor" stroke-width="0.5" stroke-linecap="round"/>
                        <line x1="-140" y1="0" x2="-60" y2="0" stroke="currentColor" stroke-width="0.5" stroke-linecap="round"/>
                        <line x1="60" y1="0" x2="140" y2="0" stroke="currentColor" stroke-width="0.5" stroke-linecap="round"/>
                        <circle cx="0" cy="0" r="18" stroke="currentColor" stroke-width="1.2"/>
                        <circle cx="0" cy="0" r="10" stroke="currentColor" stroke-width="0.8"/>
                        <circle cx="0" cy="0" r="3" fill="currentColor"/>
                        <circle cx="0" cy="-140" r="2" fill="currentColor"/>
                        <circle cx="-69.3" cy="-120" r="1.5" fill="currentColor"/>
                        <circle cx="69.3" cy="-120" r="1.5" fill="currentColor"/>
                        <circle cx="-120" cy="-69.3" r="1.5" fill="currentColor"/>
                        <circle cx="120" cy="-69.3" r="1.5" fill="currentColor"/>
                    </svg>
                </div>
                <x-ornament.corner position="tl" class="top-0 left-0 w-10 h-10 text-gold/50"/>
                <x-ornament.corner position="tr" class="top-0 right-0 w-10 h-10 text-gold/50"/>
                <x-ornament.corner position="bl" class="bottom-0 left-0 w-10 h-10 text-gold/50"/>
                <x-ornament.corner position="br" class="bottom-0 right-0 w-10 h-10 text-gold/50"/>
                <x-mc-icon name="icon-complete" class="w-12 h-12 text-emerald-400 mb-4"/>
                <h2 class="text-[18px] font-semibold text-ink mb-1">Guide Complete</h2>
                <p class="text-[13px] text-ink-subtle">{{ $completionStats['module_name'] ?? '' }}</p>
            </div>

            {{-- Stats row --}}
            <div class="grid grid-cols-3 gap-3">
                <div class="linear-card p-4 text-center relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-mc-icon name="icon-compass" class="w-6 h-6 text-gold opacity-60 mb-2"/>
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Score</p>
                    <p class="text-xl font-semibold {{ ($completionStats['score_percent'] ?? 0) >= 70 ? 'text-emerald-400' : 'text-amber-400' }}">
                        {{ $completionStats['score_percent'] ?? 0 }}%
                    </p>
                </div>
                <div class="linear-card p-4 text-center relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-mc-icon name="icon-scroll" class="w-6 h-6 text-gold opacity-60 mb-2"/>
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Questions</p>
                    <p class="text-xl font-semibold text-ink">{{ $completionStats['questions_count'] ?? 0 }}</p>
                </div>
                <div class="linear-card p-4 text-center relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-mc-icon name="icon-hourglass" class="w-6 h-6 text-gold opacity-60 mb-2"/>
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Time</p>
                    <p class="text-xl font-semibold text-accent">{{ sprintf('%d:%02d', intdiv($this->totalTime, 60), $this->totalTime % 60) }}</p>
                </div>
            </div>

            {{-- Strengths & Needs Improvement --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="linear-card p-5 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-emerald-400/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-emerald-400/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-emerald-400/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-emerald-400/20"/>
                    <div class="flex items-center gap-2 mb-3">
                        <x-mc-icon name="icon-leaf" class="w-8 h-8 text-emerald-400 opacity-70"/>
                        <p class="text-[11px] font-semibold text-emerald-400 uppercase tracking-wide">Strengths</p>
                    </div>
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

                <div class="linear-card p-5 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-red-400/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-red-400/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-red-400/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-red-400/20"/>
                    <div class="flex items-center gap-2 mb-3">
                        <x-mc-icon name="icon-starburst" class="w-8 h-8 text-red-400 opacity-70"/>
                        <p class="text-[11px] font-semibold text-red-400 uppercase tracking-wide">Needs Improvement</p>
                    </div>
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

            {{-- Guest: sign-up CTA | Auth: next module suggestions --}}
            @if ($guestMode)
                <div class="linear-card p-6 text-center border border-accent/30 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/30"/>
                    <x-mc-icon name="icon-lightning-circle" class="w-10 h-10 text-gold mb-3"/>
                    <h3 class="text-[16px] font-semibold text-ink mb-2">Save your results &amp; keep improving</h3>
                    <p class="text-[13px] text-ink-muted leading-relaxed mb-5">
                        Create a free account to save this score, track your weaknesses over time, and unlock a personalised learning path.
                    </p>
                    <a href="{{ route('register') }}"
                       class="inline-flex items-center justify-center gap-2 w-full py-2.5 text-[13px] font-semibold text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                        Sign up — it's free
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                    <a href="{{ route('login') }}"
                       class="block mt-2 text-[12px] text-ink-subtle hover:text-ink transition-colors">
                        Already have an account? Log in
                    </a>
                </div>
            @else
                <x-quiz.concept-diagnostic-recap :module-id="$moduleId" />
            @endif

            {{-- Actions --}}
            <div class="flex flex-col gap-2 pt-1">
                @if (!$guestMode)
                    <a href="{{ route('dashboard') }}"
                       class="inline-flex items-center justify-center gap-2 w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                        Continue Learning
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                @endif

                <button wire:click="retake"
                        wire:loading.attr="disabled"
                        wire:target="retake"
                        class="inline-flex items-center justify-center w-full py-2.5 text-[13px] font-medium text-ink-muted border border-line hover:bg-surface-2 rounded-md transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="retake">Retake Quiz</span>
                    <span wire:loading wire:target="retake">Starting…</span>
                </button>

                @if (!empty($completionStats['module_slug']))
                    <a href="{{ route('modules.show', $completionStats['module_slug']) }}"
                       class="inline-flex items-center justify-center w-full py-2.5 text-[13px] font-medium text-ink-muted border border-line hover:bg-surface-2 rounded-md transition-colors">
                        Return to Guide
                    </a>
                @endif

                @if (!$guestMode)
                    <a href="{{ route('collection.index') }}"
                       class="inline-flex items-center justify-center w-full py-2.5 text-[13px] font-medium text-ink-muted border border-line hover:bg-surface-2 rounded-md transition-colors">
                        My Guides
                    </a>
                @endif

                <a href="{{ route('modules.index') }}"
                   class="inline-flex items-center justify-center w-full py-2 text-[13px] font-medium text-ink-subtle hover:text-ink transition-colors">
                    Browse Guides
                </a>
            </div>

        </div>

    {{-- BETWEEN-TIER COMPLETION SCREEN --}}
    @elseif ($completed && !$quizFullyComplete)
        <div class="linear-card p-6 relative overflow-hidden" x-transition>
            <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/40"/>
            <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/40"/>
            <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/40"/>
            <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/40"/>
            <h2 class="text-[17px] font-semibold text-ink text-center mb-6">Level Complete</h2>

            <div class="grid grid-cols-2 gap-3 mb-6">
                <div class="linear-card p-4 text-center relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-mc-icon name="icon-compass" class="w-6 h-6 text-gold opacity-60 mb-2"/>
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Score</p>
                    <p class="text-xl font-semibold text-emerald-400">{{ $score }} / {{ $questions->count() }}</p>
                </div>
                <div class="linear-card p-4 text-center relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-6 h-6 text-gold/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-6 h-6 text-gold/30"/>
                    <x-mc-icon name="icon-hourglass" class="w-6 h-6 text-gold opacity-60 mb-2"/>
                    <p class="text-[11px] text-ink-subtle uppercase tracking-wide mb-1">Time</p>
                    <p class="text-xl font-semibold text-accent">{{ sprintf('%d:%02d', intdiv($this->totalTime, 60), $this->totalTime % 60) }}</p>
                </div>
            </div>

            @if (count($wrongQuestions ?? []) > 0)
                <div class="linear-card p-4 mb-6 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-red-400/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-6 h-6 text-red-400/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-6 h-6 text-red-400/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-6 h-6 text-red-400/20"/>
                    <p class="text-[12px] font-medium text-red-400 mb-3">Review these</p>
                    <ul class="space-y-2">
                        @foreach ($wrongQuestions as $q)
                            <li class="text-[13px] text-ink-muted">{{ $q->question }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <button wire:click="nextLevel"
                    class="w-full py-2.5 text-[13px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200">
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
        <div class="linear-card p-6 relative overflow-hidden" wire:key="question-{{ $question->id }}" x-transition>
            <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/40"/>
            <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/40"/>
            <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/40"/>
            <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/40"/>

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
                            @foreach ($shuffledOptions[$question->id]['options'] ?? $question->answer['options'] as $option)
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
                                                @foreach ($shuffledOptions[$question->id]['values'] ?? $question->answer['pairs']['values'] as $value)
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
                        @php $orderingSteps = $shuffledOptions[$question->id]['steps'] ?? $question->answer['steps'] ?? []; @endphp
                        @if(!empty($orderingSteps))
                        <p class="text-[11px] text-ink-subtle mb-2">Drag items into the correct order.</p>
                        <ul id="ordering-list-{{ $question->id }}" class="ordering-list" x-sortable wire:ignore
                            x-init="$wire.set('answer', [...$el.children].map(e => e.dataset.value))"
                            x-on:sorted="$wire.set('answer', [...$el.children].map(e => e.dataset.value))">
                            @foreach($orderingSteps as $step)
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
                            wire:loading.attr="disabled"
                            wire:target="submit"
                            class="w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                            :disabled="!$wire.answer && !$el.closest('form').querySelector('.ordering-list')">
                        <span wire:loading.remove wire:target="submit">Submit Answer</span>
                        <span wire:loading wire:target="submit" class="flex items-center gap-2">
                            <span class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                            Submitting…
                        </span>
                    </button>
                </div>
            </form>

            @unless ($guestMode)
                @php $isFlagged = $this->flaggedQuestionIds->contains($question->id); @endphp
                <button type="button"
                        wire:click="toggleFlag({{ $question->id }})"
                        class="mt-3 w-full flex items-center justify-center gap-2 py-2.5 text-[12px] font-medium rounded-md border transition-colors
                            {{ $isFlagged
                                ? 'border-gold/40 bg-gold/10 text-gold'
                                : 'border-line text-ink-muted hover:bg-surface-2 hover:border-line-strong' }}">
                    <svg class="w-4 h-4 shrink-0" fill="{{ $isFlagged ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v18l7-5 7 5V3a1 1 0 00-1-1H6a1 1 0 00-1 1z"/>
                    </svg>
                    {{ $isFlagged ? 'Flagged — saved to your Library' : 'Flag this question to remember it later' }}
                </button>
            @endunless
        </div>

    @else
        {{-- Questions not yet loaded or feedback state --}}
        @if ($feedback)
            <div class="linear-card p-6 text-center relative overflow-hidden">
                <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/20"/>
                <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/20"/>
                <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/20"/>
                <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/20"/>
                <p class="text-[13px] text-ink-muted">{{ $feedback }}</p>
            </div>
        @else
            <div class="linear-card p-6 text-center relative overflow-hidden">
                <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/20"/>
                <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/20"/>
                <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/20"/>
                <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/20"/>
                <p class="text-[13px] text-ink-muted">Loading...</p>
            </div>
        @endif
    @endif
</div>
