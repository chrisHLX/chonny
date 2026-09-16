@php
    $classColors = config('wow_classes.colors', []);
    $feed = $this->feed;
@endphp

{{-- The public front page. See App\Livewire\Landing for why the comp builder is linked here rather
     than embedded, and GuideFeed for what a visitor's stream leaves out. --}}
<div class="max-w-6xl mx-auto px-4 py-6 sm:py-10">

    {{-- What the site is for, and the first thing to do ------------------------------------ --}}
    <section class="linear-card border-line-gold relative overflow-hidden p-5 sm:p-8 mb-10">
        <x-ornament.corner position="tr" class="absolute top-3 right-3 w-10 h-10 text-gold/20"/>

        <p class="text-[11px] uppercase tracking-[0.14em] text-gold mb-2">World of Warcraft arena</p>
        <h1 class="font-display text-[28px] sm:text-[38px] text-ink leading-tight max-w-3xl">
            Plan your arena games, and see what other players are planning
        </h1>
        <p class="text-[14px] text-ink-muted mt-3 max-w-2xl">
            Build a comp, then write the plan: the opener, the go, and the defensives you need them to
            spend. Cooldowns and diminishing returns come from the game's own spell data.
        </p>

        <div class="flex flex-col sm:flex-row sm:items-center gap-2 mt-6">
            <a href="{{ route('wow-comps') }}" wire:navigate class="btn-primary justify-center">Build a 3v3 comp</a>
            <x-guides.try-button class="btn-secondary justify-center w-full sm:w-auto" label="Try the planner, no sign-up"/>
            <a href="{{ route('guides.browse') }}" wire:navigate class="text-[13px] text-ink-muted hover:text-gold sm:ml-2 text-center">Browse player guides &rarr;</a>
        </div>

        @if (count($this->presets))
            <div class="mt-6 pt-5 border-t border-line">
                <p class="text-[11px] uppercase tracking-[0.13em] text-ink-subtle mb-2.5">Or open a common comp</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->presets as $preset)
                        <a href="{{ route('wow-comps', ['preset' => $preset['key']]) }}" wire:navigate wire:key="preset-{{ $preset['key'] }}"
                           class="flex items-center gap-2 rounded-md border border-line-strong hover:border-gold/60 bg-surface-2 px-3 py-2 transition-colors group">
                            <span class="flex items-center gap-0.5">
                                @foreach ($preset['specs'] as $spec)
                                    <x-spec-icon :spec="$spec" size="w-6 h-6"/>
                                @endforeach
                            </span>
                            <span class="text-[13px] text-ink group-hover:text-gold">{{ $preset['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_260px]">

        {{-- The public feed --------------------------------------------------------------- --}}
        <section class="min-w-0">
            <div class="flex items-baseline justify-between gap-4 border-b border-line pb-2.5">
                <h2 class="text-[13px] font-medium text-ink">What players are planning</h2>
                <a href="{{ route('guides.browse') }}" wire:navigate class="text-[12px] text-ink-subtle hover:text-gold">All guides &rarr;</a>
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
                        <p class="text-[13.5px] text-ink-muted">No guides published yet.</p>
                        <div class="text-[12.5px] text-ink-subtle mt-1">
                            <x-guides.try-button class="text-gold hover:text-gold-light" label="Write the first one"/> &mdash; no account needed.
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
                    <span class="block text-[11.5px] text-ink-subtle">The longest real control chains on file.</span>
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
