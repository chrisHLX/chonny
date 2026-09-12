<div class="max-w-6xl mx-auto px-4 py-8 space-y-5">

    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Offensive Kits</p>
        <h1 class="font-display text-[26px] font-bold text-ink leading-tight mt-0.5">How Every Spec Bursts</h1>
        <p class="text-[12px] text-ink-muted mt-1.5 max-w-3xl">
            For every spec: how long its go lasts, how many globals fit in it, and the order to press them.
            <span class="text-ink">Set up</span> so the damage can't be healed through,
            <span class="text-ink">commit</span> your cooldowns, <span class="text-ink">execute</span> the window,
            then <span class="text-ink">fill</span> the globals that are left.
        </p>
        <p class="text-[11px] text-ink-subtle mt-2 max-w-3xl">
            Built from hundreds of recorded burst windows per spec, so every step reflects what players
            usually press, not a one-off. Timings are measured from the spec's biggest cooldown. Click any
            step to see what it does.
        </p>
    </div>

    @if (empty($availableClassSlugs))
        <div class="linear-card p-5">
            <p class="text-[12px] text-ink-subtle italic">No offensive kits available yet. Check back soon.</p>
        </div>
    @else
        {{-- Each class's real content loads lazily (Livewire #[Lazy]) — the page paints instantly
             with a lightweight placeholder per class, then each class's own real spec/spell
             resolution fires as its own small, independent follow-up request right after,
             instead of one big blocking render of all 38 specs at once. See
             App\Livewire\BurstGuideClassBlock's own docblock for the full reasoning. --}}
        <div class="space-y-6">
            @foreach ($availableClassSlugs as $slug)
                <livewire:burst-guide-class-block :class-slug="$slug" :key="$slug" lazy/>
            @endforeach
        </div>
    @endif

    <livewire:spell-detail-modal/>
</div>
