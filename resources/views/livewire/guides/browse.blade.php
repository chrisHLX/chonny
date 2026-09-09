@php $classColors = config('wow_classes.colors', []); @endphp

<div class="max-w-5xl mx-auto px-4 py-8">
    <div class="mb-6">
        <p class="text-[11px] uppercase tracking-[0.13em] text-gold mb-1">Player guides</p>
        <h1 class="font-display text-3xl text-ink">Written by players</h1>
        <p class="text-[13.5px] text-ink-muted mt-1 max-w-2xl">
            Comps, openers and matchups, written by the people who play them. Distinct from
            the guides derived from real match data elsewhere on this site.
        </p>
    </div>

    <div class="linear-card p-4 mb-6">
        {{-- Stacks on mobile: four controls forced onto one row squeeze each other into
             unreadable slivers on a phone. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1fr_auto_auto_auto] gap-3">
            <input type="text" wire:model.live.debounce.400ms="search"
                   placeholder="Search titles&hellip;"
                   class="form-input text-[13.5px]">

            <select wire:model.live="classSlug" class="form-select text-[13px]">
                <option value="">Playing any class</option>
                @foreach ($this->classes as $class)
                    <option value="{{ $class->slug }}">Playing {{ $class->name }}</option>
                @endforeach
            </select>

            {{-- The matchup half of the search. A guide's enemy team is what someone looking for
                 "how do I beat RMP" is actually searching for, and until now it was neither shown
                 on the card nor reachable by any filter. --}}
            <select wire:model.live="opponentClassSlug" class="form-select text-[13px]">
                <option value="">Against anyone</option>
                @foreach ($this->classes as $class)
                    <option value="{{ $class->slug }}">Against {{ $class->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="sort" class="form-select text-[13px]">
                <option value="popular">Most liked</option>
                <option value="new">Newest</option>
            </select>
        </div>
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
