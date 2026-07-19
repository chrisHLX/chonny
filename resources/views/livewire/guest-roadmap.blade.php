<div>
    @if ($available)
        @if (!$revealed)
            <div class="linear-card p-6 relative overflow-hidden border border-gold/20 text-center">
                <x-ornament.corner position="tl" class="top-0 left-0 w-10 h-10 text-gold/30"/>
                <x-ornament.corner position="tr" class="top-0 right-0 w-10 h-10 text-gold/30"/>
                <x-ornament.corner position="bl" class="bottom-0 left-0 w-10 h-10 text-gold/30"/>
                <x-ornament.corner position="br" class="bottom-0 right-0 w-10 h-10 text-gold/30"/>

                <x-mc-icon name="icon-compass" class="w-10 h-10 text-gold mb-4 mx-auto"/>

                <h3 class="font-display text-[20px] italic text-gold-light leading-snug mb-3">
                    Your training path is ready.
                </h3>

                <p class="text-[13px] text-ink-muted leading-relaxed mb-5">
                    See exactly what Mindcollector will train next — and why — before you decide anything.
                </p>

                <button wire:click="reveal"
                        wire:loading.attr="disabled"
                        wire:target="reveal"
                        class="inline-flex items-center justify-center gap-2 w-full py-3 text-[14px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200 disabled:opacity-60">
                    <span wire:loading.remove wire:target="reveal">View my learning path</span>
                    <span wire:loading wire:target="reveal">Building your path…</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
            </div>
        @elseif ($roadmap)
            <div class="space-y-4">
                <div class="text-center px-1">
                    <h3 class="font-display text-[22px] italic text-gold-light leading-snug mb-2">
                        {{ $roadmap['title'] }}
                    </h3>
                    <p class="text-[13px] text-ink-muted leading-relaxed">{{ $roadmap['summary'] }}</p>
                </div>

                <x-milestone-path :milestones="$roadmap['milestones']"/>

                <p class="text-[11px] text-ink-subtle text-center italic px-4">
                    This plan will adapt as we learn more about you — it's a starting point, not a fixed curriculum.
                </p>

                {{-- Sign-up CTA: relocated here from the old always-visible completion-screen CTA,
                     so it now appears only once the guest has actually seen the plan they're
                     being asked to unlock, rather than being asked to commit before seeing it. --}}
                <div class="linear-card p-6 relative overflow-hidden border border-gold/20">
                    <x-ornament.corner position="tl" class="top-0 left-0 w-10 h-10 text-gold/30"/>
                    <x-ornament.corner position="tr" class="top-0 right-0 w-10 h-10 text-gold/30"/>
                    <x-ornament.corner position="bl" class="bottom-0 left-0 w-10 h-10 text-gold/30"/>
                    <x-ornament.corner position="br" class="bottom-0 right-0 w-10 h-10 text-gold/30"/>

                    <x-mc-icon name="icon-lightning-circle" class="w-10 h-10 text-gold mb-4"/>

                    <h3 class="font-display text-[20px] italic text-gold-light leading-snug mb-3">
                        Start your journey.
                    </h3>

                    <p class="text-[13px] text-ink-muted leading-relaxed mb-5">
                        Create a free account to save this plan, unlock your first module, and track your progress along the way.
                    </p>

                    <a href="{{ route('register') }}"
                       class="inline-flex items-center justify-center gap-2 w-full py-3 text-[14px] font-semibold text-surface-0 bg-gold-gradient rounded-md hover:shadow-gold transition-all duration-200 mb-3">
                        Unlock my path — free
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>

                    <div class="flex items-center justify-center gap-4 mb-4">
                        @foreach (['Plan saved', 'First module unlocked', 'Progress tracking'] as $perk)
                            <span class="flex items-center gap-1 text-[11px] text-ink-subtle">
                                <svg class="w-3 h-3 text-gold shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                </svg>
                                {{ $perk }}
                            </span>
                        @endforeach
                    </div>

                    <p class="text-center text-[12px] text-ink-subtle">
                        Already have an account?
                        <a href="{{ route('login') }}" class="text-gold hover:text-gold-light underline underline-offset-2 transition-colors">Log in</a>
                    </p>
                </div>
            </div>
        @endif
    @endif
</div>
