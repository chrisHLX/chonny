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
            $recModule       = $diagnosticProfile['recommended_module'] ?? null;
            $recTitle        = is_array($recModule) ? ($recModule['title'] ?? '') : ($diagnosticProfile['next_module_suggestion'] ?? '');
            $recReason       = is_array($recModule) ? ($recModule['reason'] ?? '') : '';
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

            {{-- Evidence --}}
            @if (!empty($evidence))
                <div class="linear-card p-5 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/20"/>
                    <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">What we observed</p>
                    <div class="space-y-3">
                        @foreach ($evidence as $item)
                            <div class="flex gap-3">
                                <span class="mt-0.5 w-1.5 h-1.5 rounded-full bg-gold shrink-0"></span>
                                <div>
                                    <p class="text-[13px] font-medium text-ink">{{ $item['signal'] ?? '' }}</p>
                                    <p class="text-[12px] text-ink-muted leading-relaxed mt-0.5">{{ $item['interpretation'] ?? '' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif (!empty($topTraits))
                {{-- Fallback: old-format top traits --}}
                <div class="linear-card p-5 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/20"/>
                    <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">Top Traits</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($topTraits as $trait)
                            <span class="badge-gold text-[12px] px-3 py-1 rounded-full">{{ str($trait)->headline() }}</span>
                        @endforeach
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

            {{-- Sign up CTA (guests only) --}}
            @if ($guestMode)
                <div class="linear-card p-6 text-center border border-accent/30 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/30"/>
                    <x-mc-icon name="icon-lightning-circle" class="w-10 h-10 text-gold mb-3"/>
                    <h3 class="text-[16px] font-semibold text-ink mb-2">Save your profile &amp; keep improving</h3>
                    <p class="text-[13px] text-ink-muted leading-relaxed mb-5">
                        Create a free account to save this profile, track your growth areas over time, and unlock a personalised learning path.
                    </p>
                    <a href="{{ route('register') }}"
                       class="inline-flex items-center justify-center gap-2 w-full py-2.5 text-[13px] font-semibold text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                        Sign up — it's free
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                    <a href="{{ route('login') }}" class="block mt-2 text-[12px] text-ink-subtle hover:text-ink transition-colors">
                        Already have an account? Log in
                    </a>
                </div>
            @endif

            {{-- Recommended module --}}
            @if ($recTitle)
                <div class="linear-card p-5 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/20"/>
                    <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-2">Recommended Next Module</p>
                    <p class="text-[15px] font-medium text-ink mb-1">{{ $recTitle }}</p>
                    @if ($recReason)
                        <p class="text-[13px] text-ink-muted leading-relaxed mb-4">{{ $recReason }}</p>
                    @else
                        <div class="mb-4"></div>
                    @endif
                    <a href="{{ route('modules.index') }}"
                       class="inline-flex items-center justify-center gap-2 w-full py-2.5 text-[13px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200">
                        Explore recommended training
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

    {{-- ACTIVE QUESTION SCREEN --}}
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
                                    <input type="radio" wire:model="answer" value="{{ $option['text'] }}" class="sr-only">
                                    <span class="text-[13px]">{{ $option['text'] }}</span>
                                </label>
                            @else
                                <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                                       :class="$wire.answer === @js($option['text'])
                                           ? 'border-accent bg-accent/5 text-ink'
                                           : 'border-line text-ink-muted hover:bg-surface-2 hover:border-line-strong'">
                                    <div class="w-3.5 h-3.5 rounded-full border-2 shrink-0 transition-colors"
                                         :class="$wire.answer === @js($option['text']) ? 'border-accent bg-accent' : 'border-line-strong'"></div>
                                    <input type="radio" wire:model="answer" value="{{ $option['text'] }}" class="sr-only">
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
                            :disabled="!$wire.answer">
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
