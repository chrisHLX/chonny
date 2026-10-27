<div class="max-w-[1400px] mx-auto px-3 sm:px-4 py-6 sm:py-8">
    <a href="{{ $back['url'] }}" class="inline-flex items-center gap-1.5 rounded-full bg-surface-2 hover:bg-surface-3 px-3.5 py-1.5 text-[12.5px] font-semibold text-ink transition-colors">&larr; {{ $back['label'] }}</a>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 mt-4 mb-4">
        <span class="flex -space-x-1">
            @foreach ($us['label']['classes'] as $slug)
                @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-8 h-8"/> @endif
            @endforeach
        </span>
        <span class="text-ink-subtle text-[13px]">vs</span>
        <span class="flex -space-x-1">
            @foreach ($them['label']['classes'] as $slug)
                @if ($classes[$slug] ?? null) <x-class-icon :class="$classes[$slug]" size="w-8 h-8"/> @endif
            @endforeach
        </span>
        <div class="basis-full sm:basis-auto min-w-0">
            <p class="text-[11px] font-semibold tracking-widest text-gold uppercase">A 3v3 game{{ $us['at'] ? ', '.$us['at']->format('D j M') : '' }}</p>
            <h1 class="font-display text-xl sm:text-2xl text-ink leading-tight">{{ $title }}</h1>
            <p class="text-[12.5px] text-ink-muted">Read as the {{ $usName }} team: "you" is that team. No player is named.</p>
        </div>
    </div>

    @if ($state === 'open')
        {{-- The app's own game card, drawn as its own document; the frame grows with it as tabs and rows open. --}}
        <iframe src="{{ route('comps.game.card', array_filter(['round' => $round, 'as' => $as === 'them' ? 'them' : null])) }}"
                class="w-full rounded-xl bg-surface-0" style="min-height: 70vh; border: 0"
                onload="try { var f = this, b = f.contentDocument.body, fit = function () { f.style.height = (b.offsetHeight + 24) + 'px' }; fit(); if (window.ResizeObserver) { new ResizeObserver(fit).observe(b) } } catch (e) {}"
                title="{{ $title }}"></iframe>
    @else
        <div class="relative rounded-xl overflow-hidden" style="height: 70vh">
            <iframe src="{{ route('wow.sample.page', 'game') }}" class="absolute inset-0 w-full h-full pointer-events-none"
                    style="border: 0; filter: blur(6px); transform: scale(1.02)" tabindex="-1" aria-hidden="true" title="Example game card"></iframe>
            <div class="absolute inset-0 bg-black/40"></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div class="linear-card bg-surface-1 p-6 max-w-md text-center shadow-2xl">
                    <h2 class="font-display text-xl text-ink mb-2">Sign up to open this game</h2>
                    <p class="text-[13.5px] text-ink-muted mb-5">
                        Both teams, how each death happened and everyone's damage and healing, from a real 3v3 game.
                        It's free, and no player is named.
                    </p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <a href="{{ route('register') }}" class="btn-primary">Sign up free</a>
                        <a href="{{ route('login') }}" class="btn-secondary">Sign in</a>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
