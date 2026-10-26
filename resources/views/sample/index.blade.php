{{-- /wow/sample (SampleGameController): the pages "Your games" shows, from real games with the names
     hidden, for anyone to click through before installing anything. --}}
<x-app-layout title="A real arena game, reviewed | MindCollector"
              description="Click through a real WoW arena game as MindCollector reads it: each death, a timeline you can play with every player's cooldowns, a Solo Shuffle round by round, and where the player stands against their spec. Names hidden."
              og-image="/images/app/card.webp">
<style>
    .sample-tab { padding: 6px 14px; border-radius: 9999px; font-size: 13px; font-weight: 600; color: #FFFFFF; background: rgba(255,255,255,.07); }
    .sample-tab:hover { background: rgba(255,255,255,.12); }
    .sample-tab.on { color: #000000; background: #FFFFFF; }
    .sample-frame { width: 100%; height: calc(100vh - 260px); min-height: 560px; border: 0; border-radius: 8px; background: #121212; }
    /* Phones: a 560px floor on a ~670px screen left almost nothing above the frame for the
       tabs that switch it. Same proportions as the frame on /wow/coach. */
    @media (max-width: 640px) {
        .sample-tab { padding: 8px 14px; }
        .sample-frame { height: 72vh; min-height: 440px; }
    }
</style>

<div class="py-4 px-4 lg:px-6">
    {{-- ?tab=watch opens on that tab (the app page and posts link to the timeline). --}}
    <div class="mx-auto space-y-4" style="max-width: 1400px" x-data="{ tab: @js(in_array(request('tab'), array_keys($pages), true) ? request('tab') : 'game') }">
        <div class="page-hero !py-6 flex flex-col lg:flex-row lg:items-end gap-4">
            <div class="flex-1 min-w-0">
                <p class="page-eyebrow">Post arena match &middot; player names hidden</p>
                @foreach ($pages as $key => [$file, $label, $what, $heading])
                    <h1 class="page-title" x-show="tab === '{{ $key }}'" x-cloak>{{ $heading }}</h1>
                @endforeach
                @foreach ($pages as $key => [$file, $label, $what])
                    <p class="text-[14px] text-ink-muted mt-3 max-w-3xl" x-show="tab === '{{ $key }}'" x-cloak>{{ $what }}</p>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
                <a href="{{ route('app') }}" wire:navigate class="btn-primary">Get the app</a>
                @guest
                    <a href="{{ route('login') }}" class="btn-ghost">Sign in to see yours</a>
                @else
                    <a href="{{ route('coach') }}" wire:navigate class="btn-ghost">Your games</a>
                @endguest
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($pages as $key => [$file, $label])
                <button type="button" class="sample-tab" :class="tab === '{{ $key }}' && 'on'" @click="tab = '{{ $key }}'">{{ $label }}</button>
            @endforeach
        </div>

        <iframe class="sample-frame" :src="'/wow/sample/page/' + tab" title="A sample page from MindCollector"></iframe>

        <p class="text-[12.5px] text-ink-subtle">
            These are pages the MindCollector app drew from real games, with every player's name swapped for a label.
            Yours show your own games, and only you can see them.
        </p>
    </div>
</div>
</x-app-layout>
