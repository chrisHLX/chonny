@php
    $classColors = config('wow_classes.colors', []);
    $feed = $this->feed;
@endphp

{{-- The public front page. See App\Livewire\Landing for why the comp builder is linked here rather
     than embedded, and GuideFeed for what a visitor's stream leaves out. --}}
<div class="max-w-6xl mx-auto px-4 py-6 sm:py-10">

    {{-- What the site is for, and the first thing to do ------------------------------------ --}}
    <section class="page-hero mb-8 -mx-2 sm:mx-0">
        <p class="page-eyebrow">WoW arena</p>
        <h1 class="page-title max-w-3xl">Plan your next arena game with your team.</h1>
        <p class="text-[15px] text-ink-muted mt-4 max-w-2xl">
            Pick your three specs, then write down the opener, the go and the defensives you want to
            force. The spell and talent data comes from the live game.
        </p>

        <div class="flex flex-col sm:flex-row sm:items-center gap-3 mt-7">
            <a href="{{ route('wow-comps') }}" wire:navigate class="btn-primary">Build a 3v3 comp</a>
            <x-guides.try-button class="btn-secondary w-full sm:w-auto" label="Try the planner without an account"/>
            <a href="{{ route('guides.browse') }}" wire:navigate class="btn-ghost">Browse guides</a>
        </div>
    </section>

    @if (count($this->presets))
        <section class="mb-10">
            <h2 class="text-[20px] font-bold text-ink mb-3">Start from a common comp</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                @foreach ($this->presets as $preset)
                    <a href="{{ route('wow-comps', ['preset' => $preset['key']]) }}" wire:navigate wire:key="preset-{{ $preset['key'] }}"
                       class="linear-card group flex items-center gap-3 p-3">
                        <span class="flex items-center -space-x-1.5 shrink-0">
                            @foreach ($preset['specs'] as $spec)
                                <x-spec-icon :spec="$spec" size="w-11 h-11"/>
                            @endforeach
                        </span>
                        <span class="text-[15px] font-bold text-ink">{{ $preset['label'] }}</span>
                        <span class="ml-auto w-9 h-9 rounded-full bg-gold text-black flex items-center justify-center opacity-0 translate-y-1 group-hover:opacity-100 group-hover:translate-y-0 transition shrink-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_260px]">

        {{-- The public feed --------------------------------------------------------------- --}}
        <section class="min-w-0">
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

        {{-- The rest of the site, small ----------------------------------------------------- --}}
        <aside class="space-y-7 min-w-0">
            <x-quizzes.leaderboard :rows="$this->quizLeaderboard"/>

            <div>
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-2">Class data</h2>
                <a href="{{ route('pvp-guides') }}" wire:navigate class="block py-1.5 group">
                    <span class="text-[13px] text-ink-muted group-hover:text-gold">Class guides</span>
                    <span class="block text-[11.5px] text-ink-subtle">Every spec's kit, burst and counters.</span>
                </a>
                <a href="{{ route('top-damage-rotations') }}" wire:navigate class="block py-1.5 group">
                    <span class="text-[13px] text-ink-muted group-hover:text-gold">Top burst windows</span>
                    <span class="block text-[11.5px] text-ink-subtle">The hardest-hitting goes from rated matches.</span>
                </a>
                <a href="{{ route('top-cc-chains') }}" wire:navigate class="block py-1.5 group">
                    <span class="text-[13px] text-ink-muted group-hover:text-gold">Top 10 CC chains</span>
                    <span class="block text-[11.5px] text-ink-subtle">The longest crowd control chains we've recorded.</span>
                </a>
            </div>

            <div class="border-t border-line pt-5">
                <h2 class="text-[11px] uppercase tracking-[0.13em] text-ink font-semibold mb-2">Your own plans</h2>
                <p class="text-[12.5px] text-ink-muted">
                    Save guides for your comps, share them with friends or your guild, and sign them with
                    your character.
                </p>
                <div class="flex items-center gap-3 mt-3 text-[12.5px]">
                    <a href="{{ route('register') }}" class="text-gold hover:text-gold-light">Create an account</a>
                    <a href="{{ route('login') }}" class="text-ink-subtle hover:text-ink">Log in</a>
                </div>
            </div>
        </aside>
    </div>
</div>
