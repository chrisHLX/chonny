<div class="py-6 max-w-2xl mx-auto">

    {{-- DIAGNOSTIC COMPLETION SCREEN --}}
    @if ($diagnosticProfile)
        @php
            $profileTitle    = $diagnosticProfile['profile_title'] ?? ($diagnosticProfile['player_type'] ?? 'Unclassified');
            $summary         = $diagnosticProfile['summary'] ?? ($diagnosticProfile['narrative'] ?? '');
            $evidence        = $diagnosticProfile['evidence'] ?? [];
            $selfReportCheck = $diagnosticProfile['self_report_check'] ?? null;
            $inGamePattern   = $diagnosticProfile['likely_in_game_pattern'] ?? '';
            $strength        = $diagnosticProfile['primary_strength'] ?? null;
            $growthArea      = $diagnosticProfile['primary_growth_area'] ?? null;
            $growthAreaName  = is_array($growthArea) ? ($growthArea['name'] ?? '') : ($diagnosticProfile['growth_area'] ?? '');
            $practiceGoal    = $diagnosticProfile['next_practice_goal'] ?? '';
            $confidence      = $diagnosticProfile['confidence_level'] ?? null;
            $topTraits       = $diagnosticProfile['top_traits'] ?? [];
        @endphp

        <div class="space-y-3">

            {{-- Header --}}
            <div class="linear-card p-6 text-center relative overflow-hidden">
                <x-ornament.corner position="tl" class="top-0 left-0 w-10 h-10 text-gold/50"/>
                <x-ornament.corner position="tr" class="top-0 right-0 w-10 h-10 text-gold/50"/>
                <x-ornament.corner position="bl" class="bottom-0 left-0 w-10 h-10 text-gold/50"/>
                <x-ornament.corner position="br" class="bottom-0 right-0 w-10 h-10 text-gold/50"/>
                <x-mc-icon name="icon-complete" class="w-12 h-12 text-gold mb-4"/>
                <h2 class="text-[18px] font-semibold text-ink mb-1">Assessment Complete</h2>
                <p class="text-[20px] font-display italic text-gold mt-2">{{ $profileTitle }}</p>
                @if ($confidence)
                    <span class="inline-block mt-2 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-widest rounded-full
                        {{ $confidence === 'high' ? 'bg-gold-subtle text-gold border border-gold/20' : ($confidence === 'low' ? 'bg-surface-3 text-ink-subtle border border-line' : 'bg-surface-3 text-ink-subtle border border-line') }}">
                        {{ $confidence }} confidence
                    </span>
                @endif
            </div>

            {{-- Summary --}}
            @if ($summary)
                <div class="linear-card p-6 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/30"/>
                    <p class="text-[14px] text-ink leading-relaxed">{{ $summary }}</p>
                </div>
            @endif

            {{-- Evidence (collapsible) --}}
            @php $observedEvidence = array_values(array_filter($evidence, fn($e) => !str_starts_with($e['signal'] ?? '', 'Self-reported'))); @endphp
            @if (!empty($observedEvidence))
                <div class="linear-card relative overflow-hidden" x-data="{ open: false }">
                    <button @click="open = !open"
                            class="w-full flex items-center justify-between px-5 py-4 text-left">
                        <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide">What we observed</p>
                        <svg class="w-4 h-4 text-ink-subtle transition-transform duration-200"
                             :class="open ? 'rotate-180' : ''"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="open" x-collapse class="px-5 pb-5">
                        <div class="space-y-0">
                            @foreach ($observedEvidence as $item)
                                <div class="{{ !$loop->first ? 'border-t border-line pt-4 mt-4' : '' }}">
                                    <p class="text-[13px] font-semibold text-ink mb-1">{{ $item['signal'] ?? '' }}</p>
                                    <p class="text-[12px] text-ink-muted leading-relaxed">{{ $item['interpretation'] ?? '' }}</p>
                                    @if (!empty($item['score']))
                                        <p class="text-[11px] text-ink-subtle mt-1.5">Signal: <span class="font-mono text-ink-muted">{{ $item['score'] }}</span></p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @elseif (!empty($topTraits))
                <div class="linear-card relative overflow-hidden" x-data="{ open: false }">
                    <button @click="open = !open"
                            class="w-full flex items-center justify-between px-5 py-4 text-left">
                        <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide">What we observed</p>
                        <svg class="w-4 h-4 text-ink-subtle transition-transform duration-200"
                             :class="open ? 'rotate-180' : ''"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="open" x-collapse class="px-5 pb-5">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($topTraits as $trait)
                                <span class="badge-gold text-[12px] px-3 py-1 rounded-full">{{ str($trait)->headline() }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- Self-report alignment --}}
            @if ($selfReportCheck && !empty($selfReportCheck['comment']))
                @php
                    $alignment = $selfReportCheck['alignment'] ?? 'insufficient_data';
                    $alignColor = match($alignment) {
                        'aligned'           => 'text-gold border-gold/20 bg-gold-subtle',
                        'conflicting'       => 'text-violet border-violet/20 bg-violet-subtle',
                        'partially_aligned' => 'text-ink-muted border-line bg-surface-2',
                        default             => 'text-ink-subtle border-line bg-surface-2',
                    };
                @endphp
                <div class="linear-card p-4 relative overflow-hidden border {{ $alignColor }}">
                    <p class="text-[11px] font-semibold uppercase tracking-wide mb-1
                        {{ $alignment === 'conflicting' ? 'text-violet' : 'text-ink-subtle' }}">
                        Self-report {{ str_replace('_', ' ', $alignment) }}
                    </p>
                    <p class="text-[13px] text-ink-muted leading-relaxed">{{ $selfReportCheck['comment'] }}</p>
                </div>
            @endif

            {{-- Strength + Growth area --}}
            @if ($strength || $growthAreaName)
                <div class="grid grid-cols-2 gap-3">
                    @if ($strength)
                        <div class="linear-card p-4 relative overflow-hidden">
                            <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-gold/20"/>
                            <p class="text-[10px] font-semibold text-gold uppercase tracking-widest mb-1.5">Strength</p>
                            <p class="text-[13px] font-medium text-ink">{{ $strength['name'] ?? '' }}</p>
                            @if (!empty($strength['concepts']))
                                <div class="flex flex-wrap gap-1 mt-2">
                                    @foreach (array_slice($strength['concepts'], 0, 3) as $concept)
                                        <span class="text-[10px] text-ink-subtle px-1.5 py-0.5 bg-surface-3 rounded">{{ $concept }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                    @if ($growthAreaName)
                        <div class="linear-card p-4 relative overflow-hidden border border-violet/20">
                            <x-ornament.corner position="tl" class="top-0 left-0 w-6 h-6 text-violet/30"/>
                            <p class="text-[10px] font-semibold text-violet uppercase tracking-widest mb-1.5">Growth Area</p>
                            <p class="text-[13px] font-medium text-ink">{{ $growthAreaName }}</p>
                            @if (is_array($growthArea) && !empty($growthArea['concepts']))
                                <div class="flex flex-wrap gap-1 mt-2">
                                    @foreach (array_slice($growthArea['concepts'], 0, 3) as $concept)
                                        <span class="text-[10px] text-ink-subtle px-1.5 py-0.5 bg-surface-3 rounded">{{ $concept }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            {{-- In-game pattern --}}
            @if ($inGamePattern)
                <div class="linear-card p-4 relative overflow-hidden">
                    <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-1.5">How this shows up in play</p>
                    <p class="text-[13px] text-ink-muted leading-relaxed italic">{{ $inGamePattern }}</p>
                </div>
            @endif

            {{-- Guest: gated learning-path reveal (GuestRoadmap component) which itself contains
                 the sign-up CTA once revealed. Auth users get the "what to do next" card below. --}}
            @if ($guestMode)
                <livewire:guest-roadmap :module-id="$moduleId" :key="'guest-roadmap-'.$moduleId"/>
            @else
                <div class="linear-card p-5 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/20"/>
                    <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-2">What to do next</p>
                    <p class="text-[14px] font-medium text-ink mb-1">
                        {{ $growthAreaName ? 'Put your profile to work' : 'Explore your training' }}
                    </p>
                    @if ($growthAreaName)
                        <p class="text-[13px] text-ink-muted leading-relaxed mb-4">
                            Your growth area is <span class="text-ink font-medium">{{ $growthAreaName }}</span>. Browse the available modules to find targeted training that addresses it directly.
                        </p>
                    @else
                        <p class="text-[13px] text-ink-muted leading-relaxed mb-4">
                            Explore the available training modules and find one that matches what your profile revealed.
                        </p>
                    @endif
                    <a href="{{ route('modules.index') }}"
                       class="inline-flex items-center justify-center gap-2 w-full py-2.5 text-[13px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200">
                        Explore training modules
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>
            @endif

            {{-- Next practice goal --}}
            @if ($practiceGoal)
                <div class="linear-card p-4 relative overflow-hidden border border-gold/10">
                    <p class="text-[11px] font-semibold text-gold uppercase tracking-wide mb-1.5">Try this next session</p>
                    <p class="text-[13px] text-ink leading-relaxed">{{ $practiceGoal }}</p>
                </div>
            @endif

            {{-- Actions --}}
            <div class="flex flex-col gap-2 pt-1">
                <button wire:click="retake"
                        wire:loading.attr="disabled"
                        wire:target="retake"
                        class="inline-flex items-center justify-center w-full py-2.5 text-[13px] font-medium text-ink-muted border border-line hover:bg-surface-2 rounded-md transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="retake">Retake Assessment</span>
                    <span wire:loading wire:target="retake">Starting…</span>
                </button>

                <a href="{{ route('modules.index') }}"
                   class="inline-flex items-center justify-center w-full py-2 text-[13px] font-medium text-ink-subtle hover:text-ink transition-colors">
                    Browse Guides
                </a>
            </div>
        </div>

    {{-- INTRO SCREEN --}}
    @elseif (!$introShown)

        <div class="linear-card p-8 relative overflow-hidden">
            <x-ornament.corner position="tl" class="top-0 left-0 w-10 h-10 text-gold/40"/>
            <x-ornament.corner position="tr" class="top-0 right-0 w-10 h-10 text-gold/40"/>
            <x-ornament.corner position="bl" class="bottom-0 left-0 w-10 h-10 text-gold/40"/>
            <x-ornament.corner position="br" class="bottom-0 right-0 w-10 h-10 text-gold/40"/>

            {{-- Badge --}}
            <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gold-subtle border border-gold/20 mb-6">
                <span class="w-1.5 h-1.5 rounded-full bg-gold shrink-0"></span>
                <span class="text-[10px] font-semibold text-gold uppercase tracking-widest">Player Profile Assessment</span>
            </div>

            <h2 class="font-display text-[22px] font-semibold text-ink mb-1 leading-snug">
                We're building your<br>
                <span class="text-gold-light italic">{{ $moduleName ?: 'player profile' }}</span>.
            </h2>

            <div class="w-10 h-px bg-gold/30 my-5"></div>

            <p class="text-[14px] text-ink-muted leading-relaxed mb-5">
                This is not a trivia test. Your answers reveal how you naturally approach
                pressure, decision-making, risk, and win conditions.
            </p>

            <div class="space-y-3 mb-7">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 w-1.5 h-1.5 rounded-full bg-gold shrink-0"></span>
                    <p class="text-[13px] text-ink-muted">Choose what you <em class="text-ink not-italic font-medium">would actually do</em> — not what sounds ideal.</p>
                </div>
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 w-1.5 h-1.5 rounded-full bg-gold shrink-0"></span>
                    <p class="text-[13px] text-ink-muted">There are no right or wrong answers.</p>
                </div>
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 w-1.5 h-1.5 rounded-full bg-gold shrink-0"></span>
                    <p class="text-[13px] text-ink-muted">At the end you'll receive your playstyle profile and a recommended next training step.</p>
                </div>
            </div>

            <button wire:click="startAssessment"
                    class="inline-flex items-center justify-center gap-2 w-full py-3 text-[14px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200">
                Start Assessment
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>

    {{-- DECLARE CONTEXT STEP — folded into the sequence right after the intro, not a separate
         gated screen: same module heading, same card language as the questions that follow. --}}
    @elseif ($showContextStep)

        @if ($moduleName)
            <div class="flex items-center justify-center gap-3 mb-5">
                <span class="h-px w-8 bg-gold/30"></span>
                <span class="text-[11px] font-semibold text-gold/80 uppercase tracking-[0.15em] text-center">{{ $moduleName }}</span>
                <span class="h-px w-8 bg-gold/30"></span>
            </div>
        @endif

        <livewire:subject-context-form :subject-id="$subjectId" :guest-mode="$guestMode" :key="'diagnostic-context-form-'.$moduleId"/>

    {{-- ACTIVE QUESTION SCREEN --}}
    @elseif (!empty($questions) && $questions->count() > $currentIndex)
        @php $question = $questions[$currentIndex]; @endphp

        {{-- Module heading --}}
        @if ($moduleName)
            <div class="flex items-center justify-center gap-3 mb-5">
                <span class="h-px w-8 bg-gold/30"></span>
                <span class="text-[11px] font-semibold text-gold/80 uppercase tracking-[0.15em] text-center">{{ $moduleName }}</span>
                <span class="h-px w-8 bg-gold/30"></span>
            </div>
        @endif

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
                <span class="text-[11px] text-ink-subtle">Answer honestly — choose what you'd actually do.</span>
            </div>
        </div>

        {{-- Question card --}}
        @php $isSurvey = $question->type === 'survey_mcq'; @endphp

        <div class="linear-card p-6 relative overflow-hidden {{ $isSurvey ? 'border border-violet/25' : '' }}"
             wire:key="question-{{ $question->id }}" x-transition>

            @if ($isSurvey)
                <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-violet/30"/>
                <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-violet/30"/>
                <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-violet/30"/>
                <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-violet/30"/>
                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-violet-subtle border border-violet/20 mb-4">
                    <span class="w-1.5 h-1.5 rounded-full bg-violet shrink-0"></span>
                    <span class="text-[10px] font-semibold text-violet uppercase tracking-widest">About You</span>
                </div>
            @else
                <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/40"/>
                <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/40"/>
                <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/40"/>
                <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/40"/>
            @endif

            <p class="text-[15px] font-medium text-ink leading-relaxed mb-5">
                <span class="{{ $isSurvey ? 'text-violet' : 'text-accent' }} font-semibold mr-1">{{ $currentIndex + 1 }}.</span>
                {{ $question->question }}
            </p>

            <form x-data="{
                      elapsed: 0,
                      loadingLabels: ['Logging your answer…', 'Analysing your response…', 'Building your profile…', 'Mapping your tendencies…', 'Calculating patterns…'],
                      loadingIdx: 0
                  }"
                  x-init="
                      setInterval(() => elapsed++, 1000);
                      setInterval(() => loadingIdx = (loadingIdx + 1) % loadingLabels.length, 2000);
                  "
                  x-on:submit.prevent="$wire.submit({ elapsed })">

                @if (in_array($question->type, ['diagnostic_mcq', 'survey_mcq']))
                    <div class="space-y-2">
                        @foreach ($question->answer['options'] as $option)
                            @if ($isSurvey)
                                <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                                       :class="$wire.answer === @js($option['text'])
                                           ? 'border-violet bg-violet/5 text-ink'
                                           : 'border-line text-ink-muted hover:bg-surface-2 hover:border-violet/30'">
                                    <div class="w-3.5 h-3.5 rounded-full border-2 shrink-0 transition-colors"
                                         :class="$wire.answer === @js($option['text']) ? 'border-violet bg-violet' : 'border-line-strong'"></div>
                                    <input type="radio" wire:model="answer" name="answer-{{ $question->id }}" value="{{ $option['text'] }}" required class="sr-only">
                                    <span class="text-[13px]">{{ $option['text'] }}</span>
                                </label>
                            @else
                                <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                                       :class="$wire.answer === @js($option['text'])
                                           ? 'border-accent bg-accent/5 text-ink'
                                           : 'border-line text-ink-muted hover:bg-surface-2 hover:border-line-strong'">
                                    <div class="w-3.5 h-3.5 rounded-full border-2 shrink-0 transition-colors"
                                         :class="$wire.answer === @js($option['text']) ? 'border-accent bg-accent' : 'border-line-strong'"></div>
                                    <input type="radio" wire:model="answer" name="answer-{{ $question->id }}" value="{{ $option['text'] }}" required class="sr-only">
                                    <span class="text-[13px]">{{ $option['text'] }}</span>
                                </label>
                            @endif
                        @endforeach
                    </div>
                @endif

                <div class="mt-5">
                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                            class="w-full py-2.5 text-[13px] font-medium rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2
                                   {{ $isSurvey ? 'text-white bg-violet-muted hover:bg-violet border border-violet/40' : 'text-white bg-accent hover:bg-accent-hover' }}"
                            :disabled="Array.isArray($wire.answer) ? $wire.answer.length === 0 : ($wire.answer === null || $wire.answer === undefined || String($wire.answer).trim() === '')">
                        <span wire:loading.remove wire:target="submit">{{ $isSurvey ? 'Next' : 'Submit Answer' }}</span>
                        <span wire:loading wire:target="submit">
                            <span class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin inline-block"></span>
                        </span>
                    </button>

                    <div wire:loading wire:target="submit" class="mt-3 flex items-center justify-center gap-2">
                        <span class="w-1 h-1 rounded-full bg-ink-subtle animate-pulse"></span>
                        <span class="text-[12px] text-ink-subtle" x-text="loadingLabels[loadingIdx]">Logging your answer…</span>
                        <span class="w-1 h-1 rounded-full bg-ink-subtle animate-pulse" style="animation-delay:0.3s"></span>
                    </div>
                </div>
            </form>
        </div>

    @else
        {{-- Loading / feedback state --}}
        <div class="linear-card p-6 text-center relative overflow-hidden">
            <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/20"/>
            <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/20"/>
            <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/20"/>
            <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/20"/>
            @if ($feedback)
                <p class="text-[13px] text-ink-muted">{{ $feedback }}</p>
            @else
                <p class="text-[13px] text-ink-muted">Loading...</p>
            @endif
        </div>
    @endif
</div>
