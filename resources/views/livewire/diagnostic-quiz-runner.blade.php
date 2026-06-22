<div class="py-6 max-w-2xl mx-auto">

    {{-- DIAGNOSTIC COMPLETION SCREEN --}}
    @if ($diagnosticProfile)
        <div class="space-y-3">

            {{-- Header --}}
            <div class="linear-card p-6 text-center relative overflow-hidden">
                <x-ornament.corner position="tl" class="top-0 left-0 w-10 h-10 text-gold/50"/>
                <x-ornament.corner position="tr" class="top-0 right-0 w-10 h-10 text-gold/50"/>
                <x-ornament.corner position="bl" class="bottom-0 left-0 w-10 h-10 text-gold/50"/>
                <x-ornament.corner position="br" class="bottom-0 right-0 w-10 h-10 text-gold/50"/>
                <x-mc-icon name="icon-complete" class="w-12 h-12 text-gold mb-4"/>
                <h2 class="text-[18px] font-semibold text-ink mb-1">Assessment Complete</h2>
                <p class="text-[20px] font-display italic text-gold mt-2">{{ $diagnosticProfile['player_type'] ?? 'Unclassified' }}</p>
            </div>

            {{-- Narrative --}}
            <div class="linear-card p-6 relative overflow-hidden">
                <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/30"/>
                <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/30"/>
                <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/30"/>
                <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/30"/>
                <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">Your answers suggest...</p>
                <p class="text-[14px] text-ink leading-relaxed">{{ $diagnosticProfile['narrative'] ?? '' }}</p>
            </div>

            {{-- Top traits --}}
            @if (!empty($diagnosticProfile['top_traits']))
                <div class="linear-card p-5 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/20"/>
                    <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-3">Top Traits</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($diagnosticProfile['top_traits'] as $trait)
                            <span class="badge-gold text-[12px] px-3 py-1 rounded-full">{{ str($trait)->headline() }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Growth area --}}
            @if (!empty($diagnosticProfile['growth_area']))
                <div class="linear-card p-5 relative overflow-hidden border border-violet/20">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-violet/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-violet/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-violet/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-violet/30"/>
                    <p class="text-[11px] font-semibold text-violet uppercase tracking-wide mb-2">Growth Area</p>
                    <p class="text-[13px] text-ink-muted leading-relaxed">{{ $diagnosticProfile['growth_area'] }}</p>
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

            {{-- Next module suggestion --}}
            @if (!empty($diagnosticProfile['next_module_suggestion']))
                <div class="linear-card p-5 relative overflow-hidden">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/20"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/20"/>
                    <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-2">Recommended Next Step</p>
                    <p class="text-[14px] text-ink mb-4">{{ $diagnosticProfile['next_module_suggestion'] }}</p>
                    <a href="{{ route('modules.index') }}"
                       class="inline-flex items-center justify-center gap-2 w-full py-2.5 text-[13px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200">
                        Explore recommended training
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
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
                <span class="text-[11px] text-ink-subtle">Assessment</span>
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

                @if ($question->type === 'diagnostic_mcq')
                    <div class="space-y-2">
                        @foreach ($question->answer['options'] as $option)
                            <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
                                   :class="$wire.answer === @js($option['text'])
                                       ? 'border-accent bg-accent/5 text-ink'
                                       : 'border-line text-ink-muted hover:bg-surface-2 hover:border-line-strong'">
                                <div class="w-3.5 h-3.5 rounded-full border-2 shrink-0 transition-colors"
                                     :class="$wire.answer === @js($option['text']) ? 'border-accent bg-accent' : 'border-line-strong'"></div>
                                <input type="radio" wire:model="answer" value="{{ $option['text'] }}" class="sr-only">
                                <span class="text-[13px]">{{ $option['text'] }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif

                <div class="mt-5">
                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                            class="w-full py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                            :disabled="!$wire.answer">
                        <span wire:loading.remove wire:target="submit">Submit Answer</span>
                        <span wire:loading wire:target="submit" class="flex items-center gap-2">
                            <span class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                            Submitting…
                        </span>
                    </button>
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
