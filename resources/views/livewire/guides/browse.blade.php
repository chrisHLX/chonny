@php $classColors = config('wow_classes.colors', []); @endphp

<div class="max-w-5xl mx-auto px-4 py-8">
    {{-- Heading and intro deliberately short (2026-09-16). The page opened with a three-line
         paragraph and a five-line sign-up pitch above the fold, so on a phone the guides
         themselves — the reason anyone is here — started below two screens of prose. --}}
    <div class="mb-5">
        <h1 class="font-display text-3xl text-ink">Player Guides</h1>
        <p class="text-[14px] text-ink-muted mt-1.5">
            Openers, kill setups and matchup plans, written by the players who run them.
        </p>
    </div>

    @guest
        {{-- One line and the action. The old version explained the whole product here; a visitor
             who has scrolled to a listing of guides already knows what a guide is. --}}
        <div class="linear-card border-line-gold p-4 mb-5 flex flex-wrap items-center justify-between gap-3">
            <p class="text-[13.5px] text-ink">
                <span class="text-gold font-semibold">Plan your own games.</span>
                Build an opener, line up cooldowns, keep it private or share it.
            </p>
            <div class="flex items-center gap-2 shrink-0">
                <x-guides.try-button class="btn-primary text-[13px]" label="Try the planner"/>
                <a href="{{ route('register') }}" class="btn-ghost text-[13px]">Create an account</a>
            </div>
        </div>
    @endguest

    {{-- Filters. The two class pickers were native <select>s listing 13 class names as text, which
         matched nothing else on the site — every other class picker here is icons in the class's
         own colour (see the comp picker in wow-comps.blade.php). Icons also survive a phone far
         better: thirteen 36px buttons wrap into three rows, where a dropdown hides every option
         until tapped and gives no sense of what is available. --}}
    <div class="linear-card p-4 mb-6 space-y-4">
        <div class="flex flex-col sm:flex-row gap-3">
            <input type="text" wire:model.live.debounce.400ms="search"
                   placeholder="Search titles&hellip;"
                   class="form-input text-[13.5px] flex-1">

            <div class="flex rounded-md border border-line-strong overflow-hidden shrink-0">
                @foreach (['popular' => 'Most liked', 'new' => 'Newest'] as $value => $label)
                    <button type="button" wire:click="$set('sort', '{{ $value }}')"
                            class="px-3 py-2 text-[12px] transition-colors
                                   {{ $sort === $value ? 'bg-gold-subtle text-gold-light font-semibold' : 'text-ink-muted hover:text-ink' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <x-guides.class-filter
            label="Playing"
            :classes="$this->classes"
            :selected="$classSlug"
            property="classSlug"/>

        {{-- The matchup half of the search: "how do I beat RMP" is what someone is actually
             looking for, and a guide's enemy team is a separate field from its own comp. --}}
        <x-guides.class-filter
            label="Against"
            :classes="$this->classes"
            :selected="$opponentClassSlug"
            property="opponentClassSlug"/>
    </div>

    <div wire:loading.class="opacity-60" wire:target="search,classSlug,opponentClassSlug,sort">
        @forelse ($this->guides as $guide)
            <x-guides.card :guide="$guide" :class-colors="$classColors"/>
        @empty
            <div class="linear-card p-10 text-center">
                <p class="text-[13.5px] text-ink-muted">
                    @if ($search !== '' || $classSlug !== '' || $opponentClassSlug !== '')
                        Nothing matches that yet.
                    @else
                        No public guides yet. @auth<a href="{{ route('guides.index') }}" wire:navigate class="text-gold hover:text-gold-light">Write the first one.</a>@endauth
                    @endif
                </p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $this->guides->links() }}</div>
</div>
