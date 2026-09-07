<div class="max-w-6xl mx-auto px-4 py-8 space-y-5">

    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Burst Guides</p>
        <h1 class="font-display text-[26px] font-bold text-ink leading-tight mt-0.5">How Each Spec Actually Bursts</h1>
        <p class="text-[12px] text-ink-muted mt-1.5 max-w-3xl">
            One plan per spec: how long its go really lasts, how many globals fit inside it, and what to press in
            order — <span class="text-ink">set up</span> what stops the damage being healed,
            <span class="text-ink">commit</span> your cooldowns, <span class="text-ink">execute</span> the window,
            then <span class="text-ink">fill</span> every global left over.
        </p>
        <p class="text-[11px] text-ink-subtle mt-2 max-w-3xl">
            Nothing here is hand-written per spec. Every figure is aggregated across <em>every</em> real archived burst
            window for that spec — hundreds of them, across dozens of matches — so a step earns its place by being
            typical, not by appearing once. Timings are median offsets from the spec's biggest cooldown; the global
            cooldown is measured per spec from real cast cadence; and where a control ability belongs follows the rule
            that control breaking on damage cannot sit on the target you are damaging. Click any step for its real
            description.
        </p>
    </div>

    @if (empty($availableClassSlugs))
        <div class="linear-card p-5">
            <p class="text-[12px] text-ink-subtle italic">No burst guides on file yet — run <code>php artisan wow:build-burst-guides</code>.</p>
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
