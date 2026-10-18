{{-- The desktop app, shown slide by slide (the front page and /wow/app). Each picture is a real page
     the app drew from real games, with every player's name swapped for a label ("Their Rogue",
     "You") before it was taken: a real game's page names real players. Pictures in
     public/images/app/; how to take them again: tools/app-pictures/README.md. --}}
@php
    $slides = [
        ['card', 'Games', 'Every game, as it ends', 'Who you played, how experienced they were, and the MMR on both sides. It shows up about a minute after the gates close.'],
        ['death', 'Games', 'How each death happened', 'The killing blow, which abilities did the damage in the last 10 seconds, whether the healer was locked out, and every defensive pressed before it.'],
        ['basics', 'Games', 'Your goes and theirs', 'Whether your damage landed with their healer locked, and whether their goes met your defensives already down. Each is set against what kills across every game we have.'],
        ['damage', 'Games', 'Damage and healing', 'Everyone\'s damage, healing, absorbs and time spent pressing nothing. Open anyone for their abilities.'],
        ['improve', 'Improve', 'Where you stand', 'Your damage, defensives and crowd control set against other players of your spec, session by session, so you can see them move.'],
        ['comp', 'Comps', 'Know the comp you\'re facing', 'From your own games against them: when they go, what they go with, the crowd control they chain and who they kill.'],
        ['shuffle', 'Games', 'Solo Shuffle, round by round', 'All six rounds in one place, with the same read of every death and what each team still had.'],
    ];
    $navs = ['Games', 'Improve', 'Comps', 'Classes'];
@endphp

<section {{ $attributes->merge(['class' => 'select-none']) }} aria-label="What the app looks like" aria-roledescription="carousel"
    x-data="{
        i: 0, n: {{ count($slides) }}, held: false, timer: null,
        go(k) { this.i = (k + this.n) % this.n },
        start() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            clearInterval(this.timer);
            this.timer = setInterval(() => { if (! this.held && ! document.hidden) this.go(this.i + 1) }, 6500);
        },
        pick(k) { this.go(k); this.start() },
        sx: null,
    }"
    x-init="start()"
    @mouseenter="held = true" @mouseleave="held = false"
    @focusin="held = true" @focusout="held = false"
    @keydown.arrow-right.prevent="pick(i + 1)" @keydown.arrow-left.prevent="pick(i - 1)">

    {{-- The window --}}
    <div class="rounded-xl bg-frame p-2 shadow-2xl shadow-black/60 ring-1 ring-white/5">
        <div class="flex items-center gap-2 px-2 pb-2 text-[12px] text-ink-muted">
            <span class="w-3 h-3 rounded-full bg-gold/80"></span> MindCollector
            <span class="ml-auto text-ink-subtle">Real games, names hidden</span>
        </div>
        <div class="flex gap-2">
            <div class="hidden lg:flex flex-col gap-2 w-52 shrink-0">
                <div class="rounded-lg bg-surface-0 p-3">
                    <p class="text-[16px] font-bold px-1 pb-2"><span class="text-ink">Mind</span><span class="text-gold">Collector</span></p>
                    @foreach ($navs as $nav)
                        @php $on = collect($slides)->keys()->filter(fn ($k) => $slides[$k][1] === $nav)->values()->all(); @endphp
                        <button type="button" @click="pick({{ $on[0] ?? 0 }})" @disabled(! $on)
                            class="relative w-full text-left px-3 py-2 rounded-md text-[13px] font-semibold transition-colors disabled:cursor-default"
                            :class="{{ json_encode($on) }}.includes(i) ? 'bg-surface-2 text-ink' : 'text-ink-muted {{ $on ? 'hover:text-ink' : '' }}'">
                            <span x-show="{{ json_encode($on) }}.includes(i)" class="absolute left-0 top-2 bottom-2 w-[3px] rounded-full bg-gold"></span>{{ $nav }}
                        </button>
                    @endforeach
                </div>
                <div class="flex-1 rounded-lg bg-surface-0 p-2">
                    <p class="px-2 pt-1 pb-2 text-[13px] font-bold text-ink">What it shows</p>
                    @foreach ($slides as $k => [$key, $nav, $title])
                        <button type="button" @click="pick({{ $k }})"
                            class="relative w-full text-left px-3 py-1.5 rounded-md text-[12.5px] transition-colors"
                            :class="i === {{ $k }} ? 'bg-surface-3 text-ink font-semibold' : 'text-ink-muted hover:text-ink'">
                            <span x-show="i === {{ $k }}" class="absolute left-0 top-2 bottom-2 w-[3px] rounded-full bg-gold"></span>{{ $title }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="relative flex-1 min-w-0 rounded-lg bg-surface-0 overflow-hidden aspect-[49/32]"
                @touchstart.passive="sx = $event.touches[0].clientX"
                @touchend="if (sx !== null) { const d = $event.changedTouches[0].clientX - sx; if (Math.abs(d) > 40) pick(i + (d < 0 ? 1 : -1)); sx = null }">
                @foreach ($slides as $k => [$key, $nav, $title])
                    <img src="/images/app/{{ $key }}.webp" alt="{{ $title }}: a page from the MindCollector app" width="1600" height="1045"
                        @if ($k > 0) loading="lazy" @endif decoding="async"
                        class="absolute inset-0 w-full h-full object-cover object-top transition-opacity duration-500 {{ $k > 0 ? 'opacity-0' : '' }}"
                        :class="i === {{ $k }} ? '!opacity-100' : 'opacity-0'" :aria-hidden="i !== {{ $k }}">
                @endforeach
                <button type="button" @click="pick(i - 1)" aria-label="Previous"
                    class="absolute left-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full bg-black/60 text-ink flex items-center justify-center hover:bg-black/80 hover:scale-105 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" @click="pick(i + 1)" aria-label="Next"
                    class="absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full bg-black/60 text-ink flex items-center justify-center hover:bg-black/80 hover:scale-105 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        </div>
    </div>

    {{-- What the slide shows, and the pills to pick one --}}
    <div class="mt-4 min-h-[76px]" aria-live="polite">
        @foreach ($slides as $k => [$key, $nav, $title, $text])
            <div x-show="i === {{ $k }}" @if ($k > 0) style="display:none" @endif>
                <p class="text-[17px] font-bold text-ink">{{ $title }}</p>
                <p class="text-[14px] text-ink-muted mt-1 max-w-3xl">{{ $text }}</p>
            </div>
        @endforeach
    </div>
    <div class="flex flex-wrap gap-2 mt-3">
        @foreach ($slides as $k => [$key, $nav, $title])
            <button type="button" @click="pick({{ $k }})" class="chip" :class="i === {{ $k }} && 'chip-on'" :aria-current="i === {{ $k }}">{{ $title }}</button>
        @endforeach
    </div>
</section>
