@php
    $classColors = config('wow_classes.colors', []);
    $feed = $this->feed;
    $pool = $this->pool;
@endphp

{{-- WoW's home (App\Livewire\Landing, /wow). Since 2026-10-18 a hub: your games (the app and a
     sample game to click through), then the planning tools, the things to learn from, and the latest
     guides. The app's own pitch, slides and setup steps are /wow/app's alone; until that day this
     page repeated them. --}}
<div class="max-w-6xl mx-auto px-4 py-6 sm:py-10">

    {{-- Your games ---------------------------------------------------------------------------- --}}
    <section class="page-hero -mx-2 sm:mx-0 grid grid-cols-1 lg:grid-cols-5 gap-8 items-center">
        <div class="lg:col-span-3">
            <p class="page-eyebrow">World of Warcraft arena</p>
            <h1 class="page-title max-w-3xl">Every arena game you play, reviewed.</h1>
            <p class="text-[16px] text-ink-muted mt-4 max-w-2xl">
                The free MindCollector app sends each game here when it ends. You get a card for every game with
                who died and why, a timeline you can play back with every player's cooldowns, and where you stand
                against the players of your spec you actually meet.
            </p>
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 mt-7">
                <a href="{{ route('wow.sample') }}" class="btn-primary !px-7 !py-3 !text-[15px]">See a real game</a>
                <a href="{{ route('app') }}" wire:navigate class="btn-secondary !px-7 !py-3 !text-[15px]">Get the app</a>
            </div>
            @if ($pool['games'] > 0)
                <p class="text-[12.5px] text-ink-subtle mt-4">
                    Set against {{ number_format($pool['games']) }} recorded games and {{ number_format($pool['players']) }} players across {{ $pool['specs'] }} specs.
                </p>
            @endif
        </div>
        <a href="{{ route('wow.sample') }}" class="lg:col-span-2 block group">
            <img src="/images/app/card.webp" alt="A 3v3 game as MindCollector reads it, names hidden" loading="eager"
                 class="w-full rounded-lg border border-line shadow-xl group-hover:-translate-y-0.5 transition-transform">
            <span class="block text-[12.5px] text-ink-subtle mt-2 group-hover:text-ink">A real game, names hidden. Click through it &rarr;</span>
        </a>
    </section>

    {{-- The planning tools -------------------------------------------------------------------- --}}
    <section class="mt-14">
        <h2 class="text-[22px] font-bold text-ink">Plan your games</h2>
        <p class="text-[14px] text-ink-muted mt-1">Spell, talent and cooldown data from the live game, for when you're not playing.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
            @foreach ([
                [route('wow-comps'), 'Build a 3v3 comp', 'Pick three specs and see their cooldowns, crowd control and defensives side by side.'],
                [route('matchup-lab'), 'Matchup Lab', 'Set two comps against each other and see when each side has its answers.'],
                [route('spec-pvp.index'), 'PvP guides', 'Every spec\'s PvP talents, goes, defensives and what to kick, counted from real games.'],
                [route('guides.browse'), 'Guides', 'Game plans other players have written for their comps.'],
            ] as [$href, $title, $text])
                <a href="{{ $href }}" wire:navigate class="linear-card group p-5 flex flex-col">
                    <p class="text-[16px] font-bold text-ink">{{ $title }}</p>
                    <p class="text-[13px] text-ink-muted mt-1.5 flex-1">{{ $text }}</p>
                    <span class="mt-4 w-9 h-9 rounded-full bg-gold text-black flex items-center justify-center opacity-0 translate-y-1 group-hover:opacity-100 group-hover:translate-y-0 transition self-end">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                    </span>
                </a>
            @endforeach
        </div>

        @if (count($this->presets))
            <div class="flex flex-wrap items-center gap-2 mt-4">
                <span class="text-[13px] text-ink-muted mr-1">Or start from a common comp:</span>
                @foreach ($this->presets as $preset)
                    <a href="{{ route('wow-comps', ['preset' => $preset['key']]) }}" wire:navigate wire:key="preset-{{ $preset['key'] }}" class="chip">
                        <span class="flex -space-x-1">@foreach ($preset['specs'] as $spec)<x-spec-icon :spec="$spec" size="w-5 h-5"/>@endforeach</span>
                        {{ $preset['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Learn ------------------------------------------------------------------------------- --}}
    <section class="mt-14">
        <h2 class="text-[22px] font-bold text-ink">Learn the game</h2>
        <p class="text-[14px] text-ink-muted mt-1">How arena is won, and quizzes on your own spec's kit, built from live game data.</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4">
            @foreach ([
                [route('wow-basics'), 'Arena basics check', 'Eight questions on how arena is won, with your own spec\'s buttons.'],
                [route('wow-quiz'), 'Class quizzes', 'Your cooldowns, crowd control and diminishing returns, level by level.'],
                [route('spells.explore'), 'Spells and talents', 'Every spec\'s kit with talent-adjusted cooldowns, from the live game.'],
            ] as [$href, $title, $text])
                <a href="{{ $href }}" wire:navigate class="linear-card p-5 flex flex-col hover:-translate-y-0.5 transition-transform">
                    <p class="text-[16px] font-bold text-ink">{{ $title }}</p>
                    <p class="text-[13px] text-ink-muted mt-1.5 flex-1">{{ $text }}</p>
                </a>
            @endforeach
        </div>
    </section>

    {{-- The public feed ------------------------------------------------------------------------ --}}
    <section class="mt-14 min-w-0">
        <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2.5">
            <h2 class="text-[20px] font-bold text-ink">Latest guides</h2>
            <a href="{{ route('guides.browse') }}" wire:navigate class="text-[13px] font-bold text-ink-muted hover:text-ink hover:underline">Show all</a>
        </div>

        <div wire:loading.class="opacity-60" wire:target="loadMore">
            @forelse ($feed['items'] as $item)
                @if ($item['type'] === 'guide')
                    <x-feed.guide-item :item="$item" :class-colors="$classColors" wire:key="feed-g-{{ $item['guide']->id }}"/>
                @else
                    <x-feed.data-update :item="$item" wire:key="feed-d-{{ $item['update']->id }}"/>
                @endif
            @empty
                <div class="py-10 text-center">
                    <p class="text-[14px] text-ink-muted">No guides yet.</p>
                    <div class="text-[13px] text-ink-subtle mt-1">
                        <x-guides.try-button class="text-gold hover:text-gold-light" label="Write the first one"/> <span>(you don't need an account)</span>
                    </div>
                </div>
            @endforelse

            @if ($feed['hasMore'] && $feedLimit < \App\Livewire\Landing::FEED_MAX)
                <div class="pt-4 text-center">
                    <button type="button" wire:click="loadMore" class="btn-ghost">Show more</button>
                </div>
            @endif
        </div>
    </section>
</div>
