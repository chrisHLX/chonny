<div class="max-w-6xl mx-auto px-4 py-8 space-y-5">

    <div class="linear-card px-6 py-5">
        <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">Burst Guides</p>
        <h1 class="font-display text-[26px] font-bold text-ink leading-tight mt-0.5">A Definite Series of Keys to Press</h1>
        <p class="text-[12px] text-ink-muted mt-1.5 max-w-3xl">
            One block per spec, computed straight from that spec's own real, densest observed archived burst window —
            filtered down to real damage cooldowns (via the same Offensive/Defensive classification WoW Comps and
            Spells use) plus any Crowd Control landed on the kill target, with ordinary rotation kept and purely
            defensive/utility noise dropped. Each sequence stops once it starts visibly repeating — what's left is a
            simple, honest read of what was actually pressed, not a theoretical or hand-authored rotation. Click any
            step for its real description.
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
