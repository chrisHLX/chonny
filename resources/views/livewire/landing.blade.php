@php
    $classColors = config('wow_classes.colors', []);
    $feed = $this->feed;
    $pool = $this->pool;
    $download = $this->appAvailable ? route('coach.app') : route('app');
@endphp

{{-- The public front page (App\Livewire\Landing). Since 2026-10-12 it leads with the desktop app: a
     download, a replica of the app with an example game, the steps; the planning tools follow. --}}
<div class="max-w-6xl mx-auto px-4 py-6 sm:py-10">

    {{-- The app ------------------------------------------------------------------------------- --}}
    <section class="page-hero -mx-2 sm:mx-0">
        <p class="page-eyebrow">MindCollector for Windows &middot; free</p>
        <h1 class="page-title max-w-4xl">Every arena game you play, reviewed.</h1>
        <p class="text-[16px] text-ink-muted mt-4 max-w-2xl">
            The app runs in the background while you play and sends each game here when it ends. You get
            a card for every game: who died, what killed them, what you still had, and what to work on next.
        </p>
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 mt-7">
            <a href="{{ $download }}" @if ($this->appAvailable) download @endif class="btn-primary !px-8 !py-3.5 !text-[15px]">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-5-5m5 5l5-5M5 20h14"/></svg>
                Download for Windows
            </a>
            <a href="{{ route('app') }}" class="btn-ghost">How it works</a>
        </div>
        <p class="text-[12.5px] text-ink-subtle mt-3">
            Windows 10 or 11. The first time, Windows may say it "protected your PC": click More info, then Run anyway.
        </p>
    </section>

    {{-- The app, slide by slide: real pages with the names swapped out. --}}
    <x-app-showcase class="mt-8"/>

    {{-- What it does --------------------------------------------------------------------------- --}}
    <section class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-10">
        <div class="linear-card p-5">
            <p class="text-[16px] font-bold text-ink">How each death happened</p>
            <p class="text-[13.5px] text-ink-muted mt-1.5">The killing blow, which abilities did the damage, whether the healer was locked out, and the defensives that were used or still ready.</p>
        </div>
        <div class="linear-card p-5">
            <p class="text-[16px] font-bold text-ink">One thing to work on</p>
            <p class="text-[13.5px] text-ink-muted mt-1.5">Each character gets a focus: the habit you're furthest behind other players of your spec on, checked again after your next session.</p>
        </div>
        <div class="linear-card p-5">
            <p class="text-[16px] font-bold text-ink">Compared with real players</p>
            <p class="text-[13.5px] text-ink-muted mt-1.5">
                @if ($pool['games'] > 0)
                    Your numbers sit next to {{ number_format($pool['games']) }} games and {{ number_format($pool['players']) }} players across {{ $pool['specs'] }} specs, and every game uploaded adds to them.
                @else
                    Your numbers sit next to every player of your spec we've measured, and every game uploaded adds to them.
                @endif
            </p>
        </div>
    </section>

    {{-- Three steps ---------------------------------------------------------------------------- --}}
    <section class="mt-10 linear-card p-6 sm:p-8">
        <h2 class="text-[22px] font-bold text-ink">Set up in a minute</h2>
        <ol class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-5">
            @foreach ([
                ['Download and open it', 'It installs itself and sits in the tray by the clock.'],
                ['Connect your account', 'It opens this site. Sign in or make an account, and click Connect.'],
                ['Play arena', 'Each game shows up about a minute after it ends. 2v2, 3v3 and Solo Shuffle.'],
            ] as $i => [$title, $text])
                <li class="flex gap-3">
                    <span class="w-8 h-8 rounded-full bg-gold text-black font-bold flex items-center justify-center shrink-0">{{ $i + 1 }}</span>
                    <div><p class="text-[15px] font-bold text-ink">{{ $title }}</p><p class="text-[13.5px] text-ink-muted mt-0.5">{{ $text }}</p></div>
                </li>
            @endforeach
        </ol>
        <a href="{{ $download }}" @if ($this->appAvailable) download @endif class="btn-primary mt-7">Download for Windows</a>
    </section>

    {{-- The planning tools -------------------------------------------------------------------- --}}
    <section class="mt-14">
        <h2 class="text-[22px] font-bold text-ink">Plan your games</h2>
        <p class="text-[14px] text-ink-muted mt-1">Spell, talent and cooldown data from the live game, for when you're not playing.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
            @foreach ([
                [route('wow-comps'), 'Build a 3v3 comp', 'Pick three specs and see their cooldowns, crowd control and defensives side by side.'],
                [route('matchup-lab'), 'Matchup Lab', 'Set two comps against each other and see when each side has its answers.'],
                [route('pvp-guides'), 'Class guides', 'Every spec\'s kit, burst windows and counters.'],
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
